# Acceso al entorno de demostración

Este entorno solo contiene identidades y datos clínicos sintéticos para presentación. No contiene datos sanitarios reales.

## Arranque

Desde la raíz:

    cd .\infra
    .\scripts\init.sh --start
    docker compose run --rm --no-deps api php artisan db:seed --class=ClinicalDemoSeeder --no-interaction

Si estás en PowerShell y no tienes Bash/WSL, usa este equivalente:

    cd .\infra
    docker compose up -d --build
    docker compose run --rm --no-deps api php artisan db:seed --class=ClinicalDemoSeeder --no-interaction

La aplicación Angular está en http://localhost:4200/login. Si no está levantada:

    cd .\apps\web
    pnpm dev

En PowerShell también puedes arrancarla desde la raíz del monorepo:

    pnpm --filter @nexasalud/web dev

El comando queda ejecutándose en esa terminal; deja la ventana abierta mientras haces la presentación.

Servicios: API http://localhost:8000, Keycloak http://keycloak.localhost:8080 y Mailpit http://localhost:8025

## Usuarios sintéticos

Todos pertenecen al realm `nexasalud-dev`, usan Authorization Code + PKCE y MFA TOTP. Escribe el identificador en el campo DNI:

| Identificador | Perfil |
|---|---|
| `00000000T-DEMO` | Profesional + administración |
| `11111111H-DEMO` | Recepción |
| `22222222J-DEMO` | Auditoría |
| `33333333P-DEMO` | Paciente |

Las contraseñas son aleatorias, locales y no versionadas. Para verlas en PowerShell:

    cd .\infra
    Get-Content .\.env | Select-String 'KEYCLOAK_DEMO_(CLINICIAN|RECEPTIONIST|AUDITOR|PATIENT)_PASSWORD'

Correspondencia: CLINICIAN → 00000000T-DEMO; RECEPTIONIST → 11111111H-DEMO; AUDITOR → 22222222J-DEMO; PATIENT → 33333333P-DEMO.

El primer acceso obliga a cambiar la contraseña y registrar TOTP. Después inicia sesión de nuevo para que el token contenga `amr=[pwd,otp]`, requisito del API.

## Comprobaciones

    cd .\infra
    docker compose ps
    docker compose logs --tail=100 api gateway keycloak worker
    Invoke-WebRequest http://localhost:8000/ready

El seeder es idempotente. No uses `docker compose down -v` salvo que quieras borrar las bases locales. `DEMO_PORTAL_SIMULATIONS=true` habilita las funciones de demostración no fiscales y sus respuestas incluyen `simulated=true`.

Este acceso local no sustituye OIDC gestionado, KMS, TLS/WAF, backups inmutables, restore probado, DAST/pentest ni validación legal sanitaria.
