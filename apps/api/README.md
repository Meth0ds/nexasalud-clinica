# NexaSalud API

Laravel API and BFF for the NexaSalud clinical platform. It is tenant-scoped and is designed to protect patient information through server-side authorization, encrypted fields, append-only audit records, idempotent mutations and an OIDC BFF session.

## Local development

The supported local stack is defined at the repository root and includes PostgreSQL, Redis, Keycloak, Mailpit, the API gateway and Angular web application.

```powershell
docker compose --env-file infra/.env -f infra/compose.yaml up -d --build
```

The local compose file is for development only. It must not be exposed to the Internet or promoted as a production deployment.

## Verification

Run commands inside the API container so that PHP extensions and versions match the application image.

```powershell
docker compose --env-file infra/.env -f infra/compose.yaml exec -T api php artisan test
docker compose --env-file infra/.env -f infra/compose.yaml exec -T api vendor/bin/pint --test
docker compose --env-file infra/.env -f infra/compose.yaml exec -T api composer audit --locked --no-dev
```

## Architecture and safety boundaries

- OIDC authentication uses PKCE, `state`, nonce validation, token validation and a server-side encrypted session.
- API resources resolve a fail-closed active tenant and enforce policies server-side.
- Sensitive mutations use optimistic versions and idempotency keys where applicable.
- Patient and clinical fields are encrypted; sensitive reads and writes are audited without storing content in audit metadata.
- Errors use RFC 9457 problem documents and an `X-Trace-Id` correlation value.
- `CommunicationQueued` is a post-commit event. Its queue listener uses retry, backoff, overlap protection and safe operational logging; it never carries message content or PHI.

Read [backend conventions](docs/BACKEND_CONVENTIONS.md) before adding controllers, services, events, jobs or listeners.

## Patient portal messages

`GET /api/v1/portal/messages` returns only queued `portal` communications addressed to the authenticated user's verified patient link. The response is limited to 100 records, includes the encrypted subject and body only after ownership is established, and records `portal.messages_viewed` without message content in audit metadata.

The endpoint is read-only. Reply composition, attachments, staff assignment, delivery confirmation and service-level workflows require a separate approved communications design; they must not be implied by this inbox.

## Production status

The product is not production-ready yet. The authoritative gap analysis and delivery backlog are maintained in the repository-level [preproduction report](../../INFORME_PREPRODUCCION.md) and [master plan](../../PLAN_MAESTRO.md).
