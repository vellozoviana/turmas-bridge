# Roteiro de homologação — Fase 11

## Objetivo

Validar que o TURMAS-BRIDGE lê um formulário-modelo do Gravity Forms por API
autenticada e devolve um manifesto seguro. Este roteiro não autoriza nenhuma
operação de escrita.

## Antes de começar

1. Conclua o checklist de homologação e use preferencialmente *staging*.
2. Registre versões, URL do ambiente e o ID do template institucional 199 em
   canal interno restrito. O modelo tem 11 seletores de Turma por CRE; o campo
   195 é o seletor obrigatório da 1ª CRE.
3. Faça backup conforme o procedimento institucional.
4. Instale e ative somente a versão aprovada do TURMAS-BRIDGE.
5. Em `Configurações > Turmas Bridge`, configure um segredo HMAC exclusivo do
   ambiente. Guarde-o fora do WordPress e não o inclua em capturas de tela.

## Verificação administrativa

1. Confirme que Gravity Forms está ativo e que o formulário de teste existe.
2. Confira que o bridge está ativo e que não criou tabelas próprias.
3. Não edite o template, seus campos, choices, notificações ou feeds.
4. Não abra, exporte ou consulte entries para este teste.

## Verificação da API

Use um cliente controlado capaz de assinar HMAC-SHA256. O contrato `v1` usa:

- `X-Turmas-Bridge-Timestamp`;
- `X-Turmas-Bridge-Nonce`;
- `X-Turmas-Bridge-Signature`;
- método, rota, query, timestamp, nonce e hash SHA-256 do corpo na requisição
  canônica.

1. Execute `GET /wp-json/turmas-bridge/v1/ping` com assinatura válida.
2. Confirme resposta `200`, versão de API e disponibilidade básica do Gravity
   Forms, sem segredo HMAC.
3. Execute `GET /wp-json/turmas-bridge/v1/formularios/{id}` para o template de
   teste, também com assinatura válida.
4. Confirme resposta `200` e revise apenas `form`, `fields` e
   `integration_hints`.
5. Repita o pedido sem headers HMAC e confirme bloqueio `401`.
6. Repita com assinatura inválida e confirme bloqueio `401`.
7. Reenvie o mesmo nonce dentro de cinco minutos e confirme bloqueio por replay.

## Conferência do manifesto

Confirme que a resposta contém somente:

- formulário: `id`, `title`, `status` e `fingerprint`;
- campos: identificadores, tipo, labels, obrigatoriedade, prepopulação,
  subinputs e choices reduzidas a `text` e `value`;
- hints heurísticos, que não representam um mapeamento definitivo.

Quando presentes, os indicadores booleanos de lógica condicional e GP
Inventory e suas listas de IDs servem somente para conferência estrutural. Não
revelam nem permitem deduzir capacidade, estoque restante, regras, feeds ou
configurações de terceiros.

Confirme que a resposta não contém entries, valores submetidos, nomes, CPF,
e-mail, IP, notificações, feeds, tokens, API keys ou segredo HMAC.

## Registro e encerramento

Registre somente: data/hora, ambiente, versão dos plugins, ID técnico do
template de teste, códigos HTTP e confirmação de que nada foi alterado. Não
anexe payloads contendo dados sensíveis.

Se qualquer verificação falhar, interrompa o teste, preserve a evidência
sanitizada e informe a equipe. Não tente desativar a autenticação, alterar
diretamente o banco ou adaptar o formulário para contornar a falha.

## Saída esperada

Ao final, a equipe deverá ter evidência de leitura real segura e uma lista de
campos candidatos. A escolha definitiva de mapeamentos e qualquer criação ou
publicação ficam para aprovação explícita da Fase 12.
