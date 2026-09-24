# Fase 12B — contrato de entrega de Publicação

O Bridge aceita `POST /wp-json/turmas-bridge/v1/publicacoes`, sem criar ou
consultar Gravity Forms, GP Inventory, Resources ou Entries. A resposta
`accepted` somente confirma a validação e o registro idempotente do comando;
ela não indica que um formulário exista.

## Autenticação e idempotência

São obrigatórios `X-Turmas-Bridge-Timestamp`, `X-Turmas-Bridge-Nonce`,
`X-Turmas-Bridge-Signature` e `Idempotency-Key` nos POSTs de comando. GET usa
canonicalização HMAC v1 e prefixo `v1=`. POST usa canonicalização HMAC v2 e
prefixo `v2=`: além de método, rota, query canônica, timestamp, nonce e SHA-256
do body, assina `idempotency-key:<valor>` antes do hash. Alterar/remover a
chave invalida a assinatura. Host, scheme e porta continuam fora da string
canônica. Timestamp/nonce protegem o transporte; Idempotency-Key identifica
o comando lógico.

A mudança POST v2 é incompatível com clientes anteriores. EPF e Bridge devem
ser atualizados juntos e não devem processar comandos POST durante rollout
misto. GET v1 permanece compatível. Os fixtures HMAC v1 preservados são
vetores históricos; os testes atuais cobrem o contrato POST v2.

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
