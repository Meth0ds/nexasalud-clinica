# NexaSalud Delivery Backlog

This checklist is the execution view of `tasks/plan.md`. Work proceeds in dependency order; a checkbox is only marked when its acceptance criteria and verification evidence are complete.

## Phase 0 — Plan and tooling

- [x] Record verified baseline and dependency audit result.
- [x] Add Docker-backed Laravel Boost MCP project configuration.
- [x] Verify the Docker-backed Boost MCP JSON-RPC handshake.
- [x] Obtain product-owner authorisation to continue with this plan.

## Phase 1 — Contracts and appointments

- [x] Define and validate OpenAPI/API contract coverage (Scramble 3.1 + OpenApiContractCoverageTest en verde; validado en CI).
- [x] Implement and test complete appointment lifecycle transition policy (transitions + optimistic locking; cubierto por AppointmentStatusTest y AppointmentDomainTest).
- [x] Make booking, cancellation and reschedule conflict-safe end to end (slot holds, idempotency, versioned mutations; PortalBookingFlowTest en verde).
- [ ] Run the Phase 1 clinical workflow checkpoint (requiere validación del product owner clínico).

## Phase 2 — Product slices

- [ ] Complete all enabled patient portal actions or explicitly hide/defer them.
- [x] Implement waitlist offers, expiry and atomic conversion (offer/accept/reject/expire + scheduler `waitlist:expire-offers` + portal accept/reject; 4 de agosto de 2026).
- [x] Implement the automated freed-slot matching engine for waitlist (Task 2.2; motor `waitlist:match-slots` + `WaitlistMatchEngine` con ventana/días/profesional, lead mínimo y presupuesto por ejecución; scheduler cada 5 min; 4 de agosto de 2026).
- [ ] Complete patient, consent and clinical-context boundaries (consentimiento listado/registro/retirada implementado; alta y gobierno de paciente pendientes).
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
