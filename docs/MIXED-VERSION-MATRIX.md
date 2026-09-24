# Mixed-version matrix and coordinated release

| EPF | Bridge | POST command behavior | GET query behavior |
| --- | --- | --- | --- |
| 0.13.1 (old) | 0.10.0 (old) | Legacy v1 accepted; known unsigned Idempotency-Key defect remains | v1 accepted |
| 0.13.2 (new) | 0.10.0 (old) | v2 prefix rejected by old v1-only authenticator, before command mutation | v1 accepted |
| 0.13.1 (old) | 0.10.1 (new) | v1 prefix rejected by new POST-v2-only authenticator, before command mutation | v1 accepted |
| 0.13.2 (new) | 0.10.1 (new) | v2 authenticates key and body; normal contract/idempotency gates apply | v1 accepted |

There is no fallback and no negotiated compatibility window for insecure
POST v1. Safe rejection may appear as the EPF's bounded delivery error.
A mixed pair must not be used to test materialization.

## Source integration and publication

Prepare both review/12f-hmac-v2 branches, preserving the Astra candidates.
Run both complete suites after final review and patch-version changes.
Fetch both origins and stop both pushes if either remote main advanced.
Integrate into both local mains only with fast-forward history, preserving
pre-existing untracked documents. Run both main quality gates.
Before publishing, fetch/check origins again.

Push Bridge main first, then EPF main immediately after. The verifier becomes
strict first; either mixed combination fails closed. Both tracked workflows
run quality only and contain no deployment step. Repository-external hosting
automations are not observed by this source review; no deployment is executed
as part of this checkpoint.

If either push fails, stop and report the exact local/remote state; never
force, merge, rebase or imply coordinated success. Do not issue POSTs while
publication of the pair is incomplete.

Both repositories must be published and both physical plugin runtimes must
then be confirmed by the operator. Git push alone does not prove that the
physical WordPress loaded the new classes, nor that opcode caches refreshed.
The main integration may update files used by a local symlink, but this
session neither bootstraps that WordPress nor executes a LAB command.

Schema remains EPF 0.7.0 / Bridge 0.8.0. No Composer dependency or migration
change is needed. First future authenticated network check: business-read-only
GET status with HMAC v1. Only after it passes, and under the separate physical
authorization, prepare one Action 5 POST using HMAC v2.

Existing successful or reserved materialization commands may have old
row_version-derived keys. They are not rewritten or deleted. A newly derived
key can add a command-ledger row while the unique publication mapping preserves
the logical Form. The first P1 delivery has no prior materialization command
according to operator evidence; old fixture ledgers remain untouched.
