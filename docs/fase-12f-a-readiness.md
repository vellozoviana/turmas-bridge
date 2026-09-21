# Fase 12F-A — Evidência read-only do Bridge

O endpoint autenticado `GET /wp-json/turmas-bridge/v1/publicacoes/{publication_key}`
continua somente leitura. Além do formulário e do estado efetivo, ele expõe
um resumo dos Resources da Publicação: identidade, Resource, capacidade,
consumo e resultado da inspeção de bindings/choices.

O adapter usa a leitura fresca de consumo já existente e não executa
`ensure`, criação, sincronização ou alteração de inventário. Formulário ativo,
Resource ausente, binding divergente, capacidade inconsistente ou consumo
acima da capacidade tornam a evidência não saudável.

Os fields retornados pelo Gravity Forms podem ser objetos `GF_Field`; o
adapter os normaliza antes de inspecionar choices. Labels como `11` também
são convertidos explicitamente para string antes de construir a representação
da CRE. Essa normalização evita falsos `BINDING_INVALID` e mantém o fail-closed
para estruturas realmente incompatíveis.

No LAB E2F, a causa dos blockers iniciais foi essa incompatibilidade de leitura
(defeito do adapter, não drift da fixture). Depois da correção, o status
read-only confirmou `MATERIALIZED`, Form inativo, Resources 11/12 saudáveis,
capacidades 5/4 e consumo 0. A inspeção pode limpar apenas o cache transitório
de contagem do vendor para obter consumo fresco; não grava estado de domínio.

O estado `MATERIALIZED` continua exigindo formulário inativo e Resources
saudáveis. Ativação, `PUBLICADA`, FlowSheet e InscriHub permanecem fora do
escopo desta subfase.
