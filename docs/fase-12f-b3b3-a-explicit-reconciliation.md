# Fase 12F-B3B3-A — Reconciliação explícita

Esta fase cria uma fronteira de reconciliação separada do ledger de ativação.
Ela não ativa formulários, não altera Resources e não chama o serviço de
ativação. O objetivo é registrar uma verificação posterior, autenticada e
idempotente, de uma operação que terminou em `RECONCILIATION_REQUIRED`.

## Modelo e estados

Cada chamada usa uma `reconciliation_key` própria. A tabela
`turmas_bridge_reconciliation_attempts` é append-only por chave: a reserva é
`PENDING`, há uma transição CAS para `IN_PROGRESS` e o resultado terminal é
`CONFIRMED_SUCCESS`, `INCONCLUSIVE` ou `TECHNICAL_FAILURE`. A chave, a
publicação, o Form esperado e o fingerprint são comparados antes de qualquer
leitura. A mesma chave terminal apenas reproduz o resultado; uma nova chave
permite uma nova tentativa. O registro original da ativação e seu estado/erro
não são modificados.

## Evidência e decisão

O verificador reutiliza a leitura pós-ativação da Fase 12F-B3B2-R1. A leitura
exige publicação e Form corretos, Form `active`, estado efetivo
`POST_ACTIVATION_VERIFIED`, inventário `READY`, Resources/bindings saudáveis e
`0 <= consumed <= capacity`. Consumo pode mudar legitimamente depois da
ativação; não se exige igualdade com o snapshot nem consumo zero.

Além da estrutura atual, é necessária evidência histórica de causalidade da
mutação (`mutation_attempted`, `activation_mutation_confirmed` ou a mesma
indicação dentro de `gateway`). O caso físico B3B2 original contém somente
`reason_code`/`verified_at` e, portanto, seria `INCONCLUSIVE`, não uma prova de
sucesso. Formulário ativo isoladamente nunca é suficiente.

Falhas de leitura, exceções, capacidade inválida, Resource ausente, binding
divergente e persistência não confirmada são tratadas de forma conservadora.
O evidence persistido é uma allowlist pequena e limitada a 8 KiB; não contém
Entries, PII, segredos ou payloads do fornecedor.

## Contrato REST

`POST /wp-json/turmas-bridge/v1/operacoes/{operation_key}/reconciliation`
usa a autenticação HMAC/nonce existente. O header `Idempotency-Key` deve ser a
mesma `reconciliation_key` do corpo; caminho, corpo e fingerprint precisam
coincidir. O GET existente de operação continua somente leitura e agora expõe
um resumo da última tentativa de reconciliação, sem reconciliar
automaticamente.

Exemplo sintético de corpo:

```json
{
  "schema_version": "1",
  "reconciliation_key": "reconcile-2099-e2e-1",
  "activation_operation_key": "activate-2099-e2e",
  "publication_key": "2099:E2E",
  "expected_form_id": 10,
  "snapshot_fingerprint": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
}
```

## Limites desta entrega

Não foi executada reconciliação física nem aplicado o novo schema no LAB
live-linked. A validação desta fase é unitária e de contrato; a próxima etapa
deve revisar o diff e criar o checkpoint antes do gate físico B3B3-B. A
reconciliação física futura deve usar uma fixture sintética e não alterar o
Form 10 histórico.
