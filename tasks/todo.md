# NexaSalud Delivery Backlog

This checklist is the execution view of `tasks/plan.md`. Work proceeds in dependency order; a checkbox is only marked when its acceptance criteria and verification evidence are complete.

## Phase 0 — Plan and tooling

- [x] Record verified baseline and dependency audit result.
- [x] Add Docker-backed Laravel Boost MCP project configuration.
- [x] Verify the Docker-backed Boost MCP JSON-RPC handshake.
- [x] Obtain product-owner authorisation to continue with this plan.

## Phase 1 — Contracts and appointments

- [ ] Define and validate OpenAPI/API contract coverage.
- [ ] Implement and test complete appointment lifecycle transition policy.
- [ ] Make booking, cancellation and reschedule conflict-safe end to end.
- [ ] Run the Phase 1 clinical workflow checkpoint.

## Phase 2 — Product slices

- [ ] Complete all enabled patient portal actions or explicitly hide/defer them.
- [ ] Implement waitlist matching, offers, expiry and conversion.
- [ ] Complete patient, consent and clinical-context boundaries.
- [ ] Complete consent-aware communications and reminder delivery.
- [ ] Decide and implement or formally defer payment/invoice scope.
- [ ] Run the Phase 2 no-placeholder capability checkpoint.

## Phase 3 — Security and privacy

- [ ] Reconcile threat model, run deep security review and track/remediate findings.
- [ ] Harden and attack-test OIDC, sessions and privileged actions.
- [ ] Implement privacy lifecycle, retention and redaction controls.
- [ ] Add continuous supply-chain, SBOM, SAST, secret and image security gates.
- [ ] Run security and privacy checkpoint.

## Phase 4 — Experience quality

- [ ] Audit and remediate WCAG 2.2 AA critical journeys.
- [ ] Add reliable real-browser end-to-end suite.
- [ ] Enforce performance budgets and privacy-safe frontend monitoring.
- [ ] Run accessibility, performance and E2E checkpoint.

## Phase 5 — Operations

- [ ] Define production infrastructure and deployment topology as code.
- [ ] Implement secrets, backups, restore and disaster recovery drills.
- [ ] Implement observability, alerting and incident runbooks.
- [ ] Build gated progressive release pipeline and rehearse rollback.
- [ ] Run staging deployment, restore and rollback checkpoint.

## Phase 6 — Go-live

- [ ] Gather release evidence and obtain accountable approvals.
- [ ] Run controlled launch, hypercare and post-implementation review.
