# Turmas Bridge

Plugin WordPress instalado no site público para atender, por HTTPS autenticado,
o TURMAS-EPF. Ele não acessa o banco de dados do sistema de gestão e o
TURMAS-EPF não acessa bancos ou tabelas deste site.

## Fase atual: 10

Versão pública atual: `0.1.0`. Versão do contrato REST: `v1`.

## Escopo da versão 0.1.0

Somente duas operações de leitura são disponibilizadas:

- `GET /wp-json/turmas-bridge/v1/ping`;
- `GET /wp-json/turmas-bridge/v1/formularios/{id}`.

Não cria, clona, atualiza ou exclui formulários. Não integra GP Inventory,
FlowSheet ou InscriHub, não lê inscrições e não cria tabelas.

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
comparada com `hash_equals`. Operações GET não usam idempotency key; ela será
introduzida somente em operações de escrita futuras.

Não foi encontrada nesta estação uma implementação reutilizável do padrão
Fênix. Por isso este contrato é próprio, documentado e versionado; ele deverá
ser comparado ao `FB_Auth` real antes da interoperabilidade com qualquer outro
bridge.

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
disponível. Ele retorna somente ID, título, estado e uma lista estrutural
permitida de campos: identificador, tipo, rótulo, prepopulação, subinputs e
contagem de opções. Não retorna entries, escolhas, notificações, feeds ou
configurações sensíveis.

## Qualidade

```bash
composer install
composer run lint
composer run typecheck
composer test
```

## Pendências para fases posteriores

- confirmar o formato real do `FB_Auth` do Fênix;
- inspecionar os modelos Gravity Forms e os metadados de GP Inventory,
  FlowSheet e InscriHub no ambiente de homologação;
- implementar cliente/configuração no TURMAS-EPF somente na Fase 11;
- implementar criação, provisionamento e atualização somente nas Fases 12–14.
