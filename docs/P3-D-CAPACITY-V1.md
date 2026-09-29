# P3-D — capacidade posterior à publicação, contrato conservador V1

Status: implementação offline para revisão humana. P3 físico NÃO executado.
Data de fechamento: 2026-09-29. Versões candidatas: EPF 0.14.0/schema 0.8.0;
Bridge 0.11.0/schema 0.9.0. Nenhuma dessas migrations foi aplicada ao LAB.

## Evidência histórica e limite desta rodada

P2 PHYSICAL CONCURRENCY = PASS, data 2026-09-28, conforme evidência
fornecida/aprovada pelo operador. Isso supera as notas históricas de P2
pendente, mas NÃO prova segurança de alteração online de capacidade.
A ameaça TOCTOU do GP Inventory permanece: validar disponibilidade e
persistir uma Entry não constitui uma transação global com o Bridge.

## Regra central

Vagas locais não são capacidade remota confirmada. A edição local mantém
motivo (1–2000 bytes), change_events, CAS row_version, notificação e transação.
Se já existe publicação materializada contendo a Turma, grava também uma
intenção PENDING. Falha no INSERT da intenção/auditoria causa rollback local.
CAS sem linha afetada não cria intenção. Exige InnoDB nas tabelas participantes
antes de alterar uma Turma com destino remoto. Não há envio automático.

A ação em Editar Vagas/Ciclos mostra Vagas locais, última capacidade observada,
versão de origem, estado/erro, e permite sincronização explícita. Usa a
capability existente edit_turmas_epf_vacancies, nonce de admin e escopo
territorial reaplicado no service. A capability não amplia território.
ADMIN com manage_turmas_epf tem visão global; demais usuários precisam da
capability de Vagas e do escopo. Não foram atribuídas novas capabilities.

V1 NÃO aplica capacidade se o Form estiver ativo. Não desativa nem reativa
Forms. A liberação operacional exige fechamento manual autorizado, drenagem
de submissões em andamento e ausência de editores/ativadores concorrentes.

## Wire contract independente

Namespace /wp-json/turmas-bridge/v1:

| Método | Rota | Efeito |
| --- | --- | --- |
| POST | /capacidades | Aplica sob gates; registra ledger |
| GET | /capacidades/{operation_key} | Lê resultado persistido, não reinspeciona inventário |
| POST | /capacidades/{operation_key}/reconciliation | Reinspeciona inventário; altera apenas ledger |

POST usa HMAC v2 existente, assinando método, rota, query canônica, timestamp,
nonce, Idempotency-Key e hash SHA256 do corpo. GET mantém HMAC v1. HTTPS e
política local existente não foram enfraquecidos. Cada requisição usa novo
nonce; replay de comando conserva a operation_key, NÃO o nonce de transporte.

Payload de chaves exatas: schema_version (string "1"), operation_key,
publication_key, class_key, expected_form_id, source_turma_id,
source_row_version, desired_capacity, reason. IDs/versão/capacidade são
inteiros JSON positivos até 2147483647. Formato publication YYYY:CODIGO;
class_key acrescenta :NN.NN. Capacidade zero não é permitida na V1.
Não envia snapshot completo, dados de inscritos ou credenciais no payload.

Identidade: "capacity-" + SHA256 da concatenação com "|" de publication_key,
class_key, expected_form_id, source_turma_id, source_row_version e
desired_capacity, nessa ordem. O hash do comando inclui também motivo.
Mesmo ID com motivo/payload distinto resulta em conflito. A origem é a
row_version da Turma após a edição, não a versão da publicação inicial.
O vetor sintético docs/fixtures/p3-capacity-contract.json é idêntico nos dois
repositórios e testado byte a byte. Seu material criptográfico é fictício.

Resposta 200 contém operation_key, publication_key, class_key,
source_row_version, desired_capacity, expected_form_id, state, attempts,
capacity_before/after, consumed_before/after, error_code e idempotent_replay.
Não retorna motivo, secrets ou dados de Entries. HTTP 200 inclui bloqueios de
negócio: nunca deve ser interpretado como aplicação sem conferir state.
422: contrato inválido; 409: identidade, lock ocupado, origem antiga ou
operação anterior inconclusiva; 404: operação ausente; 401: autenticação;
503: indisponibilidade/persistência/lock não confirmado. Políticas existentes
de transporte podem retornar 403. EPF trata timeout, 4xx/5xx e corpo inválido
como inconclusivos, sem retry automático. Não reaproveita POST /publicacoes.

## Estados e transições

