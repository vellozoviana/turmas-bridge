# P3-D — revisão final para aprovação humana

Data: 2026-09-29. Branch em ambos os repositórios: feature/p3-capacity-v1.
Escopo: fonte, testes e documentação offline. SEM homologação física.

## A. Status final

P3-D concluída no escopo de desenvolvimento e pronta para revisão humana.
Não significa migration física aprovada, deploy ou prontidão produtiva.

## B–E. Contrato, arquitetura e mudanças

EPF mantém edição local auditável/CAS/notificação, acrescenta outbox de
capacidade na mesma transação, cliente HMAC dedicado, ação administrativa
protegida e indicação de convergência pendente.
Bridge acrescenta comando/controlador, ledger, máquina de estados, adapter
de capacidade, inspeção pós-escrita e reconciliação sem mutar inventário.
Form ativo bloqueia escrita; desejado abaixo de consumed fresco bloqueia;
falha parcial/resultado incerto não permite reaplicação cega.

Foram corrigidas também três lacunas concretas na revisão:
- validação de transições para impedir regressões de estado nos repositórios;
- leitura de consumed inválido não pode virar zero silenciosamente;
- confirmação de liberação dos locks e tratamento de falha sem devolver
  sucesso HTTP enganoso. Mudanças atingem os helpers compartilhados de
  inventário/materialização do Bridge e foram submetidas à regressão completa.

Correções após a revisão adversarial adicional:
- consumo preserva quantity > 1 conforme GP Inventory 1.0.29 e deduplica pelo
  Entry ID entre representações; linhas/quantidades malformadas ou divergentes
  falham fechado;
- allowlist explícita do adapter limitada ao GP Inventory 1.0.29;
- transações EPF revertem diante de qualquer Throwable;
- prepare/delivery EPF compartilham lock lógico de Publicação antes dos locks
  ordenados de Turma;
- retry Bridge autenticado somente para FAILED/INSPECTION_FAILED pré-escrita;
- hooks sempre recebem cleanup, índices extras benignos são aceitos e testes
  cobrem falha de integridade de linhas do vendor.

## F. Schema

EPF plugin 0.13.4 -> 0.14.0; schema 0.7.0 -> 0.8.0.
Bridge plugin 0.10.1 -> 0.11.0; schema 0.8.0 -> 0.9.0.
Ledgers InnoDB aditivos; upgrade imediato não emite DML/ALTER nas tabelas
legadas. Gate de versão exige engine, colunas e índices esperados.
Os testes usam doubles wpdb/dbDelta: preservação física de linhas, instalação
MySQL real e restart serão verificados somente no futuro gate autorizado.

## G–H. Endpoints e autenticação

POST /turmas-bridge/v1/capacidades
GET /turmas-bridge/v1/capacidades/{operation_key}
POST /turmas-bridge/v1/capacidades/{operation_key}/reconciliation
POST /turmas-bridge/v1/capacidades/{operation_key}/retry (FAILED pré-escrita somente)

HMAC v2 nos POSTs, v1 no GET, sem downgrade. Idempotency-Key vinculado à
assinatura. Vetor de contrato idêntico e fictício em ambos os repositórios.
Admin: capability edit_turmas_epf_vacancies, nonce e escopo no backend.
O endpoint é da integração autenticada entre sites; não aceita identidade
territorial informada por cliente web como autorização.

## I–L. Estados, idempotência, concorrência e recuperação

Detalhes e tabela de transições: P3-D-CAPACITY-V1.md.
Comando aplicado retorna resultado persistido, sem escrita duplicada.
Payload divergente conflita. APPLYING interrompido/inconclusivo exige inspeção
explícita. Form bloqueado pode ser fechado operacionalmente e receber o
mesmo comando novamente. Nenhum mecanismo automático fecha/reabre Forms.
EPF usa lock por Turma nas entregas; Bridge publicação -> class_key, CAS e
unique keys. Esses locks NÃO protegem submissões diretas do Gravity Forms.
Reconciliação não cria/repara Form, Resource, binding ou Entry.

## M–N. Testes e qualidade finais

| Gate | EPF | Bridge |
| --- | --- | --- |
| PHPUnit completo | 296 / 1348 assertions | 306 / 1268 assertions |
| lint | PASS | PASS |
| PHPStan | PASS | PASS |
| diff-check | PASS | PASS |

Cobertura adicionada: CAS/rollback de intenção, auditoria, assinatura/headers,
rota/payload byte a byte, identidade conflitante, 401/409/422/500/503, timeout,
resposta inválida, scopes, nonce/capability, Form ativo, equal/below consumed,
Resource ownership, cache flush, leitura inválida de consumed, falha meta após
GF, divergência pós-meta, choice/binding/consumo/Form divergentes, falha após
efeito, persistência final ausente, replay, reconciliação, locks cooperativos
inclusive classes diferentes no mesmo Form e falha de liberação.
Sem testes removidos ou cobertura reduzida. Aviso informativo de versão
antiga do PHPStan não foi tratado com atualização de dependências nesta fase.

## O–P. Documentação e futuro físico

READMEs, matriz de versões, contrato P3-D, vetor sintético e esta revisão.
Nota histórica localizada em docs/fase-12f-astra-hardening-review.md do EPF
preservada e marcada como superada: P2 PHYSICAL CONCURRENCY = PASS em
2026-09-28, conforme evidência do operador; não foi repetida nesta rodada.
Os sete documentos obrigatórios estão em docs/p3 no TURMAS-BRIDGE.
Cobrem A ativo, B inativo, C below, D replay, E divergência, F restart,
G integridade. Nenhuma fixture foi criada.

## Q. Segurança

Revisão das novas rotas/admin/SQL/payloads: prepared statements para valores,
prefixos internos, escape de UI, autorização no backend, nonce, HMAC existente,
sem acesso a Entries, sem logs de credenciais, sem compensação destrutiva,
sem sucesso antes de pós-verificação e persistência. Vetores/testes usam
apenas dados sintéticos. Nenhuma credencial operacional lida ou introduzida.
Sem dumps, traces físicos, vendor, cache ou artefatos operacionais no commit.

## R. Git

Um commit local por repositório; hashes finais fornecidos no relatório de
entrega e consultáveis em git log. Sem integração main ou push.
HEAD anterior EPF: 864e561ad577f0605f5baee949bb8cc64658e9aa.
HEAD anterior Bridge: 0733b361ee7e42dc79a1fc1ea2d5fe710be1573b.
Os checkouts originais e seus documentos não rastreados preexistentes foram
preservados. Trabalho em p3-d/TURMAS-EPF e p3-d/TURMAS-BRIDGE.

## S. Limitações residuais

Sem transação distribuída ou garantia de capacidade online atômica.
É necessário fechar/drenar Form e impedir editores/ativadores concorrentes.
Snapshot já aplicado é histórico, não uma leitura instantânea.
Timeout sem reserva remota, corrupção de inventário, conflito de origem ou
drift irreparável exigem decisão humana; não existe retry/reparo automático.
O conjunto esperado de representações é persistido no mapping Bridge e a
integridade atual do Form/bindings é comparada a esse conjunto. Uma adulteração
manual que também altere o mapping está fora do protocolo suportado. Migração
física e compatibilidade runtime final não foram executadas, em respeito ao
hard stop.

## T. Próximo gate humano

Revisar os dois commits locais, contrato e roteiro antes de autorizar
integração/push ou atualização do LAB. Migration/runtime/fixture/Entries e
P3 físico exigem autorização separada. Não executar nenhuma dessas ações
automaticamente após a revisão de código.
