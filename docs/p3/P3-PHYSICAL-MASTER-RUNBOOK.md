# P3 físico futuro — master runbook V1

PLANEJAMENTO OFFLINE. NÃO EXECUTADO. Este roteiro não autoriza execução.
P3-D só pode entrar no LAB depois de revisão humana dos dois commits,
autorização expressa de migration/runtime e de cada cenário mutável.

## Portas de entrada

1. Aprovar P3-D-CAPACITY-V1.md e as limitações de atomicidade.
2. Autorizar backup e atualização coordenada do runtime isolado. Não usar
   checkout compartilhado com WordPress durante revisão de código.
3. Executar P3-PRECHECK.md e registrar hashes, versões, schema e InnoDB.
4. Autorizar fixture NOVA segundo P3-FIXTURE-SPEC.md. Não reutilizar P1/P2.
5. Separar credenciais do relatório; transmitir comandos pelo caminho
   administrativo/API autenticado normal. Nunca burlar nonce/HMAC.
6. Guardar baseline redigida segundo P3-OBSERVABILITY-PLAN.md.

## Sequência de cenários

A — Form ativo: solicitar mudança local de Vagas e sincronização explícita.
Esperado BLOCKED_FORM_ACTIVE; nenhum GF update/meta capacity write, Form
continua ativo, IDs/bindings/Entries inalterados. Apenas ledgers/auditoria local
da intenção/tentativa podem mudar. NÃO executar redução online.

B — fechamento autorizado: tornar apenas a fixture nova inativa em etapa
separada. Drenar submissões em voo e proibir editores/ativadores concorrentes.
Reenviar a MESMA intenção bloqueada. Esperado APPLIED_VERIFIED, Resource e
todas as choices iguais ao desejado, consumed_after <= desired, Form inativo.
Não tornar o Form ativo novamente como parte do sync.

C — below consumed: depende de autorização adicional para Entries sintéticas.
Preparar consumo conhecido em etapa separada, fechar/drenar Form, registrar
novo comando com desired positivo < consumed. Esperado bloqueio sem escrita
de capacidade. Não apagar Entries para fazer o teste passar.

D — replay: repetir comando APPLIED_VERIFIED com mesmo payload/key e novo
nonce via transporte normal. Bridge retorna resultado persistido, não faz
segunda escrita nem nova inspeção de inventário. Status GET é histórico.
No EPF já aplicado, a própria entrega retorna localmente sem novo HTTP.

E — divergência: somente após autorização de fault injection específica numa
fixture descartável. Simular perda de resposta/efeito parcial; registrar estado
inconclusivo. Tentar entrega normal => não reaplica. Reconciliação explícita
reinspeciona e atualiza ledger; nunca repara Form, Resource ou Entry.
Se divergência persistir, RECONCILIATION_REQUIRED é resultado correto.
Não atribuir APPLIED_VERIFIED sem todas as evidências.

F — restart: autorizar reinício apenas dos serviços necessários, sem limpar
ledgers/caches de idempotência como contorno. Comando aplicado permanece
aplicado e replay não escreve; inconclusivo permanece bloqueado à reaplicação.

G — integridade: executar P3-POSTCHECK.md, comparar baseline e preencher
P3-PHYSICAL-RESULT-TEMPLATE.md. Interromper diante de discrepância.

## Stop conditions

Parar antes de qualquer ação em ID protegido, host institucional, dado pessoal,
capacidade abaixo de consumo, HMAC inválido, schema incompleto, inconclusivo
sem diagnóstico ou falta de evidência. Nunca corrigir automaticamente, trocar
key para escapar de conflito, deletar ledger ou fazer retry cego.
Corridas físicas, Entries e observadores não estão autorizados nesta rodada.
