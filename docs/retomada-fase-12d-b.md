# Retomada da Fase 12D-B — GP Inventory Advanced

## Estado

A Fase 12D-B está bloqueada. A fundação de Publicação, materialização inativa,
choices por CRE e o Resource Plan interno existem, mas não há ambiente
descartável e autorizado com Gravity Forms e GP Inventory para validar escrita
e persistência de Advanced Resources. Este documento não autoriza acesso ou
alteração em ambiente institucional.

## Pré-requisitos

- Instalação legítima e autorizada de WordPress, Gravity Forms e GP Inventory;
- versões registradas e licença apropriada para desenvolvimento;
- formulário e Resource estritamente fictícios;
- nenhuma Entry, dado pessoal ou segredo de produção;
- acesso ao banco apenas pelos mecanismos públicos autorizados do produto.

## Roteiro de laboratório

1. Confirmar a persistência de Resource criado pela interface oficial.
2. Confirmar o vínculo Resource → field.
3. Confirmar a persistência de `choice.value`.
4. Confirmar a persistência do limite de inventory por choice.
5. Alterar na UI `5 → 8 → 5` e registrar o estado observado.
6. Identificar, ou descartar, uma operação programática pública equivalente.
7. Confirmar leitura segura de `limit`, `count` e `available`.
8. Confirmar consumo compartilhado por fields que usam o mesmo Resource.
9. Confirmar aumento de limite preservando consumo.
10. Confirmar redução de limite sem apagar consumo.
11. Confirmar o bloqueio de `novo_limite < consumidas`.
12. Confirmar o efeito de clonar formulário sobre Resources e choices.
13. Simular drift e validar reconciliação de mapping, fields e choices.

## GO / NO-GO

**GO** somente se os testes comprovarem uma API pública ou fluxo autorizado,
idempotente e reconciliável para Resource, vínculo e capacidade compartilhada.

**NO-GO** se qualquer etapa exigir SQL direto, leitura de Entries
institucionais, cópia de licença/plugin, inventários simples independentes ou
contador paralelo. Nessa hipótese, registrar o resultado e manter a Fase 12D-B
bloqueada.
