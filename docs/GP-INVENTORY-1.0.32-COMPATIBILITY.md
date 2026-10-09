# GP Inventory 1.0.32 compatibility review

Review date: 2026-10-09. Base: local main
`b83c9b3e8f0f70ea6ea80a1cf8e1605ba07a6cf6` (Bridge 0.11.2).
Production was not accessed. Static decision: B, compatible with explicit
policy and contract tests. Physical approval remains a separate gate.

Candidate patch release: Bridge 0.11.3. Only the plugin header and runtime
version constant change; the schema remains 0.9.0. Existing 0.11.1 and 0.11.2
releases use this same version convention.

## Authorized packages

| Version | ZIP SHA-256 |
| --- | --- |
| 1.0.29 | BFCF1BAF947EF27B7E625CB1CE5D1F130CE902223EE3374A4790077B2C6685FE |
| 1.0.32 | 12584D3678952D0B09EAEA17A449C32965ACB9F63750C331A2677267574496DF |

The plugin headers confirm the versions. Licensed vendor source is external
test input and is not included in this repository.

## Contracts compared against real source

| Contract | Classification | Evidence |
| --- | --- | --- |
| `gpi_resource`, Resource create/update, `gpi_inventory_limit`, `gpi_choice_based`, `gpi_properties` | UNCHANGED | `class-resources.php` is byte-identical. |
| `gpi_field`, `gpiInventory`, `gpiResource`, `gpiResourcePropertyMap`, save hook attaching fields | UNCHANGED | Binding write path unchanged. |
| `get_resource_fields(resource_id, form_id = null)` | BACKWARD_COMPATIBLE | Optional second argument; validates stale/malformed refs and scans the current Form. |
| Shared Resource query scope | BEHAVIOR_CHANGED_COMPATIBLY | Complete valid bindings return the same scope; incomplete same-Form indices now include missing fields. Bridge still rejects binding drift before querying. |
| `get_claimed_inventory_query`, SQL fragment structure and aliases `e`, `em`, `em_quantity` | UNCHANGED | Simple query builder byte-identical; choice quantity SELECT/joins unchanged. |
| Quantity summation, default one, Entry ID union | UNCHANGED | Bridge replaces SELECT/GROUP BY and preserves vendor FROM/JOIN/WHERE. |
| `gpi_query` priorities and add/remove methods | BEHAVIOR_CHANGED_COMPATIBLY | Checkbox cleanup corrected from priority 5 to actual registration priority 15. |
| Gravity Forms validation priorities | UNCHANGED | Vendor 11 pre-validation, 9/10 validation; Bridge guard remains priority 0. |
| Cache flushing | UNCHANGED | Per-Form cache group invalidation preserved. |
| Choice processing and available-inventory message | BEHAVIOR_CHANGED_COMPATIBLY | Already-processed fields now continue, not break; redundant count queries removed. |
| Inventory availability conditional logic | BEHAVIOR_CHANGED_COMPATIBLY | Single-choice inventory rules corrected. Bridge routes CRE with ordinary GF rules, not inventory availability rules. |

1.0.30 guards the removed Stripe handler method; 1.0.31 adds shared-Resource
discovery and validation fixes; 1.0.32 improves Bulk Add behavior and reduces
per-choice count queries during submissions. No Bridge functional adaptation
was found necessary in the compared paths. 1.0.31 is NOT allowlisted: a
1.0.32 review is not a separate approval of 1.0.31.

## Test boundaries

Set `TURMAS_BRIDGE_GP_INVENTORY_PACKAGE` to the authorized 1.0.32 ZIP and run
the complete PHPUnit suite. `GPInventoryVendorContractTest` executes a fresh
PHP subprocess for each contract, requiring the real vendor PHP classes
directly from the ZIP. The isolated harness simulates WP/GF storage and hooks;
it never boots WordPress and never connects to a database.

