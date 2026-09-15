# Fase 11 - leitura segura de template

Esta fase evolui somente o endpoint autenticado `GET
/wp-json/turmas-bridge/v1/formularios/{id}`. Não cria ou modifica formulários,
não consulta entries, não cria tabelas e não altera o TURMAS-EPF.

## Fonte e API

Quando presente, o Gravity Forms é acessado exclusivamente por
`GFAPI::get_form($id)`. O bridge verifica a presença de `GFAPI` e do método
antes de ler. Não usa SQL nem acessa tabelas do Gravity Forms diretamente.

O ambiente local atual não contém Gravity Forms, GP Inventory ou FlowSheet.
Por isso, leitura de um modelo real é pendência de homologação com cópia
institucional/licenciada; testes usam fakes e não contém dados pessoais.

## Contrato do manifesto

```json
{
  "form": {
    "id": 282,
    "title": "Modelo de teste",
    "status": "active",
    "fingerprint": "sha256-hex"
  },
  "fields": [
    {
      "id": "10",
      "type": "select",
      "label": "Turma",
      "admin_label": "turma_publicacao",
      "input_type": "",
      "is_required": false,
      "allows_prepopulate": false,
      "input_name": "turma_publicacao",
      "input_ids": [],
      "has_conditional_logic": false,
      "has_gp_inventory": false,
      "has_choices": true,
      "choice_count": 1,
      "choices": [{"text": "Turma A", "value": "turma-a"}]
    }
  ],
  "integration_hints": {
    "turma_candidates": [{"field_id": "10", "signals": ["admin_label"]}],
    "formacao_candidates": [],
    "participant_identifier_candidates": [],
    "choice_field_ids": ["10"],
    "conditional_logic_field_ids": [],
    "gp_inventory_field_ids": []
  }
}
```

Somente as chaves acima são retornadas. `has_conditional_logic` e
`has_gp_inventory` são somente indicadores booleanos; os IDs nos hints não
incluem regras, estoque, capacidade, saldo, feeds ou configurações. O
fingerprint é calculado sobre o
manifesto sem ele próprio e pode indicar mudança estrutural do template. Se
uma definição não puder ser serializada como manifesto seguro, o endpoint
retorna erro controlado `500`; não devolve o objeto bruto nem detalhes internos.

## Privacidade e identificação

Nunca entram no manifesto entries, valores submetidos, inscritos, CPF, e-mail,
IP, notificações, feeds, tokens, segredos ou propriedades desconhecidas do
objeto Gravity Forms. Choices de template são reduzidas a `text` e `value`;
`isSelected`, preço e propriedades adicionais ficam de fora.

`field.id` é o identificador técnico principal dentro do formulário. Para
configuração futura, ele deve ser combinado com `adminLabel` e/ou `inputName`.
Labels visíveis são mutáveis e só devem auxiliar a revisão humana. Os
`integration_hints` são candidatos, não mapeamento definitivo.

## Evidência institucional e próximas decisões

O formulário institucional 199 foi conferido manualmente, sem ler entries ou
salvar qualquer alteração. Ele possui 11 campos separados de Turma (uma CRE
por campo). O campo 195, referente à 1ª CRE, é um `select` obrigatório com
choices e inventário por choice. Esta é uma referência estrutural, não uma
autorização para clonar ou alterar o formulário.

- A identidade lógica da Turma continua no TURMAS-EPF:
  `ano_letivo + formacao_id + codigo_turma`. `form_id`, `field.id`, choice
  `value` e texto visível são conceitos diferentes e não podem ser tratados
  como equivalentes.
- O formato definitivo de choice `value` está pendente. O bridge devolve texto
  e valor existentes, mas não cria nem escolhe valores estáveis nesta fase.
- GP Inventory é por choice no modelo. A futura operação de escrita deve
  definir separadamente capacidade original, estoque restante e sua fonte de
  verdade; o manifesto não devolve nenhum desses números.
- FlowSheet está ativo no ambiente institucional, mas o mecanismo técnico de
  associação com formulário ainda não foi identificado. Nenhum feed ou
  configuração é lido.
- InscriHub deverá, em fase posterior, associar um formulário já aprovado por
  `form_id`, campo identificador e permissões acordadas, por API/contrato
  verificado e sem SQL direto.
