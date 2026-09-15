# Turmas Bridge

Plugin WordPress instalado no site público para atender, por HTTPS autenticado,
o TURMAS-EPF. Ele não acessa o banco de dados do sistema de gestão e o
TURMAS-EPF não acessa bancos ou tabelas deste site.

## Estado atual

Versão pública atual: `0.4.0`. Versão do contrato REST: `v1`.

## Escopo atual

O Bridge disponibiliza as operações autenticadas:

- `GET /wp-json/turmas-bridge/v1/ping`;
- `GET /wp-json/turmas-bridge/v1/formularios/{id}`.
- `POST /wp-json/turmas-bridge/v1/publicacoes`.

O `POST` valida o contrato, protege a requisição por HMAC e Idempotency-Key,
materializa uma cópia técnica **inativa** do template e prepara choices por
CRE usando `adminLabel`. Ele não publica o formulário, não lê Entries nem
dados pessoais e não integra GP Inventory, FlowSheet ou InscriHub.

A Fase 12D-B (Advanced Resources e capacidade compartilhada) permanece
**bloqueada**: não existe ambiente autorizado/licenciado para validar a
persistência e a escrita programática do GP Inventory. Não há workaround por
SQL, inventários simples ou contador paralelo.

## Contrato HMAC v1

Todos os pedidos devem usar HTTPS em produção e incluir:

- `X-Turmas-Bridge-Timestamp`: Unix timestamp em UTC;
- `X-Turmas-Bridge-Nonce`: valor aleatório entre 16 e 128 caracteres;
- `X-Turmas-Bridge-Signature`: `v1=` seguido de HMAC-SHA256 em hexadecimal.

A string canônica é formada por sete linhas, nesta ordem:

```text
v1
METHOD
ROTA_REST
QUERY_CANONICALIZADA
TIMESTAMP
NONCE
SHA256_DO_CORPO_BRUTO
```

A rota é, por exemplo, `/turmas-bridge/v1/ping`: o código remove espaços
externos, mantém uma única barra inicial e remove a barra final, exceto na
raiz. A query remove o parâmetro técnico `rest_route`, ordena chaves
recursivamente por texto e usa RFC 3986 (`&` e percent-encoding) para gerar a
linha assinada. O corpo é assinado como bytes brutos e seu hash é SHA-256 em
hexadecimal minúsculo.

O timestamp deve ser Unix UTC com dez dígitos e é aceito quando a diferença
para o relógio do servidor é de no máximo 300 segundos. O nonce aceita os
caracteres `A-Z`, `a-z`, `0-9`, `.`, `_`, `~` e `-`, com 16 a 128 caracteres.
Após uma assinatura válida, o nonce é gravado em transient sob chave derivada
por SHA-256 durante cinco minutos; uma repetição nesse período recebe `401`.
A assinatura é `v1=` seguida do HMAC-SHA256 hexadecimal minúsculo e é
comparada com `hash_equals`. Operações GET não usam idempotency key. O POST de
Publicações exige `Idempotency-Key`: a mesma chave com o mesmo corpo reproduz
a resposta; com corpo distinto recebe `409`.

O contrato é próprio, documentado e versionado, e permanece independente de
outros bridges institucionais. Qualquer interoperabilidade futura exige
comparação contratual explícita; não há reaproveitamento automático de segredo
ou de cabeçalhos de autenticação.

## Chave compartilhada

Em desenvolvimento, gere uma chave fictícia própria para o ambiente e defina
`TURMAS_BRIDGE_SHARED_SECRET` no `wp-config.php`, que não pertence ao source
do plugin nem deve entrar no Git. Como alternativa, um administrador pode
salvar a chave em **Configurações > Turmas Bridge**. A constante tem
precedência. A tela nunca exibe a chave salva e o plugin não a inclui em
respostas nem logs.

Para testes HTTP locais, somente defina explicitamente
`TURMAS_BRIDGE_ALLOW_INSECURE_LOCAL` como `true` em ambiente WordPress `local`
ou `development`. Produção sempre exige HTTPS.

## Manifesto de formulário

O endpoint de formulário usa `GFAPI::get_form()` quando o Gravity Forms está
disponível. Ele retorna uma allowlist estável:

- `form`: ID, título sanitizado, estado (`active`, `inactive` ou `trash`) e
  fingerprint SHA-256 do manifesto;
- `fields`: ID, tipo, rótulo, `admin_label`, `input_name`, obrigatoriedade,
  prepopulação, subinputs, choices e indicadores booleanos de lógica
  condicional e GP Inventory;
- `choices`: somente `text` e `value` sanitizados, sem preço, seleção ou
  propriedades internas;
- `integration_hints`: candidatos heurísticos para Turma, Formação e
  identificador do participante, IDs de campos com choices e IDs com lógica
  condicional ou GP Inventory. Não contém feeds, capacidades, estoque,
  contagens, regras ou configuração de integrações.

Os hints não escolhem um campo definitivo. A futura configuração deverá usar
o ID do campo junto de `admin_label` e/ou `input_name` aprovados para cada
modelo, nunca apenas o label visível.

O manifesto não retorna entries, dados submetidos, CPF, e-mail, IP, senhas,
tokens, segredo HMAC, feeds, notificações, destinatários ou propriedades
desconhecidas do formulário. Formulário inexistente retorna `404`; Gravity
Forms indisponível retorna `503`; erro de leitura retorna `500` sem stack
trace. Um template que não possa ser convertido em manifesto também retorna
`500` controlado, sem expor detalhes internos.

### Ambiente local e dependências

Na estação local atual, Gravity Forms, GP Inventory e FlowSheet não estão
instalados. A convenção de campos para choices é `turma_cre_XX`, descoberta
por `adminLabel`; IDs de fields não são constantes. Qualquer referência
histórica a IDs institucionais é evidência de reconhecimento, não configuração
de execução. FlowSheet e InscriHub permanecem pendentes e não foram alterados.

## Qualidade

```bash
composer install
composer run lint
composer run typecheck
composer test
```

## Pendências para fases posteriores

- inspecionar os modelos Gravity Forms e os metadados de GP Inventory,
  FlowSheet e InscriHub no ambiente de homologação;
- validar Advanced Resources em laboratório autorizado e só então implementar a Fase 12D-B;
- definir reconciliação operacional, FlowSheet, InscriHub, ativação e homologação end-to-end em fases próprias.
