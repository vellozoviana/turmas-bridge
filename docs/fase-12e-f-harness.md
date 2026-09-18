# Fase 12E-F — Harness e hardening do TURMAS-BRIDGE

## Cobertura e contratos

O Bridge mantém o contrato REST autenticado: HMAC-SHA256, timestamp, nonce de transporte, `Idempotency-Key` somente no POST e resposta de status somente leitura no GET. Os testes cobrem rotas, autenticação, JSON inválido, conflito de chave, replay determinístico, estados `MATERIALIZING`, `SUCCEEDED` e `RECONCILIATION_REQUIRED`, além de ausência controlada de Gravity Forms/GP Inventory.

O contrato compartilhado permanece:

- publicação: `year`, `formation_code`, `publication_key`;
- turma: `class_key`, nomes derivados, capacidade, CRES e oito ciclos;
- resposta: `status`, `form_id`, `idempotent_replay`;
- status: `effective_state`, `form_state`, `form_id`.

Mapa de testes: unitários cobrem domínio/adapter; contratos cobrem HMAC, nonce e REST; integração de materialização cobre clone, mapping, Resources e recuperação; o teste físico administrativo permanece fora do Bridge e depende do login local.

Não existe dependência de filesystem entre os repositórios.

## Recuperação e efeitos

- Reserva idempotente com hash diferente retorna conflito.
- Replay do mesmo payload retorna a mesma resposta e não cria formulário ou Resource adicional.
- Crash após clone preserva o mapping para reconciliação; mapping ausente com formulário existente não gera clone cego.
- Formulário ausente ou ativo, binding inconsistente, Resource órfão ambíguo, leitura de consumo indisponível e capacidade abaixo do consumo falham de modo conservador.
- Lock de orquestração é liberado em sucesso, exceção e retorno antecipado; os nomes de lock permanecem limitados a 64 caracteres e são derivados de hash.
- O GET de status não cria, atualiza ou remove qualquer objeto.

## Evolução e instalação

O schema `0.6.1` mantém a tabela existente e converge instalações legadas adicionando apenas `choices_fingerprint` quando ausente. Instalação nova, upgrade repetido e reativação são idempotentes; mappings, idempotency rows e Resources existentes não são removidos.

O adapter continua sendo a única boundary para internals proprietários (`gpi_field`, `gpiResource`, `choice.inventory_limit`). A versão testada é GP Inventory 1.0.29; não é declarada compatibilidade universal.

## Pendência humana

O teste automatizado não substitui o browser físico autenticado no WordPress local. Esse gate deve executar o fluxo administrativo do EPF e confirmar redirect/feedback, persistência, auditoria e consulta de status sem ativar formulários.

## CI e rollback do checkpoint

O workflow `.github/workflows/quality.yml` executa lint, PHPStan, PHPUnit e `git diff --check` em `main`. Em caso de falha após publicação, identificar o job `verify` e o repositório afetado antes de qualquer correção. Não usar force push: preservar o histórico e criar uma reversão revisada somente depois de confirmar o último commit verde.
