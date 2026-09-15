# Fase 12D-A — choices por CRE

Esta fase converte cada Turma do payload v1 em choices nos fields do formulário
materializado. O contrato de template exige `adminLabel` exato
`turma_cre_XX`; labels visíveis não identificam CRES. Cada CRE necessária deve
ter exatamente um field `select`, `radio` ou `checkbox`. A homologação desse
contrato contra um template Gravity Forms real continua pendente.

`choice.value` é sempre `class_key`; `choice.text` é exatamente `short_name`.
Turmas com várias CRES repetem o mesmo value nos fields correspondentes. Dentro
de cada field, choices são ordenadas naturalmente por `class_code`. Fields CRE
sem Turmas recebem `choices: []`, removendo choices herdadas do template. Outros
fields, labels, required e conditional logic não são alterados.

Antes de atualizar, o Bridge resolve todos os fields, valida CRES, duplicidade,
topologia e Resource Plans. O plano em memória contém uma entrada por
`class_key`, uma única `capacity` positiva e múltiplas representations
`{cre, field_id, choice_value}`. Nenhum Resource, Inventory, consumo ou vaga
restante é criado ou consultado.

O hash `choices_fingerprint` representa apenas o estado desejado das choices e
é separado do hash do payload. O lock consultivo por `publication_key` e a
atualização convergente evitam writes concorrentes cegos. O formulário deve já
estar inativo; a atualização preserva essa propriedade. Falhas prévias não
alteram o formulário; falha na API deixa o fingerprint inalterado para retry.

Pendência da 12D-B: vincular as representations ao GP Inventory Advanced
Resource compartilhado, sem multiplicar a capacidade da Turma.

## Estado de homologação — 12D-B0.3

- **12D-A:** concluída.
- **12D-B0:** homologação parcial.
- **12D-B0.3:** bloqueada por dependência externa.
- **12D-B:** bloqueada; o mecanismo de escrita ainda não foi homologado.

O bloqueador é a ausência de um ambiente local ou descartável, oficialmente
autorizado, com Gravity Forms e GP Inventory. Sem ele, não é possível homologar
com segurança a persistência de Resources, limites de choices, associação
Resource → field ou escrita programática de capacidade.

Evidências preservadas da homologação institucional: as representations CRE
01, 02 e 03 do Form 297 usam Advanced Inventory, o mesmo Resource fictício e o
mesmo `choice.value`. Ainda há uma divergência de limites persistidos que não
deve ser corrigida nessa instância até que o mecanismo de escrita seja
validado no laboratório autorizado.

Retomar a 12D-B somente após disponibilizar: (a) instalação oficial/licenciada
para desenvolvimento local; (b) ambiente institucional descartável já
licenciado; ou (c) licença específica de desenvolvimento. A retomada começa
pelos testes de persistência Resource/field/choice/limit, atualização
programática equivalente à interface, leitura de limit/count/available,
consumo compartilhado, redução de capacidade e reconciliação.

O modelo abaixo é uma **hipótese de integração**, não o contrato definitivo da
12D-B: identidade TURMAS-EPF `publication_key + class_key`; mapeamento Bridge
para `resource_id`; pool potencial `resource_id + choice.value`; e estado
`limit`, `count` e `available`. A hipótese só pode ser promovida após o
laboratório autorizado comprovar persistência, escrita e consumo compartilhado.

Não são alternativas aceitas para superar o bloqueio: copiar plugin, licença ou
banco da produção; incorporar código proprietário; SQL direto como API;
sincronizar inventories independentes; ou calcular consumo institucional pela
leitura manual de Entries.
