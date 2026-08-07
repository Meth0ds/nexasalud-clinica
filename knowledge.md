# NexaSalud — knowledge del repositorio

Plataforma segura de gestión de citas médicas (nombre de trabajo: **NexaSalud Agenda**). Monorepo pnpm con **API/BFF Laravel** + **frontend Angular** + **stack Docker local**. Protege datos de pacientes con OIDC BFF, cifrado, auditoría append-only e idempotencia. **No está apta para producción** — ver `PLAN_MAESTRO.md` e `INFORME_PREPRODUCCION.md` (backlog autoritativo).

## Dónde vive el código

| Ruta | Contenido |
|---|---|
| `apps/api/` | Laravel 13 / PHP 8.4. BFF con sesión OIDC, autorización por tenant fail-closed, campos cifrados, eventos post-commit, errores RFC 9457 + `X-Trace-Id`. Rutas en `routes/api.php`, identidad en `app/Services/Identity/`, dominios en `app/Services/Scheduling|Clinical|...` |
| `apps/web/` | Angular 21 + Angular Material 21 (standalone, signals). Rutas en `src/app/app.routes.ts`, sesión/permisos en `src/app/core/`, features en `src/app/features/` |
| `infra/` | `compose.yaml` (dev) y `production/` (prod). Postgres, Redis, Keycloak, Mailpit, API, gateway, worker |
| `docs/`, `security/`, `tasks/` | Convenciones backend (`apps/api/docs/BACKEND_CONVENTIONS.md`), threat model, plan de pentest |

Servicios dev: API `http://localhost:8000` · Keycloak `http://keycloak.localhost:8080` · Mailpit `http://localhost:8025` · Web `http://localhost:4200` (proxy `/api`, `/auth`, `/up` → `127.0.0.1:8000`). Healthcheck: `GET /ready`.

## Comandos

Requisitos: node `>=24 <25`, pnpm `>=11 <12` (pnpm@11.9.0).

```bash
# Arrancar todo (infra + web) / solo infra / solo API
pnpm dev                 # infra:up + dev:web
pnpm dev:api             # docker compose up -d api gateway
pnpm infra:up|down|logs  # gestionar stack

# Tests y calidad (desde raíz)
pnpm typecheck           # tsc en los workspaces
pnpm test / test:ci      # suites de todos los workspaces
pnpm test:api            # php artisan test dentro del contenedor api
pnpm lint:api            # pint --test dentro del contenedor
pnpm format / format:check   # prettier (raíz)

# Dentro del contenedor api (PHP NO está instalado en el host Windows)
docker compose --env-file infra/.env -f infra/compose.yaml exec -T api php artisan test --compact
docker compose --env-file infra/.env -f infra/compose.yaml exec -T api vendor/bin/pint --format agent

# Web individual
cd apps/web && pnpm dev            # http://localhost:4200 (proxy al API)
pnpm --filter @nexasalud/web typecheck   # tsc --noEmit
pnpm --filter @nexasalud/web test        # vitest
```

## Entorno de demo (ACCESO_PRUEBAS.md)

Realm `nexasalud-dev`, Authorization Code + PKCE, **MFA TOTP obligatorio**.

```bash
cd infra && ./scripts/init.sh --start
docker compose run --rm --no-deps api php artisan db:seed --class=ClinicalDemoSeeder --no-interaction
cd apps/web && pnpm dev
```

| DNI (campo de login) | Perfil |
|---|---|
| `00000000T-DEMO` | Profesional + administración |
| `11111111H-DEMO` | Recepción |
| `22222222J-DEMO` | Auditoría |
| `33333333P-DEMO` | Paciente |

Contraseñas locales en `infra/.env` (`KEYCLOAK_DEMO_*_PASSWORD`). El primer login obliga a cambiar contraseña y registrar TOTP; **hay que volver a iniciar sesión** para que el token lleve `amr=[pwd,otp]` (lo exige el API). `DEMO_PORTAL_SIMULATIONS=true` habilita funciones de demostración con respuesta `simulated=true`.

## Convenciones y gotchas

- **PHP solo en Docker.** Nunca `php artisan`/`composer` en el host; ejecutar con `docker compose exec -T api ...`.
- **No usar `docker compose down -v`** (borra las BBDD locales). El seeder `ClinicalDemoSeeder` es idempotente.
- **Keycloak 25+ regenera los UUIDs** de usuarios al importar el realm → el seeder resuelve los subjects reales vía admin API solo en entorno `local`.
- **Frontend** (`apps/web/AGENTS.md`): componentes standalone (no escribir `standalone: true`), signals + `computed()` (nunca `mutate`), `input()`/`output()` (no decorators), `OnPush`, control de flujo nativo `@if/@for/@switch` (nada de `*ngIf`), sin `ngClass`/`ngStyle`, rutas lazy, `inject()` (no DI por constructor), `NgOptimizedImage`, AXE + WCAG AA.
- **Backend** (`apps/api/AGENTS.md`, `docs/BACKEND_CONVENTIONS.md`): PHPUnit (nunca Pest), `pint --dirty` antes de cerrar, tipos explícitos y propiedad promocionada en constructores, Eloquent API Resources versionados (`/api/v1/...`), rutas con nombre, auditoría **sin contenido sensible** en metadatos, `composer audit` en CI.
- **Errores**: contracto RFC 9457 en el API; páginas de estado en web: `/sin-acceso` (403) e `/indisponible`.
- **MCP disponibles**: `laravel-boost` (`.codex/config.toml`, requiere stack Docker arriba) y `chrome-devtools` (`.agents/mcp.json`, requiere Chrome escuchando en `127.0.0.1:9222`; lánzalo con `--remote-debugging-port=9222` y perfil propio).
- Estado de tests (ago 2026): API 104 tests / 541 aserciones; web 30 archivos / 66 tests. La suite OpenAPI (Scramble) cubre las rutas nuevas.
