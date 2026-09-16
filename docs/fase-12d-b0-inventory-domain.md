# Fase 12D-B0 — Domínio de Resources Compartilhados

## Limites

Esta entrega prepara somente o domínio local do Bridge. Não instala nem chama
Gravity Forms, GP Inventory, FlowSheet ou APIs proprietárias. A Fase 12D-B real
continua bloqueada até existir laboratório descartável e autorizado.

## Arquitetura encontrada

O payload v1 já entrega `publication_key`, `class_key`, `capacity` e CRES. O
`Resource_Plan_Builder` já produz uma entrada por `class_key` e várias
representações por CRE. Fields são descobertos por `adminLabel`, e cada choice
usa `value = class_key` e `text = nome curto`.

O plano usa objetos de domínio internamente e mantém a mesma saída array para a
preparação atual de choices. Não houve mudança de payload, schema ou versão.

## Identidade e capacidade

`Resource_Identity` é formado por `publication_key + class_key`. Texto humano,
CRE, posição e field não são identidades do Resource. Uma Turma de 30 vagas nas
CRES 01, 02 e 03 gera um plano, capacidade 30 e três representações — nunca 90.

EPF é autoridade para capacidade total desejada. O futuro Inventory será
autoridade para consumo e disponível. O Bridge não persiste `consumed`; ele só
existe no fake de testes como snapshot externo.

Uma mudança de texto, Local, Endereço ou Ciclos não muda a identidade nem a
capacidade do Resource. A mudança de nome curto continua relevante para a
apresentação da choice e para o fingerprint de choices já existente, mas não
cria outro pool lógico. Mudança de Vagas mantém a identidade e altera somente a
capacidade desejada.

| Situação | Decisão |
| --- | --- |
| 30 / consumidas 20 / solicitada 40 | `INCREASE`, remaining 20 |
| 30 / consumidas 20 / solicitada 25 | `DECREASE_ALLOWED`, remaining 5 |
| 30 / consumidas 20 / solicitada 20 | `DECREASE_ALLOWED`, remaining 0 |
| 30 / consumidas 20 / solicitada 15 | `DECREASE_BELOW_CONSUMED`, bloquear |
| 30 / solicitada 30 | `NO_CHANGE`, sem escrita |

## Boundary e laboratório

`Inventory_Gateway` é uma interface de domínio para localizar/assegurar um
Resource, alterar capacidade e sincronizar representações. Só há fake de teste;
não há adapter de produção ou stub que finja sucesso.

Não há migration de mapping: identificador externo, tipo e lifecycle não foram
comprovados. O provisioning futuro deverá localizar/reconciliar por identidade
antes de criar, para suportar falhas parciais sem duplicação.

O fluxo futuro, ainda não implementado, é: publicação aceita → formulário
materializado → choices preparadas → Resources provisionados → representações
vinculadas → configuração validada. O formulário permanece inativo durante
todas essas etapas; Resource provisionado não significa publicação liberada.
Uma falha antes do Resource deve permitir retry. Uma falha depois de qualquer
escrita externa deve exigir localização e reconciliação antes de nova criação.

Diagnósticos futuros podem registrar `publication_key`, `class_key`, identidade
do Resource e identificador externo quando comprovado, nunca CPF, Entry, nome,
e-mail ou payload completo.

Para desbloquear a 12D-B real, um laboratório autorizado deve confirmar por API
pública/suportada: modelo/ID/criação/consulta/atualização do Resource; capacidade,
consumo e disponível; vínculo field + `choice.value` + Resource em múltiplas
CRES; aumento, redução, retry e falha parcial; efeito de clonagem, atualização
de choices, `adminLabel` e formulário inativo. Reflection, tabelas internas,
serialização proprietária, scraping e automação administrativa são proibidos.
