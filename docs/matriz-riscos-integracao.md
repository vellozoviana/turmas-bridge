# Matriz de riscos da integração

| Risco | Probabilidade | Impacto | Mitigação | Status |
| --- | --- | --- | --- | --- |
| API proprietária sem CRUD público homologado | Alta | Alto | Laboratório autorizado e roteiro 12D-B | Bloqueado |
| Duplicação de Resource | Média | Alto | Mapping idempotente e reconciliação antes de escrita | Pendente |
| Multiplicação de capacidade entre CRES | Média | Alto | Um `class_key`, um Resource Plan e teste de capacidade única | Parcial |
| Clone compartilhar Resource inesperadamente | Média | Alto | Teste de clone em fixture descartável | Pendente |
| Retry duplicar formulário | Média | Alto | Chave única de materialização e reconciliação | Parcial |
| IDs de field mudarem | Alta | Médio | Descoberta por `adminLabel`, nunca IDs fixos | Implementado |
| `adminLabel` ausente ou ambíguo | Média | Alto | Validação prévia e erro controlado | Implementado |
| Drift manual de fields/choices | Média | Médio | Fingerprint, detecção e reconciliação futura | Parcial |
| Redução abaixo do consumo | Média | Alto | Leitura oficial e bloqueio de regra de negócio | Bloqueado |
| Replay de requisição | Baixa | Alto | HMAC, timestamp e nonce | Parcial: revisar atomicidade do claim |
| Colisão de Idempotency-Key | Baixa | Alto | Hash do corpo e resposta `409` | Implementado |
| Falha parcial entre formulário e mapping | Média | Alto | Estado `FAILED`, `form_id` e reconciliação | Parcial |
| Acoplamento com FlowSheet | Média | Médio | Contrato e fase própria | Não iniciado |
| Acoplamento com InscriHub | Média | Médio | Contrato e fase própria | Não iniciado |
| WP-Cron de produção | Média | Médio | Cron real e checklist de produção | Pendente |
| Atualização de plugin alterar comportamento | Média | Alto | Fixar versões no laboratório e repetir roteiro | Pendente |
