# Pré-Fase 12 — contrato de publicação (registro histórico)

Este documento consolidou as decisões que orientaram as Fases 12A, 12B, 12C e
12D-A. A fundação de publicação, o endpoint autenticado, a materialização
inativa e choices por CRE agora existem localmente. As seções que tratam de
Advanced Resources, reconciliação operacional, FlowSheet e InscriHub continuam
propostas para fases futuras; a Fase 12D-B permanece bloqueada até laboratório
autorizado.

## Identidade externa da Turma

O modelo proposto é `ano_letivo:codigo_formacao:codigo_turma`, por exemplo
`2027:MT1:01.01`. Ele é legível e corresponde à chave lógica atual da Turma
quando `codigo_formacao` representa de forma imutável a mesma Formação de
`formacao_id`.

Hoje o banco garante `UNIQUE(ano_letivo, formacao_id, codigo_turma)` e também
garante unicidade global de `formacoes.codigo`. Entretanto, o cadastro mestre
permite alterar esse código e não restringe seus caracteres. Portanto, a
identidade proposta precisa da seguinte regra antes da primeira publicação:
`codigo_formacao` não contém `:` e, após uma publicação que o use, não pode
ser alterado. Nome e descrição da Formação continuam rótulos humanos editáveis.

O formato recomendado é maiúsculas, dígitos, `_` e `-`, sem separador `:`.
Isso não exige UUID nem migration. A verificação de dados já existentes deve
ocorrer antes da primeira publicação.

Esta identidade não altera `codigo_turma`, Nome curto nem Nome completo. O
Nome curto continua `{CODIGO} {TURMA} {SEMANA} {TURNO}` e é usado pelo
TURMAS-EPF, telas e exportações. Em Gravity Forms, `choice.text` será o texto
humano e `choice.value` será a identidade externa estável.

## Unidade, idempotência e mapeamento

A publicação é a unidade `ano_letivo + formacao_id`, contendo somente Turmas
explicitamente escolhidas pelo Gabinete e em estado `COMPLETA`. Não exige que
todas as Turmas da Formação estejam completas.

O contrato futuro deve manter uma publicação por essa chave, com o `form_id`
remoto e um marcador de versão. Reprocessar primeiro localiza essa publicação
antes de criar um formulário. Para sobreviver a uma falha entre criação remota
e gravação local, o formulário também precisará de um marcador técnico
pesquisável; o carrier concreto no Gravity Forms deve ser validado na Fase 12.

O mapeamento por CRE pertence ao agregado de publicação no TURMAS-EPF, pois é
configuração operacional e auditável da publicação. O contrato mínimo é:

```text
publication_id + cres_id -> gravity_form_id + gravity_field_id
                            + field_admin_label + manifest_fingerprint
```

`field_id` não é global: 195 é somente uma evidência do formulário-modelo e
nunca uma constante. O bridge valida a estrutura do formulário por manifesto;
o TURMAS-EPF é a fonte do mapeamento aprovado.

## Um campo por CRE e capacidade compartilhada

Preservar um campo de Turma por CRE é compatível com o padrão institucional e
simplifica a lista de choices: cada campo mostra somente Turmas da CRE.
Quando houver várias Turmas, o campo lista várias choices. Quando não houver
nenhuma, o campo não pode ser obrigatório nem ficar visível; a lógica
condicional deve ocultá-lo antes da validação.

Uma Área pode ter várias CRES (`area_cres`) e a Turma armazena somente
`area_id`. A mesma Turma pode, portanto, aparecer em todos os campos das CRES
derivadas, sempre com o mesmo `choice.value`. Isto continua sendo uma única
Turma, não várias Turmas.

Essa repetição só é segura com GP Inventory em modo **Advanced**, usando o
mesmo **Resource** nos campos espelhados. A documentação oficial confirma que
fields de choice ligados ao mesmo Resource compartilham o limite quando o
`choice.value` e a coluna `Inv.` são iguais. Assim, uma inscrição em qualquer
CRE consome o mesmo pool da Turma. O formulário não pode usar Simple Inventory
nesses campos, pois seu limite é local ao field/choice e multiplicaria vagas.

Para cada Turma replicada, a publicação deve manter em todas as cópias o mesmo
valor externo e o mesmo limite. A identificação funcional do pool passa a ser
`resource + choice.value` (e scopes, se um dia forem adotados), não
`form_id + field_id` isoladamente. O controlador da lógica condicional do
formulário ainda precisa ser identificado e aprovado; não pode ser inferido do
label visível.

