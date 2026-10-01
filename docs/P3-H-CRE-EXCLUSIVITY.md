# P3-H — CRE exclusiva por inscrição

O ensaio físico P3 identificou que o Form 18, derivado do template sintético
Form 17, apresentava a mesma Turma simultaneamente nas CRES 04, 05 e 11.
Sem seleção exclusiva, uma Entry normal produziria três claims no mesmo
Resource. O Resource e a capacidade são únicos por Turma; as CREs são
representações alternativas, não três vagas por participante.

## Invariante

Uma inscrição sem campo de quantidade explícito consome **uma vaga**, mesmo
que a Turma seja oferecida em várias CREs. Quantidade explícita legítima
continua sendo somada pelo GP Inventory e preservada pelo agregador do Bridge.
Não deduplicar claims distintos por Resource para mascarar um Form inválido.

## Contrato do template multi-CRE

- Um único field `select` com `adminLabel=turmas_cre_selector`, `isRequired=true`
  e `placeholder` não vazio controla a CRE. Ele não possui GP Inventory nem
  Resource. Seus valores são códigos de CRE de dois dígitos.
- Cada field `turma_cre_XX` é um `select` obrigatório com `conditionalLogic`
  `show/all` de uma única regra: `fieldId` do controlador, `operator=is`,
  `value=XX`. O ID do controlador é descoberto pelo `adminLabel`, nunca fixado
  no código.
- Antes do clone, o preflight exige o controlador e as regras para toda
  Publication com mais de uma CRE. Falha com
  `turmas_bridge_cre_selection_invalid`, `pre_effect=true`.
- Após o clone, o mesmo contrato é verificado. Divergência após criação do
  Form é efeito parcial, mantém o Form ID no ledger e requer reconciliação.
- Na preparação, as opções do controlador passam a conter somente as CREs
  efetivamente representadas. O mesmo Resource continua vinculado a cada
  representação da Turma; nenhuma capacidade é multiplicada.

## Proteção de submissão

O Bridge registra `gform_pre_validation` e `gform_validation` na prioridade
zero, antes dos hooks de validação do GP Inventory 1.0.29 (prioridades 9/10/11
na versão validada). Para Forms marcados `turmasBridgeCreExclusive`, o Bridge
exige CRE válida e escolha de Turma correspondente. Valores enviados em outra
CRE tornam o envio inválido; os inputs de Turma são removidos do POST antes
da validação de inventário. No caminho válido, apenas o input da CRE escolhida
é mantido. Não há gravação de Entry pelo Bridge.

Forms históricos sem o marcador permanecem intactos. A regra nova protege
as materializações futuras e o Form P3 reparado após autorização operacional;
ela não reinterpreta Entries antigas. O teste C físico permanece separado:
nenhuma Entry é criada pelos testes automatizados deste patch.
