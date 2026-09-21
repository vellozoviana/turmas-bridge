# Fase 12F-B2 — Boundary interna de ativação de Form

Esta fase adiciona uma capacidade interna e não alcançável operacionalmente para a futura ativação de um Gravity Form. Nenhum endpoint, handler, tela, WP-CLI, cron, hook automático ou integração com o EPF foi registrado.

## Boundary

`Form_Activation_Gateway` expõe somente disponibilidade, leitura e ativação por ID. `WordPress_Form_Activation_Gateway` usa `GFAPI::get_form()` e, na versão local validada do Gravity Forms, `GFAPI::update_form_property($form_id, 'is_active', 1)`. A API estreita evita regravar o objeto inteiro do Form e reduz risco de lost update; não há acesso direto às tabelas pelo Bridge. A mutation consiste exclusivamente em alterar `is_active` e reler o Form. Se essa API não estiver disponível, o adapter fica indisponível (fail closed).

`Form_Activation_Service` recebe `publication_key`, `gravity_form_id`, `operation_key` e fingerprint. Antes da mutation, executa duas leituras locais de status, valida Publicação `MATERIALIZED`, Form esperado `INACTIVE`, mapping correto e inventário saudável (Resources, capacidades, consumo e bindings através do `Publication_Status_Reader`). Usa o lock de Publicação já compartilhado pela camada de escolhas e pela preparação de inventário. Depois da mutation, relê o status e exige Form `ACTIVE` e inventário ainda saudável.

`operation_key` e a fingerprint são validados quanto ao formato e preservados somente como evidência de correlação; nesta fase não existe ledger no Bridge que prove replay da mesma operação.

## Estados e falhas

O resultado estruturado distingue `ACTIVATED`, `BLOCKED`, `FAILED`, `UNKNOWN` e `RECONCILIATION_REQUIRED`. Form ativo sem prova de operação conhecida nunca é aceito como replay. Falha antes da mutation é `FAILED`; perda de resposta após tentativa é `UNKNOWN`; divergência pós-ativação exige reconciliação. O ponto de exposição pública é a alteração `INACTIVE → ACTIVE`, que não é executada nesta fase.

## Segurança e alcance

O serviço não é composto no `Plugin`, `Bridge_Controller` ou `Publication_Controller`; só testes internos o instanciam. Não há migration, nova tabela, novo estado persistido, alteração de HMAC ou mudança de schema (permanece 0.6.1). A fixture LAB e o Form real não são chamados pelo adapter durante esta fase.

Os testes cobrem caminho feliz, Form ausente/divergente, estado não materializado, ativo desconhecido, inventário indisponível, lock, indisponibilidade, falha pré-mutation, resposta perdida, ausência de confirmação, drift pós-ativação e ausência de reachability externa.

A suíte completa terminou com 123 testes e 423 assertions. A consulta read-only do LAB mantém a evidência anterior (Publicação `2099:E2F` materializada, Form 10 inativo, Entries 0, Resources 11/12 com capacidades 5/4 e bindings saudáveis); o serviço de ativação não foi executado fisicamente.
