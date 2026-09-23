# 12F-B4-R1 — Activation Failure Disposition

`GET /wp-json/turmas-bridge/v1/operacoes/{operation_key}` keeps its existing response fields and adds a bounded `failure_disposition` object:

```json
{
  "failure_disposition": {
    "mutation_safety": "NO_MUTATION",
    "retry_disposition": "RETRYABLE"
  }
}
```

The values are derived by Bridge from the durable activation state, error code, and persisted server-generated `activation_mutation` evidence. They are not request inputs and do not change the ledger state machine. `FAILED` remains terminal for its idempotency key; Bridge never creates a retry or reopens an operation.

`NO_MUTATION + RETRYABLE` is returned only for a `FAILED` row with valid persisted `NO_MUTATION_EVIDENCE` and one of these known causes: `GRAVITY_FORMS_UNAVAILABLE`, `STATUS_UNAVAILABLE`, `LOCK_UNAVAILABLE`, `PUBLICATION_NOT_MATERIALIZED`, `FORM_NOT_INACTIVE`, or `INVENTORY_NOT_HEALTHY`. The first three indicate temporary dependency/lock problems; the last three can be corrected without changing the publication snapshot. EPF must run its own readiness check before an explicit later attempt.

Other `FAILED` codes with verified no-mutation evidence are `NO_MUTATION + NOT_RETRYABLE`. Missing/invalid evidence, unknown causes, `IN_PROGRESS`, and `RECONCILIATION_REQUIRED` fail closed to `MUTATION_UNCERTAIN + RECONCILIATION_REQUIRED`. `SUCCEEDED` is `MUTATION_CONFIRMED + NOT_RETRYABLE`; confirmed mutation represented by any other ledger state is reconciliation-required, never retryable.

The fields are additive for older consumers. An older Bridge response without them remains parseable, but a newer EPF client must not infer retry safety from the error text or state alone. Bridge schema remains `0.8.0`; no migration or ledger change is required.
