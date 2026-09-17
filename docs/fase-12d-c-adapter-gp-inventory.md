# Fase 12D-C — Adapter real de GP Inventory

## Objetivo e limite

A implementação foi validada no laboratório com Gravity Forms 2.10.0,
Gravity Perks Framework 3.0.30 e **GP Inventory 1.0.29**. Essas versões são
evidência do laboratório, não uma garantia de compatibilidade futura.

Esta fase configura capacidade compartilhada para uma Turma lógica no
Gravity Forms. Um `class_key` do Bridge mapeia para exatamente um post
`gpi_resource`; várias representações CRE podem apontar ao mesmo Resource.
O mapeamento pertence ao Bridge em `*_turmas_bridge_inventory_resources`.
`class_key` não é uma identidade nativa do GP Inventory.

O adapter é uma camada anticorrupção: somente `src/Inventory/` conhece
`gpi_resource`, `gpi_field`, `gpiResource`, `gpiInventory` e
`choice.inventory_limit`. Esses detalhes são compatibilidade observada na
versão homologada do plugin, não API pública garantida pelo fornecedor.

## Ciclo seguro

1. Localiza o mapeamento por `class_key` e obtém lock MySQL determinístico.
2. Cria o Resource somente se não houver mapeamento; um Resource mapeado e
   ausente nunca é recriado silenciosamente.
3. Lê o formulário materializado, que deve estar inativo, e inspeciona todas
   as representações CRE, bindings e limites.
4. Lê `consumed` com limpeza do cache de choices antes de reduzir capacidade.
5. Se `desired_capacity < observed_consumed`, falha sem alterar fields,
   Resource ou mapeamento saudável.
6. Reconciliam-se `gpiResource`, `gpiInventory=advanced`, bindings `gpi_field`
   e `choice.inventory_limit` de cada representação; só então o mapeamento
   fica `HEALTHY`.

O meta do Resource é escrito somente como observabilidade. Para `select`, a
fonte efetiva da capacidade é `choice.inventory_limit` em cada field.

## Identidade técnica e recuperação

Resources criados pelo Bridge recebem `turmas_bridge_managed=1` e
`turmas_bridge_class_key=<class_key>`. A recuperação consulta exclusivamente
esses metadados e exige um único candidato; o título `[Turmas Bridge] …` é
apenas apresentação. Resources históricos sem esses marcadores não são
adotados por título e exigem reconciliação manual controlada.

## Duplicate form, drift e falhas parciais

`GFAPI::duplicate_form()` pode copiar `gpiResource` sem registrar os novos
bindings `gpi_field`. O adapter detecta o binding ausente e o recria pela
atualização do formulário e hook do GP Inventory. Bindings de formulários
anteriores são preservados: removê-los poderia excluir Entries históricas do
cálculo compartilhado.

A suite padrão contém um contract test que reproduz o invariant observado:
`duplicate_form` copia `gpiResource`, mas o binding `gpi_field` do formulário
novo está ausente. O teste comprova estado não saudável, reconciliação sem novo
Resource e replay sem binding duplicado. Plugins proprietários não são
copiados para o CI; a semântica real permanece validada no laboratório local.

Resource ausente, field/choice ausente, capacidade divergente, binding ausente
ou extra no formulário atual são drift. Bindings ausentes e limites divergentes
podem ser reconciliados automaticamente; divergência semântica de Resource,
choice ou representação exige `RECONCILIATION_REQUIRED` para não reinterpretar
Entries existentes. Como Gravity Forms e post meta não possuem uma transação
única, uma falha intermediária também exige reconciliação explícita.

## Concorrência e risco residual

O lock do Bridge serializa apenas sua própria orquestração por `class_key`.
Ele não serializa submissões diretas do Gravity Forms. Há uma janela residual
entre a leitura fresh de `consumed`, a escrita e novas Entries. Por isso o
adapter bloqueia reduções abaixo do consumo observado e não promete atomicidade
com inscrições externas. Operações de redução devem ser feitas com formulário
inativo e revisão operacional.

## Validação local

Em laboratório local, o fixture fictício `2099:ADAPTER:01.01` foi usado com
capacidade 5 e CRE 01/02/03. A validação confirmou um único Resource, três
`inventory_limit=5`, replay sem novo Resource, consumo compartilhado e bloqueio
de redução abaixo do consumo. Nenhum dado institucional integra esse teste.

## Checklist de upgrade

- confirmar versões compatíveis de Gravity Forms, Gravity Perks e GP Inventory;
- executar testes unitários e uma fixture fictícia inativa;
- conferir Resource, `gpi_field` e os três limites por CRE;
- testar replay, drift e redução abaixo do consumo;
- manter secret HMAC e dados submetidos fora do repositório;
- não tratar estes internals como contrato público sem nova homologação.
