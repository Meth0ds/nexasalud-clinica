# Plan: Cerrar brechas operativas y de despliegue contrastadas con el código

**Fecha de análisis**: 2026-08-05  
**Origen**: Análisis cruzado apps/api ↔ apps/web + auditoría de wiring (rutas, controladores, FormRequests, eventos, políticas, jobs, migraciones).  
**Resultado de la auditoría**: **0 rutas, 0 endpoints, 0 FormRequests, 0 eventos, 0 políticas, 0 servicios y 0 métodos de controlador referenciados faltan en el código**. La aplicación es funcionalmente completa. Lo que sigue son únicamente las brechas reales detectadas (operativas, de despliegue y de limpieza).

---

### 1. Documentar variables críticas de producción en `apps/api/.env.example`

**Problema contrastado**:  
`apps/api/.env.example` solo contiene la plantilla por defecto de Laravel (65 líneas), pero la aplicación consume ~40 claves propias:

- `config/services.php:39-72`: `OIDC_ISSUER`, `OIDC_CLIENT_ID`, `OIDC_CLIENT_SECRET`, `OIDC_REDIRECT_URI`, `OIDC_FRONTEND_URL`, `OIDC_POST_LOGOUT_REDIRECT_URI`, `OIDC_UPSTREAM_LOGOUT`, `OIDC_SCOPES`, `OIDC_ALLOWED_ALGORITHMS`, `OIDC_REQUIRED_ACR_VALUES`, `OIDC_REQUIRED_AMR_VALUES`, `OIDC_TRANSACTION_TTL_SECONDS`, `OIDC_DISCOVERY_CACHE_SECONDS`, `OIDC_JWKS_CACHE_SECONDS`, `OIDC_CONNECT_TIMEOUT_SECONDS`, `OIDC_TIMEOUT_SECONDS`, `OIDC_CLOCK_SKEW_SECONDS`, `KEYCLOAK_BASE_URL`, `KEYCLOAK_REALM`, `KEYCLOAK_ADMIN_USERNAME`, `KEYCLOAK_ADMIN_PASSWORD`.
- `config/waitlist.php`: `WAITLIST_MATCHING_*`.
- `config/documents.php`: `CLINICAL_PDF_*`.
- `config/filesystems.php`: `CLINICAL_FILESYSTEM_DRIVER`, `CLINICAL_FILESYSTEM_ROOT`.
- `config/cors.php:5`: `CORS_ALLOWED_ORIGINS`.
- `config/session.php`: `SESSION_ABSOLUTE_LIFETIME`, `SESSION_SAME_SITE`, `SESSION_SECURE_COOKIE`.
- `bootstrap/app.php:38`: `TRUSTED_PROXIES`.
- `config/demo.php:17`: `DEMO_PORTAL_SIMULATIONS`.

**Impacto**: Sin estas claves documentadas, en producción `AppServiceProvider::assertProductionIdentityConfiguration()` (líneas 51-136) **fallará el arranque cerrado** por configuración ausente.

**Acción**:  
Sobrescribir `apps/api/.env.example` añadiendo todas las claves anteriores con valores de ejemplo seguros y comentarios explicativos. No tocar `.env`.

**Validación**: `php artisan config:show services.keycloak` / revisar arranque en entorno de producción simulado.

---

### 2. Configuración de entorno del frontend (apps/web)

**Problema contrastado**:  
No existe `apps/web/src/environments/` ni ninguna constante centralizable de `apiUrl`. Todo el HTTP cliente usa rutas relativas same-origin depuradas mediante `apps/web/proxy.conf.json` (→ `http://127.0.0.1:8000`).  
*Nota*: no es un error de wiring (0 llamadas rotas), pero impide desplegar el build en orígenes separados sin tocar código.

**Acción**:  
Decidir y aplicar *una* de estas dos opciones (sin mezclar):
- Crear `apps/web/src/environments/environment.ts` + `fileReplacements` en `angular.json`, y refactorizar los ~50 literales `/api/...` a `environment.apiUrl + ...`.
- O documentar en README/docker que la SPA se sirve siempre desde el mismo origen que la API y mantener las rutas relativas.

**Validación**: `pnpm --filter web build` y comprobación de que el origen final es el esperado.

---

### 3. Limpieza de residuos físicos del repo

**Problema contrastado**:  
Directorios vacíos nacidos de errores de terminal en Windows que nunca se eliminaron:
- `apps/api;C` (existe en disco).
- `apps/api/apps/api/` (árbol vacío, confirmado con glob).

**Acción**:  
Eliminar con `Remove-Item -LiteralPath "apps/api;C" -Force` y `Remove-Item -LiteralPath "apps/api\apps" -Recurse -Force`. Confirmar con glob que desaparecen.

**Validación**: `git status --short` sin residuos; `glob apps/api/apps/**/*` vacío.

---

### 4. Alinear validación de consentimientos con la convención del proyecto

**Problema contrastado**:  
`ConsentController::store` y `::withdraw` (`apps/api/app/Http/Controllers/Api/V1/ConsentController.php`) usan `$request->validate([...])` inline, incumpliendo la regla del proyecto `validation_via_formrequests` (memoria guardada: *“La validación en el backend se hace vía FormRequests (~28 existentes), no $request->validate() inline en controladores”*).

**Acción**:  
Crear `StoreConsentRequest` y `WithdrawConsentRequest` en `app/Http/Requests/Api/V1/`, extender de `TenantFormRequest`, mover las reglas inline tipadas y sustituir en el controlador.

**Validación**: `php artisan test --compact --filter=Consent` y ejecutar Pint (`vendor/bin/pint --dirty --format agent`).

---

### 5. Eliminar método muerto en el frontend

**Problema contrastado**:  
`PatientsService.get(id)` (`apps/web/src/app/core/services/patients.ts:19-23`) existe pero ningún componente o servicio lo llama (búsqueda de referencias exhaustiva en memoria). Es dead-code puro.

**Acción**:  
Eliminar el método `get(id)` o justificar su permanencia con un comentario `@internal` si se reserva para funcionalidad futura.

**Validación**: `pnpm --filter web test` / chequeo de tipos (`pnpm --filter web typecheck`).

---

### 6. Evaluación opcional: i18n

**Problema contrastado**:  
No hay sistema i18n (todas las cadenas están hardcodeadas en español, solo `LOCALE_ID='es-ES'` en `apps/web/src/app/app.config.ts:34`). No es un hueco de wiring porque no se usa ninguna librería de traducción, pero impide crecer a otros idiomas.

**Acción (marcada como OPCIONAL/fuera de alcance si el producto es monolingüe)**:  
Si el roadmap incluye multi-idioma, introducir `@angular/localize` o Transloco y extraer literales. En caso contrario, documentar explícitamente *“frontend monolingüe es-ES”* en el README de `apps/web`.

---

### Resumen final
- **No hay código de negocio sin implementar** (cada ruta del frontend y del backend tiene su contraparte real).
- Todas las acciones propuestas son operativas, de higiene de repositorio o de alineación de convenciones internas.
- Prioridad: (1) `.env.example` y (3) residuos, porque afectan directamente al despliegue y la limpieza; el resto son mejoras de consistencia.
