# P3-F — preflight da materialização

## Ordem e fronteira de efeito

`POST /turmas-bridge/v1/publicacoes` passa pela autenticação HMAC do
`Bridge_Controller`, valida o payload e reserva a chave em
`Idempotency_Repository`. `Publication_Controller` inicia `MATERIALIZING` e
chama `Materialization_Service`. O serviço lê o mapping por `publication_key`,
obtém o único template configurado, carrega o Form via GFAPI e executa o
`Template_Preflight` **antes** de reservar o mapping e chamar
`GFAPI::duplicate_form()`. A duplicação é o primeiro efeito no Gravity Forms.
Depois vêm a persistência do Form, a atualização de choices e, por último,
Resources/bindings/expected representations no adapter de inventário. O EPF
só grava `gravity_form_id` após receber e validar a resposta completa.

O preflight usa o mesmo `Template_Field_Map` e `Resource_Plan_Builder` das
etapas posteriores. Ele verifica campos gerenciados únicos, tipos compatíveis,
CREs exigidas, plano de Resource e disponibilidade/versão do runtime GP
Inventory. CREs ausentes são devolvidas ordenadas em `missing_cres`, com
`turmas_bridge_cre_field_missing` e `pre_effect=true`. O controller retorna
o comando a `RESERVED`; uma configuração corrigida pode ser tentada de novo
com a mesma identidade. Nenhum Form, Resource ou mapping de materialização é
criado nessa falha. A reserva do comando de idempotência é um registro interno,
não um efeito externo.

Falhas após clone ou resultado incerto exigem reconciliação; não apagam o
Form/Resource. Mapping `RECEIVED` sem Form, `FAILED` ou `MATERIALIZED` sem
fingerprint de choices bloqueia replay cego. Payload diferente para a mesma
`publication_key` também é rejeitado. Uma operação em `MATERIALIZING` ou
`RECONCILIATION_REQUIRED` bloqueia outra chave para a mesma Publicação.
A unicidade do mapping limita clones
concorrentes; não há alegação de transação atômica entre GFAPI e MySQL. Uma
mudança do template entre preflight e clone ainda é possível; o Form clonado
é revalidado, e divergência pós-clone é tratada como efeito parcial, nunca
como falha segura pré-efeito.

## Configuração do template

O template é selecionado globalmente por `TURMAS_BRIDGE_TEMPLATE_ID` ou pela
opção protegida `turmas_bridge_template_id`. Não há roteamento por Área,
Formação ou território, nem cadastro mestre de múltiplos templates. Logo, o
template configurado precisa cobrir as CREs de toda Publicação que será
materializada nesse ambiente, embora campos extras benignos sejam aceitos.
Adicionar campos dinamicamente não faz parte do contrato atual. O Form 11 do
LAB só contém CRE 01/02 e não serve à fixture 2094:E2F, que usa 04/05/11.
Não há evidência nesta fase sobre quais CREs o template institucional de
produção suporta; isso requer revisão humana da configuração, sem acesso a
produção nesta etapa.

## Retomada controlada do P3 físico

1. Manter LAB congelado até merge, atualização aprovada e nova autorização.
2. Ler sem mutação o Form 16, Publication 6, idempotency 38, materialization
   11, mappings, Resources e Entries; confirmar Form inativo e ausência de
   novos efeitos. Preservar P1/P2 e comparar seus baselines.
3. Decidir explicitamente entre preservar, reconciliar ou remover o Form 16.
   Não presumir que exclusão é segura. Não há endpoint de reconciliação de
   materialização equivalente ao de ativação; qualquer recuperação exige
   desenho e autorização próprios.
4. Escolher ou configurar um template compatível com CRE 04/05/11. Como há
   apenas um template global, avaliar impacto sobre P1/P2 antes de alterá-lo.
5. Decidir se Publication 6/Turma 25 podem ser reutilizadas sem contornar o
   ledger `RECONCILIATION_REQUIRED`; caso contrário, planejar fixture nova.
6. Somente após resolução documentada do efeito parcial, retomar a
   materialização pelo fluxo oficial e os testes A–H de capacidade do P3.

Nenhuma destas ações físicas foi executada na P3-F.
