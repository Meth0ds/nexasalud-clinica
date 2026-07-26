# Infraestructura local de NexaSalud

Entorno reproducible de **desarrollo** para Laravel 13: PHP-FPM 8.4 detrás de Nginx no privilegiado, PostgreSQL 17, Redis 8, Keycloak 26.6.3 y Mailpit. No es una receta de producción: usa HTTP local, publica puertos solo en `127.0.0.1` y contiene identidades totalmente sintéticas.

## Inicio rápido

Requisitos: Docker con Compose v2, OpenSSL y `curl`.

```bash
cd infra
./scripts/init.sh --start
```

`init.sh` genera `infra/.env` con secretos aleatorios locales, permisos `0600`, y renderiza el realm en `infra/data/`; ambos están ignorados por Git. La plantilla versionada no contiene el secreto del cliente OIDC. El arranque construye PHP, espera los healthchecks y ejecuta las migraciones Laravel mediante el usuario PostgreSQL limitado `nexasalud_app`.

Para validar un stack ya iniciado:

```bash
cd infra
./scripts/verify.sh
```

Servicios locales:

- API Laravel: `http://localhost:8000` (`/up` es el healthcheck de aplicación).
- Keycloak: `http://keycloak.localhost:8080` (también responde en `http://localhost:8080`).
- Mailpit: `http://localhost:8025`.
- PostgreSQL: `127.0.0.1:5432`.
- Redis: `127.0.0.1:6379`.

`keycloak.localhost` resuelve al loopback en el navegador y es, a la vez, un alias DNS dentro de las redes Docker. Esto mantiene idéntico el issuer OIDC dentro y fuera de los contenedores.

Laravel recibe `OIDC_ISSUER=http://keycloak.localhost:8080/realms/nexasalud-dev`, tiempos de conexión acotados, algoritmo `RS256`, TTL de transacción de cinco minutos y exige simultáneamente `acr=2` y `amr=otp`. Keycloak utiliza un flujo propio de contraseña + TOTP, publica `amr` mediante su mapper oficial y fija un ACR mínimo de 2 en el cliente; Laravel vuelve a comprobar ambas evidencias y falla cerrado. `OIDC_ALLOW_INSECURE_HTTP=true` existe exclusivamente para este loopback de desarrollo; la aplicación debe fallar cerrada si aparece en producción.

## Identidades sintéticas

Realm: `nexasalud-dev`. Tenant sintético: `01KXVSN5BZK269J2HZCD53EVZ0`.

| DNI sintético (no válido) | Roles |
| --- | --- |
| `00000000T-DEMO` | `clinician`, `tenant_admin` |
| `11111111H-DEMO` | `receptionist` |
| `22222222J-DEMO` | `auditor` |
| `33333333P-DEMO` | `patient` |

Todos los identificadores incluyen el sufijo `-DEMO`: no son DNI válidos ni deben sustituirse por identidades reales. Cada perfil recibe una contraseña inicial aleatoria distinta en las variables `KEYCLOAK_DEMO_*_PASSWORD` de `infra/.env`; no están versionadas ni se imprimen durante la inicialización. Para consultar la del perfil que vayas a probar, léela directamente desde ese archivo protegido. Son temporales: Keycloak exige cambiarlas y configurar TOTP en el primer acceso. Los correos usan el dominio reservado `.test` y Mailpit nunca entrega mensajes a Internet.

El tema `nexasalud` etiqueta el identificador como **DNI**, explica la verificación en dos pasos y conserva estados de foco, contraste y reducción de movimiento. El realm desactiva el acceso por correo, remember-me, autorregistro, implicit flow, direct grants y scopes opcionales; aplica protección por fuerza bruta, rotación estricta de refresh tokens, contraseña robusta y TOTP SHA-256 no reutilizable. Los redirects de login y logout y el origen web son exactos, sin comodines.

Keycloak distingue correctamente **registrar** TOTP de **autenticarse** con TOTP. En el primer acceso, después de cambiar la contraseña y registrar la aplicación, el token contiene `acr=2` y `amr=[pwd]`; Laravel exige también `otp`, rechaza ese callback y obliga a iniciar sesión de nuevo. El segundo acceso ya solicita el código temporal y emite `amr=[pwd,otp]`. Este paso adicional es deliberado: configurar una credencial no se acepta como prueba de haberla usado.

El cliente `nexasalud-bff` es confidencial, usa Authorization Code, fuerza PKCE `S256`, desactiva implicit/direct grants y acepta únicamente `http://localhost:8000/auth/callback` como redirect de inicio de sesión. El secreto se genera localmente.

