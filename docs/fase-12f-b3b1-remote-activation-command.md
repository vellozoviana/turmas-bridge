# Fase 12F-B3B1 — Remote Activation Command Boundary

Esta etapa torna alcançável, por REST autenticado, a orquestração do ledger B3A e da primitive de ativação B2. A etapa foi validada somente com doubles; nenhum Form ou Resource real foi mutado.

A versão pública do plugin foi incrementada de `0.8.0` para `0.9.0`, seguindo a convenção dos checkpoints funcionais anteriores. O schema permanece `0.7.0`; não há migration.

## Boundary e autenticação

- `POST /turmas-bridge/v1/publicacoes/{publication_key}/activation`
- `GET /turmas-bridge/v1/operacoes/{operation_key}` (consulta read-only)
- As duas rotas usam o `Request_Authenticator` existente: HTTPS (quando exigido pelo ambiente), timestamp, nonce e HMAC-SHA256 v1.
- O POST exige JSON, `Idempotency-Key` igual a `operation_key`, `publication_key` do caminho igual ao corpo, `schema_version=1`, Form esperado inteiro positivo e fingerprint SHA-256 hexadecimal.
- O controller não expõe segredo, assinatura, cookie, PII ou evidência bruta.

## Fluxo efetivo

`Bridge_Controller::activation` → `Remote_Activation_Command_Service` → `Activation_Operation_Orchestrator` (reserva/CAS do ledger) → `Activation_Command` (B2) → transição final do ledger → resposta HTTP.

O serviço recebe B2 por injeção de dependência. Em produção o wiring usa `Form_Activation_Service`; nos testes usa fake. A única mutation boundary continua sendo B2. O Bridge não escreve Gravity Forms nem GP Inventory diretamente.

O lock de publicação continua pertencendo à primitive B2, que já protege a leitura e mutação do Form. B3B1 não adquire o mesmo lock externamente, evitando deadlock/não-reentrância. Esse lock não serializa submissões diretas feitas pelo Gravity Forms; a race residual é tratada pelo CAS do ledger e pelo estado `RECONCILIATION_REQUIRED`.

## Estados e respostas

- Nova operação: reserva `PENDING`, CAS para `IN_PROGRESS`, chama B2 uma vez.
- Sucesso confirmado: `SUCCEEDED`, HTTP 201.
- Replay de `SUCCEEDED`: HTTP 200 sem chamar B2.
- `FAILED`, conflito, operação em andamento ou reconciliação: HTTP 409/422 conforme a causa, sem nova mutação.
- Exceção B2 ou evidência inconclusiva: `RECONCILIATION_REQUIRED`.
- Falha de persistência após possível mutação nunca é convertida em sucesso; retorna reconciliação e preserva o ledger para recuperação.

As evidências persistidas são limitadas a `observed_form_state`, `observed_inventory_health`, `reason_code` e `verified_at`. O payload do cliente não é armazenado como evidência.

## Segurança e testes

Os testes cobrem validação de identidade, autenticação/rotas, reserva e CAS, conflitos, replay sequencial, bloqueio seguro, exceção B2, falha de persistência e status read-only. Todos os testes usam fakes; não há chamada física ao gateway WordPress.

## Gate físico futuro

B3B2 deverá usar fixture sintética nova, distinta dos Forms 10, 199 e 297 e do Resource 428371. Esta etapa não cria fixture, Entry, Resource, ativação física, commit ou push.
