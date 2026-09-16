# Idempotência de Publicação — schema 0.5.0

## Objetivo e identidades

`nonce` protege replay da requisição autenticada. `Idempotency-Key` identifica
o comando HTTP mutante. `publication_key` identifica a publicação de domínio e
o mapping identifica a materialização. Eles não são intercambiáveis.

O primeiro side effect relevante é `duplicate_form()` dentro da materialização.
Antes dele, a key é inserida sob `UNIQUE(idempotency_key)` e o registro passa
atomicamente de `RESERVED` para `MATERIALIZING`. Não existe lock global.

## Migration

Schema anterior: `0.4.0`. Schema novo: `0.5.0`; a versão pública do plugin
acompanha o mesmo número pela convenção existente do projeto.

| Coluna nova | Finalidade | Sensível | Índice |
| --- | --- | --- | --- |
| `state varchar(32)` | ciclo de execução; default `SUCCEEDED` | Não | Sim |
| `publication_key varchar(80)` | correlação operacional | Não | Sim |
| `processing_started_at` | início da região ambígua | Não | Não |
| `reconciliation_at` | registro de bloqueio conservador | Não | Não |
| `last_error_code varchar(100)` | código sanitizado | Não | Não |

`dbDelta` adiciona campos e índices sem recriar a tabela. Registros antigos
recebem semanticamente `SUCCEEDED`: antes da 0.5.0, o único `INSERT` acontecia
depois de materialização e choices concluídos. Chave, hash, resposta e
timestamps antigos são preservados. Fresh install nasce diretamente nesse
schema; repetir a instalação/upgrade é idempotente.

## Máquina de estados

```text
RESERVED --CAS--> MATERIALIZING --resultado completo--> SUCCEEDED
    |                   |
    | falha comprovada  +-- resultado ambíguo --> RECONCILIATION_REQUIRED
    +--> RESERVED
```

- `RESERVED`: reserva persistida, sem side effect. Após 120 segundos sem
  progresso, uma retry pode recuperar a reserva com compare-and-set em
  `updated_at`; só um recuperador vence.
- `MATERIALIZING`: side effect pode ter ocorrido. Uma entrada antiga passa por
  compare-and-set a `RECONCILIATION_REQUIRED`; nunca é materializada de novo.
- `SUCCEEDED`: terminal. Mesma key/hash reproduz a resposta persistida.
- `RECONCILIATION_REQUIRED`: terminal até ação operacional humana. Não há
  worker, UI ou descoberta automática de formulário órfão nesta fase.

Não existe `FAILED`: falha segura pré-side-effect retorna a `RESERVED`; toda
falha posterior ou incerta converge para reconciliação.

## HTTP e retry

| Situação | Resposta | Próxima ação |
| --- | --- | --- |
| Nova key/hash | `201` após sucesso | guardar resposta |
| Key/hash em `RESERVED` recente ou `MATERIALIZING` recente | `202 processing` | retry posterior com novo nonce |
| Key/hash em `SUCCEEDED` | status original + replay | não materializa |
| Key/hash diferente | `409` | nunca processa o novo body |
| Reconciliação necessária | `409` sanitizado | diagnóstico humano |
| Falha de storage | `500` sanitizado | não executa side effect novo |

Payload inválido é rejeitado antes da reserva. Autenticação HMAC, timestamp e
nonce ocorrem antes de o endpoint ser invocado. A resposta não inclui SQL,
stack trace, payload integral, assinatura, nonce, segredo ou PII.

## Matriz de crash e operações

| Ponto | Estado resultante | Retry |
| --- | --- | --- |
| Antes da reserva | nenhum | nova tentativa normal |
| `RESERVED` abandonado | `RESERVED` stale | recuperação CAS segura |
| Antes/depois de clone | `MATERIALIZING` | reconciliação, sem novo clone |
| Mapping/choices falham após materialização | reconciliação | sem novo clone |
| Persistência de sucesso falha | materializando/reconciliação | sem novo clone |
| Resposta perdida após `SUCCEEDED` | `SUCCEEDED` | replay determinístico |

Runbook: localizar por hash da key, verificar `state`, `publication_key`,
timestamps, código sanitizado e mapping correspondente. Não apagar registros
`MATERIALIZING`, `RECONCILIATION_REQUIRED` ou `SUCCEEDED` antes de decisão de
retenção. Antes de migration em produção, executar backup do banco; rollback
de código é compatível porque os campos novos são aditivos, mas não deve
remover o schema. Ordem futura sugerida: Bridge primeiro, smoke test de schema,
`/ping`, autenticação e replay; depois EPF. Isso não é deploy autorizado.