El dataset Laravel se instala de forma explícita, únicamente en `local` o `testing`, y es idempotente:

```bash
cd infra
docker compose run --rm --no-deps api php artisan db:seed --class=ClinicalDemoSeeder --no-interaction
```

El seeder alinea issuer y subjects con estas cuatro identidades, crea membresías y roles internos separados (administración integral, profesional, recepción, auditoría y paciente), clínica, sala, catálogo y datos clínicos totalmente sintéticos. También crea un único vínculo activo `relationship=self` entre el usuario del portal y su paciente demo; volver a ejecutar el seeder no duplica ese vínculo ni el dataset. `DatabaseSeeder` no introduce cuentas demo de manera implícita salvo que `SEED_CLINICAL_DEMO=true` se configure deliberadamente en un entorno local.

## Operación

```bash
cd infra
docker compose logs -f api gateway keycloak
docker compose stop
docker compose down
```

Los datos persisten en volúmenes con nombre. No uses `docker compose down -v` para actualizar el realm: eliminaría también la base clínica. Keycloak omite la importación de arranque cuando el realm ya existe, por diseño.

### Aplicar una nueva plantilla a un realm local existente

No se ha reemplazado automáticamente el realm activo. Cuando decidas hacerlo, usa este procedimiento exclusivamente en desarrollo; elimina las sesiones, credenciales TOTP y cambios manuales del realm `nexasalud-dev`:

1. Renderiza y valida la plantilla sin reiniciar servicios con `./scripts/init.sh`.
2. Detén solo Keycloak: `docker compose stop keycloak`.
3. Crea un backup completo offline, con usuarios, antes de cualquier borrado:

   ```bash
   mkdir -p backups/keycloak
   chmod 700 backups/keycloak
   docker compose run --rm --no-deps \
     -v "$PWD/backups/keycloak:/backup" \
     keycloak export \
     --file /backup/nexasalud-dev-before-mfa.json \
     --realm nexasalud-dev \
     --users realm_file
   ```

4. Arranca Keycloak, entra en la consola de administración, comprueba que el selector muestra exactamente `nexasalud-dev` y elimina únicamente ese realm. No elimines `master`, bases ni volúmenes.
5. Reinicia solo Keycloak con `docker compose restart keycloak`. Al no existir ya el realm, `--import-realm` cargará el JSON renderizado y el tema montado.
6. Ejecuta `./scripts/verify.sh`, realiza un primer acceso completo y confirma en el ID token `acr=2` y que `amr` contiene `otp` antes de considerar válido el entorno.

El export/import offline es el mecanismo de backup transferible recomendado por Keycloak; una exportación parcial desde la consola no incluye usuarios ni secretos y no sirve como copia restaurable.

La rotación de claves de `.env` con volúmenes existentes requiere reprovisionar las credenciales PostgreSQL antes de reiniciar dependientes. Para un entorno local desechable, la ruta segura es crear un stack nuevo. No copies estos secretos ni la contraseña demo a staging o producción; allí deben inyectarse desde un gestor de secretos, TLS debe ser obligatorio y Mailpit no debe existir.

## Controles incorporados

- El contenedor Laravel copia archivos de forma selectiva, por lo que `apps/api/.env` no entra en la imagen.
- PHP oculta errores y argumentos de excepciones, limita cargas y ejecuta FPM como UID/GID `10001`.
- Nginx es no-root, tiene filesystem de solo lectura, límite de cuerpo y cabeceras defensivas.
- Laravel recibe solo su contraseña PostgreSQL; no recibe la credencial de superusuario ni la de Keycloak DB.
- PostgreSQL crea usuarios distintos y no-superusuario para Laravel y Keycloak, con autenticación SCRAM.
- Redis exige autenticación y AOF; PostgreSQL y Redis usan volúmenes persistentes.
- Las redes `backend` y `mail` son internas. Una red `tooling` separada habilita PostgreSQL y Redis para herramientas del host, pero sus publicaciones quedan ligadas exclusivamente a `127.0.0.1`; ningún puerto escucha en interfaces públicas.
- Las sesiones Laravel usan Redis, cifrado, cookie HttpOnly, SameSite Lax y expiración al cerrar. `Secure` queda desactivado exclusivamente por el HTTP local.
- Los secretos reales no tienen valores por defecto en Compose; sin `infra/.env`, la configuración falla de forma explícita.
