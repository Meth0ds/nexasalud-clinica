# ADR 001: Generated OpenAPI contract

## Status

Accepted — 2026-07-29.

## Context

The web application and clinical API need a versioned, reviewable contract.
Maintaining an independent hand-written specification would drift from Laravel
routes, validation rules, and response resources.

## Decision

The Laravel API uses `dedoc/scramble` as a development-only dependency to
generate an OpenAPI 3.1 document from the `/api/v1` application contract.

- Run `php artisan scramble:export` in the API container to write
  `storage/app/openapi/nexasalud-v1.json`.
- Interactive documentation is limited to local environments through
  `RestrictedDocsAccess`, has request execution disabled, and is not shipped in
  the production image because Composer installs production dependencies with
  `--no-dev`.
- The generated document describes the first-party session and CSRF model in
  its top-level description. A bearer scheme is deliberately not inferred,
  because it would misrepresent the browser-authenticated BFF.

## Consequences

The contract can be checked in CI and supplied to API consumers without
duplicating endpoint definitions. Changes to routes, request validation, or
resources must be accompanied by a regenerated contract review. The exported
artifact is operational output and must not be committed to source control.
