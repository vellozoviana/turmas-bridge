# 12F-B4-P1 — Action 5 and final publication hardening (Astra candidate)

The original Astra candidate did not change physical LAB state, schema or
plugin version. Its subsequent R1 review adds strict header/version rules and
patch versions Bridge 0.10.1 / EPF 0.13.2; see HMAC-V2-CONTRACT.md.
No physical LAB command, schema migration or institutional access is included.

## Materialization

Bridge accepts authenticated `POST /turmas-bridge/v1/publicacoes`; its
`Publication_Controller::receive` validates the body and Idempotency-Key,
hashes exact body bytes, reserves the command ledger, then executes
materialization, choices, and inventory in order. Same key/body replays the
stored response. Same key/different body returns 409. A stale `RESERVED` row
can be recovered with compare-and-set; stale `MATERIALIZING` becomes
`RECONCILIATION_REQUIRED`, not a second clone. Safe pre-side-effect dependency
failures may return to `RESERVED`; failures after a possible remote side effect
are conservative and require reconciliation.

`Materialization_Service` maps unique `publication_key` to the Form ID. It
obtains the template from `TURMAS_BRIDGE_TEMPLATE_ID` or the protected option
`turmas_bridge_template_id`; no physical template ID is hardcoded. Missing or
invalid Gravity Forms/template fails closed. The gateway calls
`GFAPI::duplicate_form()`, then updates title/description marker and
`is_active=false`, and reads back structure. Failed post-clone persistence
retains the Form ID for reconciliation; no automatic deletion is attempted.
The tests prove the postcondition, not that the clone could never be
transiently active between duplicate and update.

Choices derive from the signed payload and template adminLabels. Inventory
identity is publication key plus `class_key`, not form-local field IDs. Bridge
reconciles actual GP Inventory `gpi_field` bindings and choice resource links.
Capacity comes from EPF `vagas`; all representations of one class share the
same desired capacity. A fresh consumed count is read, and a request below
consumed is rejected before mutation. Consumed need not remain zero after the
Form becomes active. Bridge locks serialize its own orchestration only; they
do not lock direct Gravity Forms submissions.

Status GET is read-only and reports stored status, Form ID/state, inventory,
Resources, and blockers. Effective `MATERIALIZED` requires the mapping, an
inactive Form, and inventory `READY`; active or unknown structures are not
ordinary pre-activation readiness.

## HMAC protocol correction

REST paths remain under `/turmas-bridge/v1`. HMAC request-signature version is
separate: GET retains canonical v1 and prefix `v1=`. Every POST command
requires an Idempotency-Key, signs `idempotency-key:<value>` into canonical
v2, and uses prefix `v2=`. Host/scheme/port remain excluded. This fixes a
reproduced signature/key substitution defect. Legacy v1 POST requests are
rejected; EPF and Bridge code must be rolled out together, with no commands
sent while only one side is upgraded. Historical `*hmac-v1*` fixtures remain
legacy vectors and do not prove v2 POST interoperability.

## Final publication and ledgers

EPF owns `PublishOperation`, publication business state, audit, and final
`PUBLICADA` transition. Bridge owns activation state and causal evidence. A
final preflight stores snapshot JSON, fingerprint, snapshot format version 2,
snapshot version, Form ID, and publication row version in the durable
`PublishOperation`. Snapshot v2 binds Publication identity, exact classes,
capacity/CRES, and cycles represented as `numero` + `data_ciclo` in canonical
order.

The explicit activation POST carries operation key, publication key, expected
Form, and fingerprint; Idempotency-Key must equal operation key. Bridge CAS
grants one mutation owner. Success requires server-generated causal evidence
and a fresh structural read of the same active Form, exact Resources/bindings,
expected capacities, and valid `0 <= consumed <= capacity`. EPF verifies the
unchanged local snapshot/row version, then atomically finalizes publication,
operation, attempt, and audit event in InnoDB. A persistence failure after
remote activation remains reconciliation-required; it is not repaired by a
second activation.

Completed replay is a local persisted-identity check and does not reactivate.
R1 retry is a new technical attempt only after durable proof of safe
no-mutation/retryable disposition; recovery is read-only. Unknown or ambiguous
outcomes are not auto-retried. No schema change is required by this candidate.
