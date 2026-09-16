# Fase 12B — contrato de entrega de Publicação

O Bridge aceita `POST /wp-json/turmas-bridge/v1/publicacoes`, sem criar ou
consultar Gravity Forms, GP Inventory, Resources ou Entries. A resposta
`accepted` somente confirma a validação e o registro idempotente do comando;
ela não indica que um formulário exista.

## Autenticação e idempotência

São obrigatórios `X-Turmas-Bridge-Timestamp`, `X-Turmas-Bridge-Nonce`,
`X-Turmas-Bridge-Signature` e `Idempotency-Key`. A assinatura é
`v1=<HMAC-SHA256>` do canonical request `v1`, método, rota, query canônica,
timestamp, nonce e SHA-256 do body, cada item em uma linha. Nonce protege a
requisição assinada; Idempotency-Key identifica o comando lógico.

O schema `1` usa `publication_key` `{ano}:{codigo_formacao}` e `class_key`
`{publication_key}:{codigo_turma}`. Cada Turma leva nomes derivados,
capacidade total, CRES com zeros preservados e oito datas ISO (`YYYY-MM-DD`).
Não leva PII, Entries, secrets ou assinatura.

`wp_turmas_bridge_idempotency` armazena chave, hash do body, `publication_key`,
estado, resposta sanitizada e timestamps. A key é reservada atomicamente antes
da materialização; chave+hash iguais retornam processamento, replay ou
reconciliação conforme o estado. A mesma chave com hash diferente retorna 409.
O ciclo detalhado está em `fase-12b-idempotencia.md`. Retenção/limpeza de
registros de sucesso continua pendente de decisão operacional.

Erros: 401 para autenticação/replay de nonce, 400 para header/JSON inválido,
409 para conflito de idempotência, 422 para contrato inválido e 500 para falha
de persistência. O vetor fictício compartilhado está em `docs/fixtures/`.