| Estado | Semântica | Próxima ação |
| --- | --- | --- |
| PENDING | Intenção durável, sem convergência comprovada | Entrega explícita |
| BLOCKED_FORM_ACTIVE | Form ativo, nenhuma escrita de capacidade nessa tentativa | Fechar/drenar por procedimento separado e reenviar MESMO comando |
| BLOCKED_BELOW_CONSUMED | Desejado abaixo do consumo fresco, sem escrita nessa tentativa | Investigar; mesma intenção só passa se gate mudar legitimamente |
| APPLYING | Tentativa persistida antes do possível efeito | Interrupção exige reconciliação |
| APPLIED_VERIFIED | Observação integral concorda com o desejado | Replay retorna resultado histórico sem reaplicar |
| RECONCILIATION_REQUIRED | Drift ou efeito possível não confirmado | Inspeção explícita; NÃO retry cego |
| FAILED | Falha pré-efeito ou origem local superada | Terminal para entrega normal; investigar |

Os repositórios validam transições e CAS (estado + revision); não aceitam
retorno silencioso a PENDING, estado desconhecido ou APPLIED_VERIFIED -> APPLYING.
EPF: PENDING/bloqueados -> APPLYING ou FAILED; APPLYING -> bloqueado, verificado,
inconclusivo ou FAILED; inconclusivo -> APPLYING somente pela reconciliação.
APPLIED_VERIFIED e FAILED são terminais no workflow EPF.

Bridge: PENDING/bloqueados -> APPLYING, bloqueios, APPLIED_VERIFIED (no-op já
convergido), RECONCILIATION_REQUIRED ou FAILED; APPLYING -> bloqueios somente
quando o adapter atesta zero escrita, verificado ou inconclusivo. Inconclusivo
só permanece inconclusivo ou chega a verificado mediante reconciliação.
Replay normal de APPLIED_VERIFIED/FAILED não executa adapter. Reconciliação
explícita de APPLIED_VERIFIED pode registrar drift; não reaplica. A inspeção
de FAILED pode atualizar diagnóstico, nunca executar escrita.

Replay é evidência histórica, não garantia do inventário neste instante.
Após uma operação mais nova, a reconciliação de uma antiga é recusada por
SOURCE_VERSION_CONFLICT. A UI não apresenta replay histórico como nova edição.
Uma origem local alterada após a intenção é recusada como SOURCE_SUPERSEDED;
não se recicla identidade antiga para capacidade nova.

## Persistência, locks e verificação

Ledgers próprios, sem reutilizar PublishOperation/ActivationOperation:
wp_turmas_epf_capacity_operations e wp_turmas_bridge_capacity_operations
(prefixo real via wpdb). Chave de operação única; EPF único por
(publication_id,turma_id,source_row_version); Bridge único por
(class_key,source_row_version). Defaults attempts=0/revision=1, estado inicial
PENDING inserido pelo código, timestamps UTC obrigatórios. Observações antes/
depois podem ser NULL até verificadas. Comando/motivo imutáveis, histórico de
transições acumulado e CAS em toda atualização; histórico inválido falha fechado.

EPF usa o mesmo named lock tepf_capacity_{turma_id} tanto na edição de
Vagas quanto na entrega/materialização. A entrega bloqueia IDs em ordem
numérica estável, relê a seleção sob as travas e libera em ordem inversa.
Na edição, targets() é consultado depois do CAS, dentro da transação local;
assim uma materialização concorrente não pode tornar a Turma elegível entre
a descoberta de destinos e o commit. CAS continua sendo a última defesa de
row_version; mudança de seleção observada antes do envio retorna conflito.
Bridge usa lock de choices por publicação, depois lock por class_key,
liberando na ordem inversa em finally. Assim duas classes do mesmo Form
materializado não sobrescrevem o documento completo cooperativamente.
GET_LOCK usa timeout de 10 segundos. Falha de liberação é reportada como
inconclusiva no transporte; o ledger preserva o resultado para consulta/
reconciliação. Lock não substitui unique keys ou CAS.

O adapter exige mapping HEALTHY, materialização MATERIALIZED, Form esperado,
Resource existente com marcadores Bridge de propriedade da class_key,
representações select idênticas ao expected_representations_json persistido
no mapping quando a materialização ficou saudável e bindings exatamente iguais.
O conjunto esperado não é reconstruído do Form atual: mapping antigo sem essa
referência, campo/choice ausente, representação adicional ou binding incorreto
falha fechado para RECONCILIATION_REQUIRED. A referência inclui CRE, field ID
e choice value; é mantida no banco e sobrevive a restart do processo.

### Fonte de verdade do consumo compartilhado

