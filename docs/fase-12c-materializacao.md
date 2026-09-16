# Fase 12C — materialização controlada

Materialização cria um formulário técnico inativo a partir de um template
configurado, sem abrir inscrições. `materialized` significa somente que o
Bridge associou `publication_key` a `form_id`; não significa `published`.

O template vem de `TURMAS_BRIDGE_TEMPLATE_ID` ou da opção protegida
`turmas_bridge_template_id`. O adapter usa apenas APIs públicas do Gravity
Forms (`GFAPI::get_form`, `duplicate_form` e `update_form`), nunca SQL. Antes
de clonar, exige template ativo, não removido, com fields e fields de choices.
Após o clone descobre os IDs dos fields de choices no formulário novo e o
mantém inativo. A lógica condicional do clone não é reescrita nesta fase.

`wp_turmas_bridge_materializations` usa `publication_key` único e guarda
template, `form_id`, estado técnico `RECEIVED`, `MATERIALIZED` ou `FAILED`,
hash, IDs descobertos e erro sanitizado. Em retry, a mesma chave reutiliza ou
reconcilia o form existente; não cria outro. Falha após clone preserva o ID
para reconciliação. A atomicidade entre Gravity Forms e o banco não é assumida.

Uma reserva existente sem `form_id` é resultado desconhecido: o Bridge não cria
outro clone automaticamente e retorna `reconciliation_required`. Essa proteção
evita duplicação silenciosa após interrupção entre a intenção persistida e a
gravação do mapping. A descoberta automática de um formulário órfão por
marcador técnico ainda depende de contrato público validado do Gravity Forms.

O título usa apenas os dados do contrato v1: `Inscrições {ano} — {código}`.
Um nome descritivo de Formação exigirá extensão explícita do payload em fase
futura. Nenhum Resource, Inventory, choice definitiva, feed, Entry, FlowSheet
ou InscriHub é criado nesta fase.
