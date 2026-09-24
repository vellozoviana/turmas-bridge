# HMAC v2 contract — coordinated EPF / Bridge security review

Applies to EPF 0.13.2 and Bridge 0.10.1. REST routes stay under
/turmas-bridge/v1; delivery payload schema stays "1"; final snapshot format
stays "2". These are separate version domains. No database migration.

## Authenticated canonical bytes

Each component occupies one line, joined by a single LF byte (0x0A), with no
terminal LF. POST has eight lines; GET has seven.

| Component in order | EPF signer | Bridge verifier | Match |
| --- | --- | --- | --- |
| 1. Signature contract | POST = v2; GET = v1 | Chosen from actual method, never from received version | YES |
| 2. Method | strtoupper(method) | strtoupper(method) | YES |
| 3. Route | trim, one leading slash, remove trailing slash except root | Same normalization | YES |
| 4. Query | Remove rest_route, recursive ksort SORT_STRING, http_build_query RFC3986 | Identical algorithm | YES |
| 5. Timestamp | Decimal Unix UTC timestamp | One header, 10 decimal digits, max skew 300 seconds | YES |
| 6. Nonce | Per-request random token | One header, 16–128 ASCII A-Z a-z 0-9 . _ ~ - | YES |
| 7. POST only | idempotency-key: followed by normalized token | Same literal and token | YES |
| Last. Body | Lowercase hex SHA-256 of exact transmitted bytes | Same hash of raw request body | YES |

POST example structure:

~~~text
v2
POST
/turmas-bridge/v1/publicacoes
<canonical query, possibly empty>
<timestamp>
<nonce>
idempotency-key:<token>
<lowercase SHA-256 of raw body>
~~~

HMAC-SHA256 is computed over the complete canonical bytes. Its lowercase hex
digest is prefixed with v2= (POST) or v1= (GET). The version is authenticated
as the first canonical line as well as enforced in the signature prefix.
Host, scheme, port and /wp-json front-controller prefix are not components.
Production transport still requires HTTPS under the existing transport policy.

POST without a valid key cannot build canonical material; there is no v1
fallback. The verifier rejects v1 POST, v2 GET, unknown/missing versions and
unsupported methods with a bounded 401. Changing either key or body invalidates
a signature. Knowing a valid old signature is insufficient to remove the key
and downgrade to v1.

## Header rules

WordPress canonicalizes header names case-insensitively (and maps hyphens to
underscores internally). It provides a list of values. The verifier requires
exactly one value for timestamp, nonce, signature and POST Idempotency-Key.
Multiple values, including identical duplicates, are rejected. Comma-joined
duplicates are rejected by the token grammar too. Headers discarded by an
upstream HTTP server cannot be reconstructed by PHP; this guarantee applies
to the values WordPress actually exposes.

Only outer SP and HTAB are removed. Internal whitespace is not normalized into
a new identity: it is rejected. CR/LF, NUL, control characters, non-ASCII,
commas and token lengths above 128 are rejected. Key bytes remain case-sensitive.
The common transport grammar is [A-Za-z0-9:._~-]{1,128}. Controllers preserve
their narrower business contracts: materialization uses
[A-Za-z0-9._~-]{16,128}; activation/reconciliation use [A-Za-z0-9:_-]{1,128}.
EPF send() requires an explicit valid key and does not invent a random command
identity. Its signed header and canonical material use the same normalized key.

Errors reveal only turmas_bridge_unauthorized, a fixed message and status 401;
they do not return an expected signature, canonical string, or shared secret.

## Every protected route

| Method | Route suffix | Signature | Business identity |
| --- | --- | --- | --- |
| POST | /publicacoes | v2 | Materialization Idempotency-Key |
| POST | /publicacoes/{publication_key}/activation | v2 | Header equals body operation_key |
| POST | /operacoes/{operation_key}/reconciliation | v2 | Header equals body reconciliation_key |
| GET | /ping, /formularios/{id} | v1 | None |
| GET | /publicacoes/{publication_key}, /operacoes/{operation_key} | v1 | None |