O pacote fonte GP Inventory 1.0.29 foi lido do ZIP local autorizado, sem
instalação nem execução WordPress. Em
gp-inventory/includes/class-inventory-type-advanced.php,
get_resource_fields() (linha 206) resolve os fields listados em gpi_field;
resource_and_properties() (linha 323) constrói escopo por Resource sobre
esses fields. Porém, em
gp-inventory/includes/class-inventory-type-choices.php,
get_choice_count() (linha 361) delega a uma consulta do field; o modificador
modify_query_select_for_choices() (linha 389) usa SUM(...) de meta rows,
e get_choice_counts() (linha 530) agrega resultados de meta, não IDs únicos
de Entry. Isso não prova a contagem única do conjunto lógico.

Por isso a fonte de verdade do Bridge é uma leitura SQL síncrona/fresca baseada
na consulta GP Inventory gerada separadamente para cada representação
persistida: mantém os query hooks do vendor (incluindo status de Entry,
exclusão de partial entries e escopo Advanced Resource), mas troca apenas a
projeção/agregação para obter entry_id + class_choice; a união é deduplicada
por Entry ID entre todas as representações esperadas e conta apenas o
choice_value exato da class_key. A cache de choice counts é limpa, mas não
é usada como resultado. Resultado inválido, erro SQL, runtime sem os métodos
esperados ou referência estrutural divergente nunca vira zero e nunca pode
produzir APPLIED_VERIFIED. Essa consulta é uma integração com internals
1.0.29, não uma API pública suportada; uma atualização do plugin exige nova
inspeção de código e teste.

GFAPI::update_form precisa confirmar true. update_post_meta pode retornar
false para valor já existente; sua releitura precisa ser igual ao desejado.
Não cria ou altera mapping/bindings/Entries. Após escrita, compara capacidades,
consumo, Resource, bindings e Form. Divergência/erro => reconciliação.
Se a gravação final no ledger falhar, APPLYING permanece recuperável por
reconciliação; não há sucesso antecipado ou rollback destrutivo.

Reconciliação nunca escreve Form/Resource/Entry: compara o estado observado
com a intenção. Só APPLIED_VERIFIED quando tudo concorda e o Form está
inativo; drift e efeito anterior incerto permanecem inconclusivos. Operação
não recebida pelo Bridge (404 após timeout) exige diagnóstico humano; V1 não
inventa confirmação nem reenvia automaticamente.

## Upgrade e evidência de testes

Upgrade EPF 0.7.0 -> 0.8.0 e Bridge 0.8.0 -> 0.9.0 é aditivo; no Bridge,
dbDelta também adiciona expected_representations_json à tabela de mapping
para futuras materializações. Não faz backfill: mappings legados sem conjunto
esperado falham fechado e precisam de reconciliação/repreparação explicitamente
aprovada. Repetição é idempotente; a versão só avança após validar colunas e
definição (tipo/comprimento/unsigned, nullability, default, extra),
índices/ordem/uniqueness e engine InnoDB do ledger. Falha mantém versão antiga.

Testes de schema usam doubles de wpdb/dbDelta, inspecionam DDL, defaults,
nullability, índices, upgrade repetido, ausência de DML/ALTER legado na etapa
nova e falhas de instalação. NÃO demonstram execução física do dbDelta/MySQL,
preservação física de linhas nem reboot. Isso é gate futuro explícito.
A suíte também cobre assinatura/identidade, bloqueios, persistência/CAS,
fault injection depois de GF/meta, consumo mutável, replay e reconciliação.

## Limitações residuais e revisão humana

- Não existe transação distribuída GF + postmeta + ledger.
- Named locks só coordenam participantes Bridge que os utilizam. Não protegem
  submissões GF, plugins, administrador GF ou SQL fora desse protocolo.
- Um Form inativo pode ter submissões já em voo. Uma ativação/edição externa
  entre leituras ainda é race residual. Leituras pré/pós detectam parte dos
  casos; não provam que o Form ficou inativo em todos os instantes.
- Fresh significa invalidar caches conhecidos do GP/postmeta, não isolamento
  serializável da base nem garantia contra cache/runtime de versão incompatível.
- Expectativa estrutural é persistida no mapping durante a materialização
  saudável e comparada ao Form/bindings observados. Alteração manual simultânea
  do Form e do próprio mapping permanece fora do modelo de adulteração.
- Mudanças de Vagas concorrentes com entrega, ou materialização ainda em voo,
  precisam de disciplina operacional. O lock de entrega não bloqueia edição
  local nem substitui a revalidação; uma intenção mais nova continua pendente.
- O V1 não implementa compensação, desativação/reativação, conserto de bindings,
  retry automático, resolução administrativa de estado incerto ou UI sofisticada.
- Não se declara deploy, integração main, push, P3 físico ou prontidão produtiva.

Os sete runbooks em docs/p3 do TURMAS-BRIDGE definem o gate futuro. Antes dele:
revisão humana dos commits, autorização de atualização/migration, backup,
runtime compatível e fixture nova fora da lista protegida. Nada físico é
autorizado pela mera existência destes documentos.
