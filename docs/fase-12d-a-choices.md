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