All routes register Bridge_Controller::authenticate as permission_callback.
The EPF Publication_Client signs its materialization and activation POSTs as
v2; status and activation_status are v1. EPF currently has no reconciliation
POST client. The existing Bridge reconciliation command is nevertheless
covered by the same v2 verifier; no new EPF command was added.

Required headers are parsed and HMAC verified before an authentication nonce
is claimed; only then may WordPress call a business controller. Contract
validation precedes command-ledger reservation and business mutation.
A new timestamp/nonce with the same business key is a legitimate replay.
A reused authentication nonce is rejected even for the same business key.
A signed GET is business-read-only, but does reserve its authentication nonce
and may schedule nonce housekeeping; do not describe it as zero database writes.

## Delivery identity and final snapshot

Materialization key:
publication-{local publication ID}-{sha256(exact JSON payload bytes)}.

Publication_Payload_Builder constructs fixed property order and primitive
types; classes sort by class_code, CRES sort as strings, cycles sort by numero.
JSON uses JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, without pretty print.
Tests permute input maps and database-row order and reconstruct in a new PHP
process. No timestamp, random value, gravity_form_id or row_version enters
the identity. The source row_version still protects local optimistic writes.

The deterministic guarantee applies to the payload builder used by delivery,
not arbitrary associative-array ordering supplied by external callers.
Changing transmitted bytes deliberately changes the technical identity.

Delivery schema 1 sends the eight data_ciclo values in numero order. It does
not transmit/persist a final snapshot fingerprint or snapshot_format_version.
Final preflight independently uses Publication_Snapshot format 2, including
numero + data_ciclo; format 1 is not silently treated as format 2.

Final activation keys are generated by preflight as
publish-{publication_key}-v{snapshot_version}, with distinct technical attempt
keys on authorized retry. They do not share the publication-{id}-{hash}
namespace. Materialization, activation and reconciliation also have distinct
ledgers, and the route/method are signed.

Bridge's materialization command ledger stores Idempotency-Key, raw payload
hash and publication_key. Same key/body replays stored success; same key with
different body returns 409 after valid authentication. A new key reserves a
new command row, not necessarily a new Form: Materialization_Service reuses
the unique publication_key mapping, then choices/inventory run their existing
checks. New key is not a permission to bypass drift or recovery restrictions.

## Shared synthetic vector and tests

docs/fixtures/hmac-v2-contract.json is identical in both repositories.
It includes no HMAC secret. Canonical SHA-256, computed independently with
.NET SHA256 and checked by both PHP implementations:

3a1b9fc8c676c8aad65586cfb97e360889251c5769902a61c83fd752f828a5b6

CanonicalHmacV2Test verifies exact bytes, query ordering, delimiters and key
normalization in both projects. Historical v1 POST vectors remain historical;
tests explicitly translate their query/body representation into v2 instead
of retaining an insecure production v1 POST builder.

HmacCommandSecurityTest covers T1–T8 and T10–T12: valid v2 on all POST routes;
body/key/route/method/query/time/nonce mutations; absent/malformed/duplicate
headers; stale timestamp; reused nonce; version substitution and downgrade;
v1 GET status. PublicationControllerTest authenticates two distinct envelopes
before dispatching the same command to in-memory fakes: one materialization,
one ledger result, successful business replay (T9). Tampered authentication
does not reserve a business command or nonce.

PublicationDeliveryTest proves M1/M2 using the full service and a fresh service
instance after local row_version/Form ID persistence. MaterializationIdentityTest
proves a real child PHP-process restart (M3), canonical builder order and
separate command namespaces (M6). PublicationDeliveryTest covers changed
content (M4) and distinct publication IDs (M5). No physical endpoint or LAB
database is called by these tests.
