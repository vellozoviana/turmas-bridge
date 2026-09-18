# Fase 12E — fechamento seguro do pipeline local

## Gap identificado

Após a Fase 12D-C o Bridge já recebia uma Publicação, materializava um
formulário inativo, preparava choices e reconciliava Resources compartilhados.
Faltava uma leitura autenticada e sem efeitos colaterais que permitisse
confirmar o estado efetivo da materialização. `MATERIALIZED` não é sinônimo de
`READY` ou `PUBLISHED`.

## Entrega local

Foi adicionado `GET /turmas-bridge/v1/publicacoes/{publication_key}`. O
endpoint usa a mesma autenticação HMAC/nonce, aceita somente chaves válidas e
retorna estado persistido, `form_id` quando conhecido, estado efetivo do
formulário (`inactive`, `active`, `missing` ou `not_materialized`), um estado
efetivo conservador e erro sanitizado/data de atualização.

O leitor não cria formulário, Resource, choice, Entry ou binding. Formulário
ativo ou ausente é reportado como `RECONCILIATION_REQUIRED`; não há promoção
automática para `PUBLISHED`.

## Pipeline efetivo

| Etapa | Antes | Depois |
|---|---|---|
| HMAC + idempotência | Implementada | Mantida |
| Materialização GF inativa | Implementada | Relida no status |
| Choices por CRE | Implementada | Mantida |
| Resource Advanced compartilhado | Implementada | Mantida |
| Leitura operacional do estado | Parcial | Implementada (somente leitura) |
| Ativação/publicação pública | Não implementada | Fora do escopo |
| FlowSheet/InscriHub | Pendente | Não acessados |

## Limites

O TURMAS-EPF possui ação administrativa restrita a ADMIN/Gabinete que dispara
uma entrega autenticada e idempotente ao Bridge, além de consulta de status
somente leitura. A ação valida escopo e registra o `gravity_form_id` retornado
na publicação local; nenhum contrato externo foi alterado. A entrega continua
técnica e exige homologação local separada antes de qualquer ativação pública.

Durante a homologação local foi detectado que instalações antigas podiam não
possuir a coluna `choices_fingerprint`, embora ela já fizesse parte do contrato
do schema. A versão `0.6.1` mantém todas as tabelas e usa `dbDelta` para
adicionar a coluna sem recriar ou remover dados.
FlowSheet, InscriHub, ativação e publicação institucional continuam futuros.

Laboratório de referência: PHP 8.3.33, WordPress 7.1, Gravity Forms 2.10.0,
Gravity Perks Framework 3.0.30 e GP Inventory 1.0.29. Nenhum fixture
institucional foi acessado.
