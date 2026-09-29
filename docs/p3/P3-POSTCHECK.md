# P3 — postcheck físico futuro

Não executar agora.

Comparar baseline por cenário: Vagas locais, row_version, motivo/auditoria
redigidos, notificação, intenção local, attempts/revision e ledger remoto.
Confirmar dados persistidos após restart, sem remover linhas para obter PASS.

Em APPLIED_VERIFIED exigir Form inativo observado; Resource existente,
owner/mapping correto, gpi_inventory_limit e todas as choice.inventory_limit
iguais ao desired, bindings íntegros, consumed_after fresco <= desired.
Registrar instantes de leitura e limitações de concorrência externa.

Em bloqueios: nenhuma escrita GF/meta de capacidade, nenhuma alteração de
mapping/binding/Entry; ledger de tentativa pode mudar.
Em replay: mesma identidade e nenhuma segunda escrita. Resposta histórica
não deve ser apresentada como nova observação.
Em reconciliação: apenas ledger muda; nenhum reparo de inventário/Entries.
Conferir counts e denylist: objetos protegidos invariantes, sem efeito em
ambiente institucional. Finalizar Forms novos inativos por procedimento
explicitamente autorizado; preservar evidências, sem limpeza destrutiva.

Se escrita parcial, IDs novos inesperados, consumo/limite divergentes ou
evidência insuficiente: FAIL/INCONCLUSIVO, STOP e revisão humana.
