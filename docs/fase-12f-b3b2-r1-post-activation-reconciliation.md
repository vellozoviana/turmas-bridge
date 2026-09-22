# Fase 12F-B3B2-R1 — verificação pós-ativação

O checkpoint de correção é compatível com a versão pública `0.9.1`; o schema permanece `0.7.0`.

## Causa confirmada

`Form_Activation_Service::activate()` fazia o pre-read por `Publication_Status_Reader::read()`, exigindo publicação `MATERIALIZED`, Form `INACTIVE` e inventário `READY`. Depois de `WordPress_Form_Activation_Gateway::activate()`, o mesmo `read()` era chamado novamente. `Publication_Status_Reader` classifica Form ativo sem consultar inventário; o status resultava em `RECONCILIATION_REQUIRED`, e o serviço convertia isso em `POST_ACTIVATION_DRIFT`.

Esse acoplamento misturava “pronto para ativar” com “estrutura saudável depois de ativar”. A ativação física do Form 12 confirmou o defeito: o Form ficou ativo, mas o ledger permaneceu em `RECONCILIATION_REQUIRED`.

## Contratos separados

- `Publication_Status_Provider::read()` permanece o caminho de readiness pré-ativação. Form ativo nunca passa por esse caminho.
- `Post_Activation_Status_Provider::read_post_activation()` é um contrato somente leitura para o estado esperado ativo.
- `Inventory_Status_Gateway::read_post_activation()` e `GP_Inventory_Operations::inspect_post_activation()` consultam Resources, bindings, capacidade e consumo sem escrever. A inspeção ativa não é um bypass global: o método pré-ativação continua rejeitando Form ativo.
- O estado `POST_ACTIVATION_VERIFIED` exige Form ativo, mesmo `form_id`, Resources/bindings íntegros, capacidades consistentes e consumo coerente (`0 <= consumed <= capacity`). Consumo não precisa permanecer zero nem ser igual ao pre-read; submissões legítimas podem ocorrer após a exposição. Capacidade é configuração esperada e divergência de capacidade continua sendo drift.

## Máquina de estados e reconciliação

`PENDING → IN_PROGRESS → SUCCEEDED` continua sendo o caminho de sucesso. Falha comprovada antes de mutação vai para `FAILED`; resposta perdida, drift ou persistência inconclusiva vai para `RECONCILIATION_REQUIRED`. O incidente físico original não foi alterado: permanece terminal em `RECONCILIATION_REQUIRED` com `POST_ACTIVATION_DRIFT`. Uma futura resolução deve preservar esse erro e anexar evidência posterior em transição auditável, sem apagar o evento original; não foi implementada nesta rodada.

## Regressão e evidência física

Foram adicionados testes para Form ativo no pre-read, pós-read saudável, Form incorreto/ausente, binding drift, capacity drift e falha do leitor. A fixture física continua sem novas mutações: Form 12 permanece `ACTIVE`, Entries 0, Resource 15 e bindings preservados; o ledger permanece `RECONCILIATION_REQUIRED`. A fixture E2F permanece intacta (Form 10 inativo; Resources 11/12 em 5/4, consumo 0, saudáveis).

Nenhuma migration, mudança de HMAC, alteração de API REST, reconciliação física ou mudança no TURMAS-EPF foi feita.
