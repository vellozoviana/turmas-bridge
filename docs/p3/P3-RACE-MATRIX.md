# P3 — matriz conservadora V1

OFFLINE. Não é autorização para corridas físicas.

| Cenário | Resultado esperado | O que NÃO prova |
| --- | --- | --- |
| Form ativo + capacity sync | BLOCKED_FORM_ACTIVE / zero capacidade escrita | Redução online atômica |
| Dois workers Bridge, mesma class_key | Lock ocupado ou execução serial; replay sem reaplicar | Lock sobre submissões GF |
| Duas classes, mesma publicação/Form | Lock publicação serializa documento GF completo | Lock sobre edição manual GF |
| Form inativo, desired >= consumed | Aplicação + pós-verificação completa | Ausência eterna de novas Entries |
| Form inativo, desired < consumed | BLOCKED_BELOW_CONSUMED / zero escrita | Compensação de excesso já existente |
| Consumo sobe depois do pre-read | Reconciliação se pós-leitura excede desired | Detecção de aumento posterior ao último read |
| Form ativa entre verificações | Bloqueio se antes de escrever; inconclusivo se pós-escrita | Inatividade contínua entre todos os reads |
| GF grava, meta falha | RECONCILIATION_REQUIRED | Rollback distribuído |
| Ledger final falha após efeito | APPLYING recuperável, não sucesso | Reexecução automática segura |
| Resposta perdida, replay/reconciliação | Retorno histórico ou inspeção explícita; sem reaplicar | Entrega exatamente uma vez na rede |

Corridas cooperativas nesta P3-D foram testes determinísticos, sem processos
contra LAB. O futuro físico prioriza A–G do master; ensaio concorrente adicional
requer autorização específica e ausência de submissões em voo.
