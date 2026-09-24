# 12F-B4-P1-ASTRA-ACTION5-R1 — coordinated review evidence

Reviewed baselines: EPF e8af1d0266728eff28495b7395a0753e2c6deda1 and Bridge
9c11b25794b8c61795b33adead024dd883cad8b1. Original Astra candidates 55867fd
and c235bf7 remain preserved. Integration candidates use review/12f-hmac-v2,
with controlled cherry-picks and no conflicts.

## Original candidate: every changed hunk

Categories: A version selection; B POST canonicalization; C key handling;
D identity derivation; E replay stability; F tests; G documentation;
H unrelated. Ranges below are the old/new hunk starts from the original
three-context-line Git diffs, not line references to the final files.

| Repository/file | Original hunk starts (old -> new) | Categories | Decision |
| --- | --- | --- | --- |
| EPF docs/fase-12e-b-envio-bridge.md | 9 -> 9 | G | Correct delivery identity and payload explanation |
| EPF docs/fase-12f-b4-p1-action5-hardening.md | new -> 1 | G | Source map, boundaries, known limitations |
| EPF src/Bridge/Canonical_Request.php | 6 -> 6 | A/B/C | Required security change; R1 removes key-dependent version fallback |
| EPF src/Bridge/Publication_Client.php | 27 -> 27 | A/B/C/D/E | Sign POST key and derive stable exact-body identity |
| EPF src/Bridge/Publication_Client.php | 80 -> 102 | A/B/C | Apply v2 to activation too |
| EPF src/Bridge/Publication_Delivery_Service.php | 45 -> 45 | D/E | Replace row_version-based identity |
| EPF tests/unit/BridgePublicationTest.php | 49 -> 49; 109 -> 109 | F | Materialization and activation signer regressions |
| EPF tests/unit/PublicationDeliveryTest.php | 48 -> 48; 112 -> 125 | F | Stateful Form persistence fake and identity regressions |
| Bridge README.md | 42 -> 42; 76 -> 76 | G | Describe explicit GET/POST signature versions |
| Bridge docs/fase-12b-entrega-publicacao.md | 8 -> 8 | G | Correct command authentication contract |
| Bridge docs/fase-12f-b4-p1-action5-hardening.md | new -> 1 | G | Materialization/finalization audit and limits |
| Bridge src/Auth/Canonical_Request.php | 6 -> 6 | A/B/C | Bind the command key in v2 |
| Bridge src/Auth/Request_Authenticator.php | 36 -> 36; 51 -> 54 | A/B/C | Require v2 POST and verify signed key before nonce/business mutation |
| Bridge tests/unit/RequestAuthenticatorTest.php | 85 -> 85; 147 -> 168 | F | Key tampering and v1 POST rejection |

There are no category H changes. The review did not alter materializers,
inventory internals, schema, domain state machines or territorial permissions.

## R1 changes and rationale

- A/B/C: signature version is determined by method alone; POST canonicalization
  cannot emit v1 when its key is missing. It throws before signing; HTTP
  callers return bounded validation errors rather than falling back.
- C: common ASCII token normalization removes only outer SP/HTAB; rejects
  control characters, internal whitespace, overlength and comma values.
  Bridge rejects multiple values even if equal, before nonce reservation.
- C/E: EPF send() no longer invents a random business key when omitted.
  Publication_Delivery_Service already supplies the deterministic key.
- F: synthetic shared vector, downgrade/mutation/header matrix, full
  authenticated in-memory replay, new-process identity test and shuffled
  builder-input tests. WordPress request stub now models list headers and
  name canonicalization as the real request class does.
- F/G: historical v1 POST vector tests now explicitly translate their nested
  query representation to v2; production code has no legacy POST builder.
- G/release metadata: EPF patch 0.13.2 and Bridge patch 0.10.1 reflect actual
  changed runtime behavior, following existing patch-release conventions.
  Schemas remain EPF 0.7.0 / Bridge 0.8.0. No dependency update.
- G: exact contract, mixed-version matrix and future operator handoff. README
  version/administrative-action text is reconciled with the current code.

## Cross-repository evidence

Beyond the independently hashed shared vector, an ephemeral offline PHP
harness loaded old signer/verifier source from the untouched main worktrees
under isolated test namespaces, and candidate signer/verifier from review
worktrees. Only tests/bootstrap.php and synthetic WordPress stubs were used.

The four POST combinations matched the matrix: old/old accepted (known
vulnerability retained), old/new rejected, new/old rejected, new/new accepted.
All four GET combinations passed v1 authentication. The actual candidate EPF
Publication_Client materialization/activation POST and both status GET
envelopes were accepted by the actual candidate Bridge authenticator.
Canonical bytes matched exactly. Network calls: zero. Business controller
calls in that cross-source harness: zero. Authentication option writes
occurred only inside in-memory test stubs.

The separate PHPUnit authenticated-replay test exercises the business
controller with in-memory stores/gateways: two fresh authentication envelopes
and one stable key produce one materialization, one persisted fake command
result and an idempotent replay. It does not prove a physical WordPress run.

## Release limits

The old and new physical runtimes were not loaded or probed. P1 baseline is
operator-provided evidence only. No Action 5, POST to LAB, Form/Resource
creation, activation, reconciliation or deploy is part of this checkpoint.
A normal push publishes Git source and does not certify loaded plugin versions.
Follow ACTION5-RUNTIME-GATE.md in EPF after the coordinated source checkpoint.

The duplicate_form transient-active interval and direct-Gravity-Forms
submission/capacity race remain documented physical limits from the previous
audit; this HMAC review neither expands those guarantees nor changes that code.