Covered against vendor code: Resource metadata, idempotent binding attach,
same-/cross-Form shared query scope, invalid index fallback, quantity/default
one, SQL aliases, hook cleanup/priorities, cache invalidation, exclusive CRE
guard, real Bridge adapter inspection/synchronization and Entry quantity union.
Database rows are controlled inputs, NOT proof of real SQL execution or Entry
creation. Existing capacity service tests cover applied replay without a second
write. A missing package produces explicit skipped vendor tests, not proof of
compatibility; all vendor contracts must run for release approval.

## Physical gate

Before any replacement, verify LAB identity, official GF 2.10.0, plugin/schema
versions, protected P1/P2/P3 snapshots and backup. The installed Bridge policy
must also support 1.0.32; merely replacing GP Inventory while the installed
Bridge only accepts 1.0.29 cannot pass readiness.

With the reviewed compatibility policy installed under appropriate LAB scope,
update GP Inventory only (leave GF 2.10.0), compare protected snapshots, verify
readiness and existing bindings, materialize an isolated multi-CRE fixture,
make exactly one ordinary GF submission and verify one Entry/claim, quantity
one and consumed delta one. Verify capacity and idempotent replay, no Resource
duplication, and P1/P2/P3 preservation. Stop on any unexpected result.

No production update, push, merge or physical approval follows from static PASS.

## Execution checkpoint

2026-10-09: PHPUnit PASS (349 tests, 1,430 assertions), all ten external
vendor-source contract scenarios executed, PHP lint PASS (120 files), PHPStan
PASS, and diff-check PASS. Policy now lists 1.0.29 and 1.0.32 in the isolated
compatibility worktree. No commit, push, merge or LAB replacement performed.

Physical validation NOT RUN: localhost ports 8080 and 3306 did not respond;
no Apache/MySQL process or listener was found. Installed files still declare
GP Inventory 1.0.29, and the installed Bridge policy still lists only 1.0.29.
Runtime versions and protected database records cannot be reconfirmed while
the LAB is offline. No backup or physical test was attempted in this state.
Resume with LAB identity/preflight after Apache and MySQL are started; resolve
the installed compatibility-policy prerequisite before replacing GP Inventory.

Resumed preflight: LAB HTTP 200, database `turmas_epf_local`, GF 2.10.0,
GP Inventory 1.0.29, Bridge 0.11.2/schema 0.9.0, EPF 0.14.1/schema 0.8.0.
Protected fixtures are present. Form 18 is inactive with Entries 15 and 16;
Resource 19 has capacity 4, fresh consumed 2, bindings 18_3/18_4/18_5 healthy.
Template global is 11. A dedicated backup precedes any plugin replacement.

## Physical validation result

2026-10-09: PASS. This final result supersedes the earlier NOT RUN checkpoint
above, which is retained as execution history.

Environment:

- TURMAS-BRIDGE 0.11.3;
- schema 0.9.0;
- Gravity Forms 2.10.0;
- GP Inventory 1.0.32.

Controlled submission:

- Form 18;
- exactly one new Entry: 17, created by a normal human Gravity Forms submission;
- selected CRE: 11;
- only `turma_cre_11` populated; `turma_cre_04` and `turma_cre_05` empty;
- effective claims: 1;
- quantity: 1, verified with the real vendor consumption query;
- Resource 19 capacity remained 4;
- fresh consumed changed 2 -> 3;
- multi-CRE double claim: NO;
- shared Resource: PASS;
- bindings `18_3`, `18_4` and `18_5` preserved;
- no new Resource;
- no new binding;
- no unexpected operation;
- Form 18 returned to INACTIVE.

Preservation:

- P1 preserved;
- P2 preserved;
- P3 preserved, with only the planned new Entry and consumption increment;
- historical incident `2094:E2F` preserved;
- Entries 15 and 16 and their metadata preserved, confirmed by hash comparison;
- global template remained 11.

Conclusion: GP Inventory 1.0.32 physical compatibility: PASS.

Production was not accessed. This result does not authorize production deployment.
