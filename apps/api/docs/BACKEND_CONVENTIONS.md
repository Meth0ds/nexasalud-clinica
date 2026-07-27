# Backend conventions

## Documentation standard

Every non-trivial service, controller, action, event, listener, job, DTO and public method must have a PHPDoc block in English ending with a period. Document purpose, security or business invariants, non-obvious side effects, relevant inputs and failure conditions. Do not repeat the signature or describe simple getters.

```php
/**
 * Queues a draft after validating current channel-specific consent and its optimistic version.
 */
public function queue(QueuePatientCommunicationRequest $request, PatientCommunication $communication): PatientCommunicationResource
```

Use precise verbs such as `Returns`, `Creates`, `Rejects`, `Persists`, `Validates` and `Dispatches`. Avoid PHI, credentials, token values and raw request bodies in comments.

## Error contract

API and authentication failures must use RFC 9457 problem responses. Error messages must be actionable but cannot disclose SQL, stack traces, filesystem paths, authorization rules, tokens or patient information. Preserve the `X-Trace-Id` for secure support correlation.

Client code must treat `409`, `412`, `419`, `422`, `429` and `5xx` as recoverable states with explicit UX. Retries are only allowed for idempotent operations and must use bounded backoff and jitter.

## Debugging and incident handling

Use the `X-Trace-Id` / RFC 9457 `traceId` as the only browser-to-support correlation value. Support staff must locate the corresponding server-side event through protected logs; never ask users to share page source, cookies, bearer tokens, raw request bodies, patient data or screenshots containing clinical content.

Unexpected exceptions are reported server-side and rendered to clients as a generic `500` problem document. Expected authorization, validation, CSRF, conflict and identity protocol failures use stable problem slugs and are not reported as operational defects. Preserve the original exception when rethrowing, and do not catch an exception merely to return a success-shaped response.

Every externally visible failure path needs an automated feature test covering status, content type, safe body, cache headers and trace correlation. Every worker failure needs a safe `failed` hook, a retry policy, an observable queue metric and a documented operator action before a real delivery adapter is enabled.

## Events, listeners and queues

Events are domain facts and must be emitted after the transaction commits. Event payloads contain opaque IDs and allowlisted operational metadata only. Never include message text, recipient contact details, clinical notes, access tokens, cookies or signed URLs.

Listeners that perform asynchronous work must declare a queue, timeout, retry count and backoff. They need an idempotency boundary and should use `WithoutOverlapping` when work is scoped to a mutable aggregate. The `failed` hook must log only safe metadata with the trace ID. Every listener requires an automated success and idempotency test.

An event is not a substitute for a delivery adapter. External providers must revalidate consent, authorization and current entity state immediately before dispatching; failed jobs need a dead-letter process, alerting and an operator runbook.

## Tenant and audit boundaries

Normal requests use the tenant global scope. A background worker that receives a tenant ID must intentionally bypass the global scope only for a narrowly-scoped idempotency query and must create records with the explicit tenant ID. Do not set a mutable global tenant context from untrusted payload data.

Audit metadata is allowlisted. It may include status, event type, channel, template usage and opaque IDs. It may not include plaintext clinical content, contact values, secrets or authentication artifacts.

## Required checks before review

```powershell
docker compose --env-file infra/.env -f infra/compose.yaml exec -T api vendor/bin/pint --test
docker compose --env-file infra/.env -f infra/compose.yaml exec -T api php artisan test
docker compose --env-file infra/.env -f infra/compose.yaml exec -T api composer audit --locked --no-dev
```

The preproduction report is updated with the commands, results and remaining risks after material changes.
