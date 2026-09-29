# P3 — especificação da fixture futura

OFFLINE / nenhuma fixture criada. IDs devem ser atribuídos pelo ambiente
somente após autorização; não presumir IDs por contagem ou sequência.

Proposta reservada apenas no papel: ano 2094, formação LABP3, classe 01.01,
publication_key 2094:LABP3, class_key 2094:LABP3:01.01, Área e Local com rótulo
[LAB P3]. Verificar colisões antes de qualquer criação; se houver, STOP.

Uma Turma lógica, três representações select, CRES 01/02/03, adminLabels
turma_cre_01/02/03. Mesma choice.value=class_key, rótulo sintético, mesma
capacidade (proposta inicial 8), mesmo Resource e bindings exatos. Nenhuma
identidade pessoal nos fields, Entries ou motivos. Form inicialmente inativo.

Para ensaio de serialização cooperativa, segunda Turma 01.02 no MESMO Form
pode ser proposta separadamente; sua criação exige aprovação explícita.
Capacidades e consumo dos cenários devem ser anotados, nunca multiplicados
pela quantidade de CRES. Capacidade zero não pertence ao contrato V1.

## Denylist obrigatória

Publications 4/2097:E2F e 5/2096:LABP2; Forms 10,12,13,14,15,199,297;
Resources 11,12,15,16,17,18,428371. Não reutilizar, duplicar, ativar, alterar
capacidade ou consultar Entries desses objetos como parte do P3.
Proteger também qualquer fixture histórica descoberta no precheck.
Nenhum host institucional está no escopo.

## Estados controlados

A usa apenas o Form novo ativo e prova bloqueio.
B usa esse Form fechado/drenado e prova aplicação.
C requer consumo sintético previamente autorizado e Form fechado.
E usa objeto novo descartável; plano exato de injeção e recuperação deve
ser aprovado antes. NÃO editar diretamente IDs/estados no ledger para fabricar
sucesso. Final previsto: novos Forms inativos e evidências preservadas.