## GP Inventory 1.0.29

O formulário institucional utiliza inventário por choice. A propriedade
estrutural usada pelo addon no campo é `gpiInventory`; os choices guardam o
limite em `inventory_limit`. O manifesto do bridge expõe somente a presença
desse recurso, nunca os valores.

No modo Simple, o limite é local ao field/choice. No modo Advanced, o Resource
é o pool oficialmente suportado para compartilhamento entre fields e formulários.
Para choices espelhadas, o fornecedor exige o mesmo Resource, `choice.value` e
limite. O snippet "Shared Choices" não resolve este caso: ele só compartilha
choices dentro de um único field e não deve ser usado como substituto do
Resource Advanced.

O contrato de autoridade é compatível:

- TURMAS-EPF: Turma, dados operacionais e `vagas` como capacidade total
  autorizada.
- Gravity Forms/GP Inventory: entries, consumo e disponibilidade resultante.
- TURMAS-BRIDGE: tradução autenticada entre os dois, sem banco compartilhado.

Na futura sincronização, o bridge deve exigir Advanced Resource e ler a
configuração e o consumo com APIs do Gravity Forms/GP Inventory, calcular ou
solicitar `limit`, `claimed` e `available`, e confirmar o resultado após a
atualização. Não deve consultar tabelas diretamente nem manter consumo próprio.
A API PHP exata para provisionar Resource e vincular fields deve ser validada
em fixture não produtiva da versão 1.0.29 antes de escrita automatizada.

Aumentar `vagas` de 30 para 35 deve alterar apenas o limite para 35, preservando
as entries já contadas. Reduzir deve ler novamente o consumo imediatamente
antes da atualização e bloquear com erro de negócio quando `novo_limite <
consumidas`. Não cancela entry, não produz saldo negativo e não sobrescreve o
inventário silenciosamente.

A consulta e a atualização do limite não são uma transação única com o envio de
uma inscrição. Por isso a Fase 12 precisa de precondição de versão/limite e
lock consultivo curto por `form_id + field_id`, além de nova leitura antes e
depois da escrita. A equipe deve aprovar se essa proteção é suficiente ou se
exigirá um hook adicional no fluxo de submissão para serialização forte.

## Alterações após publicação

A identidade de choice não muda quando muda o texto. A publicação deve ser
marcada como desatualizada quando qualquer dado publicado mudar:

- `vagas` (sincronizar limite do GP Inventory);
- Local ou Endereço operacional (sincronizar `choice.text`);
- ciclos, se o formulário publicado os exibir;
- qualquer componente do Nome completo que altere o texto apresentado.

Código de Turma, Semana e Formação ficam bloqueados no fluxo normal. Alteração
de código de Formação é justamente a pendência de estabilidade acima. A decisão
sobre exibir ciclos deve constar no contrato do template; enquanto isso, tratá-
los como potencialmente desatualizadores é a alternativa conservadora.

## FlowSheet e InscriHub

A inspeção read-only mostrou que FlowSheet é uma integração própria: uma
conexão associa um formulário a uma planilha/aba e cria uma linha quando a
inscrição é submetida. Não é suficiente concluir que qualquer configuração
"Google Sheets" do Gravity Forms seja FlowSheet. Para uma publicação futura,
a conexão deverá receber ao menos `form_id`, conta autorizada, destino
planilha/aba, nome e regras de identificação/colunas. A estrutura detalhada,
sem abrir planilhas, continua pendente de aprovação específica.

No pacote InscriHub 1.7.5 disponível para análise, a classe
`InscriHub_Form_Config` oferece `get_by_form_id`, `update_one` e
`sync_gravity_form`. O contrato futuro deve usar `form_id`, campo CPF/identificador
aprovado, estado ativo e permissões de visualizar/editar/excluir; reprocessar
deve localizar a configuração existente por `form_id`, não duplicá-la. Nenhuma
configuração foi lida, criada ou alterada.

## Precondições da Fase 12

1. Implementar a validação/imutabilidade de `formacoes.codigo` para publicações.
2. Usar Advanced Resource compartilhado; proibir Simple Inventory para choices
   espelhadas entre CRES.
3. Identificar e aprovar o controlador da lógica condicional por CRE.
4. Aprovar a estratégia de concorrência para redução de capacidade abaixo do
   consumo, incluindo o nível de garantia necessário.
5. Definir se ciclos serão mostrados no formulário publicado.
6. Aprovar os detalhes de FlowSheet e de InscriHub em escopos próprios.
