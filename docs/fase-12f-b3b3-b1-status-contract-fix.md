# Fase 12F-B3B3-B1 — Correção do contrato de status

## Defeito observado

Na primeira reconciliação física B3B3-B, o GET autenticado da operação
retornou corretamente `RECONCILIATION_REQUIRED` e a reconciliação
`INCONCLUSIVE`, mas omitiu o `error_code` persistido na ActivationOperation:
`POST_ACTIVATION_DRIFT`.

## Causa raiz

`Remote_Activation_Command_Service::status_result()` montava o payload base
com operação, publicação, Form e replay, mas não copiava
`ActivationOperation.error_code`. O controller apenas acrescentava a seção de
reconciliação, portanto não tinha como recuperar o valor omitido.

## Correção

O payload de status agora inclui, de forma aditiva,
`error_code: <valor persistido ou null>`. Nenhum campo existente foi removido,
nenhum estado foi renomeado e a seção de reconciliação continua separada.
O schema permanece `0.8.0`; a versão pública do plugin passa para `0.9.2` por
ser uma correção backward-compatible do contrato de leitura.

## Garantias

- o erro original não é derivado nem substituído pelo resultado da reconciliação;
- GET continua sem mutação e não cria novas tentativas;
- a ActivationOperation e a tentativa de reconciliação permanecem entidades
  independentes;
- não há alteração na causalidade, CAS ou idempotência da reconciliação.

A revalidação física após o checkpoint é somente GET. Nenhum POST, replay,
ativação, alteração de Resource ou Entry faz parte desta correção.
