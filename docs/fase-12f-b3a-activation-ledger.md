# Fase 12F-B3A — Ledger persistente de ativação

Esta etapa adiciona somente a persistência interna da futura ativação remota. Nenhum endpoint, handler administrativo, WP-CLI, cron, integração com o TURMAS-EPF ou mutação do Gravity Forms é registrado.

## Modelo

A tabela `{$wpdb->prefix}turmas_bridge_activation_operations` usa `operation_key` como identidade única. Cada registro mantém apenas a correlação técnica mínima: publicação, Form, fingerprint, estado, erro, evidência estruturada e timestamps. Não são armazenados payload completo, dados pessoais, cabeçalhos assinados, HMAC, credenciais ou resposta HTTP bruta.

Estados duráveis: `PENDING`, `IN_PROGRESS`, `SUCCEEDED`, `FAILED` e `RECONCILIATION_REQUIRED`. `UNKNOWN` é apenas um resultado transitório e nunca é persistido. Transições são monotônicas e protegidas por compare-and-swap (`id` + estado esperado). `SUCCEEDED`, `FAILED` e `RECONCILIATION_REQUIRED` são terminais para automação.

`evidence_json` possui contrato mínimo: somente `observed_form_state`, `observed_inventory_health`, `reason_code` e `verified_at`, com valores escalares. Chaves desconhecidas, objetos e estruturas aninhadas são rejeitados; nunca devem conter HMAC, headers, credenciais, cookies ou PII. `response_status` e `response_body` não fazem parte do ledger.

## Idempotência e recuperação

Mesma chave com publicação, Form e fingerprint iguais retorna a operação existente. A mesma chave com qualquer identidade divergente retorna conflito sem sobrescrever o registro. Operações diferentes podem existir para a mesma publicação, permitindo histórico de versões; a serialização operacional continuará sendo responsabilidade de um lock na etapa B3B.

Um worker pode reservar (`PENDING`), adquirir (`IN_PROGRESS`) e concluir, falhar seguramente antes do efeito colateral ou exigir reconciliação. A recuperação de uma operação `IN_PROGRESS` stale usa CAS e move-a para `RECONCILIATION_REQUIRED`; não há retry automático após uma possível mutação externa.

`UNIQUE(operation_key)` e CAS são suficientes para o ledger; não há transação ACID envolvendo a futura mutação externa.

## Fontes de verdade

- EPF: intenção de negócio, snapshot, operador e estado de publicação.
- Ledger Bridge: identidade e evidência técnica da execução remota.
- Gravity Forms: estado físico ativo/inativo.
- GP Inventory: capacidade e consumo atuais.

O lock do Bridge não serializa submissões feitas diretamente pelo Gravity Forms.

## Limite para B3B

O endpoint autenticado, HMAC adicional, controller, integração EPF, ativação física e estados `PUBLICADA`/`SUSPENSA` permanecem fora desta etapa. A migration é aditiva e preserva as tabelas existentes; o schema local passa de 0.6.1 para 0.7.0 e a versão pública do plugin para 0.8.0.
