# Fase 12F-B3B3-C1 — Evidência causal de ativação

## Causa e fronteira de confiança

Antes desta fase, `Form_Activation_Service` recebia do gateway evidência de tentativa e, após uma leitura, também conhecia o Form e o estado observado. `Remote_Activation_Command_Service::safe_evidence()` descartava esses dados e `Activation_Operation_Repository::encode_evidence()` aceitava somente campos escalares de diagnóstico. Assim, um incidente pós-mutação terminava em `RECONCILIATION_REQUIRED` sem prova causal persistida. O reconciliador aceitava `mutation_attempted=true`, que comprova no máximo que uma chamada foi tentada; esse sinal não distingue uma mutação bem-sucedida de uma falha seguida de ativação independente.

O endpoint REST continua aceitando somente os campos definidos no contrato de ativação. Evidência causal nasce depois da fronteira REST, dentro da aplicação. HMAC autentica a requisição, mas não transforma alegações do cliente em observação do servidor.

## Contrato canônico

`Activation_Mutation_Evidence` é um objeto imutável e versionado. A representação persistida fica em `evidence_json.activation_mutation`:

```json
{
  "version": 1,
  "state": "MUTATION_CONFIRMED",
  "form_id": 901,
  "source": "b2_gateway_success_and_post_read",
  "observed_form_state": "active"
}
```

O estado confirmado é produzido por B2 somente quando o gateway relata mutação bem-sucedida do Form esperado e a leitura pós-ativação observa a mesma publicação, o mesmo ID de Form e `active`. A tentativa de chamada, erro do gateway, leitura indisponível ou Form ativo sem essa cadeia gera evidência fraca ou incerta e não permite `CONFIRMED_SUCCESS`. A persistência limita a estrutura a cinco campos conhecidos, tipos exatos e no máximo 2 KB para todo o JSON.

O reconciliador aplica novamente a validação: versão inteira 1, estado confirmado, ID correspondente à operação, origem fixa do B2 e estado observado exatamente `active`. Strings como `"true"`, inteiros `1`, campos desconhecidos, versões futuras e estruturas incompletas não são prova.

## Propagação e ramos de falha

| Situação | Evidência | Operação | Resultado possível da reconciliação |
|---|---|---|---|
| Pré-condição ou lock falha | Sem mutação | `RECONCILIATION_REQUIRED` apenas quando a identidade é incerta; caso contrário, `FAILED`/`BLOCKED` | Inconclusivo |
| Gateway retorna erro depois da chamada | Tentativa/resultado incerto | `RECONCILIATION_REQUIRED` | Inconclusivo |
| Gateway confirma sucesso, leitura imediata falha | Incerto, sem confirmação | `RECONCILIATION_REQUIRED` | Inconclusivo |
| Gateway confirma sucesso e mesma publicação/Form ativo, mas inventário ou outra condição posterior diverge | `MUTATION_CONFIRMED` canônico | `RECONCILIATION_REQUIRED` | Pode confirmar após leitura estrutural saudável |
| Gateway e leitura confirmam, estrutura saudável | `MUTATION_CONFIRMED` canônico | `SUCCEEDED` | Não requer reconciliação |
| Gravação de sucesso falha depois de B2 confirmar | Evidência forte repassada à tentativa de transição para reconciliação | `RECONCILIATION_REQUIRED` se a gravação dessa transição funcionar | Pode confirmar após leitura saudável; se ledger falhar, estado permanece sujeito à recuperação conservadora |
| Exceção antes de resultado tipado de B2 | Sem evidência confiável | `RECONCILIATION_REQUIRED` | Inconclusivo |

Não se interpreta texto de exceções nem estado `ACTIVE` isolado como causalidade. Não há retry automático de ativação.

## Compatibilidade histórica

Não há migração ou backfill; schema permanece `0.8.0`, plugin passa de `0.9.2` para `0.9.3` para publicar esta correção de segurança causal. Linhas antigas sem o objeto canônico permanecem sem evidência suficiente. `mutation_attempted` e `gateway.mutation_attempted` legados são tratados como fracos. Embora uma versão anterior do reconciliador aceitasse `activation_mutation_confirmed`, não foi encontrada uma origem histórica que prove a semântica desse campo; por isso o campo legado não é promovido a confirmação. O incidente B3B2 continua `INCONCLUSIVE / INSUFFICIENT_CAUSAL_EVIDENCE` sem reinterpretação.

## Prova automatizada e próxima etapa

O teste de alcançabilidade usa gateway, leitor e ledger em memória: o Form passa de inativo para ativo uma vez; uma leitura pós-ativação encontra drift de inventário; B2 gera evidência forte; B3B1 persiste `RECONCILIATION_REQUIRED`; uma leitura posterior saudável resulta em `CONFIRMED_SUCCESS`. O ledger original e seu erro são preservados, e a reconciliação não chama o gateway de ativação. Testes separados cobrem flags legadas fracas, versões/tipos/origens incorretos e tentativas de forjar evidência pela REST.

Nenhum formulário, Resource, Entry, operação ou ledger do WordPress físico foi alterado nesta fase. A prova física futura exige uma fixture nova e legítima, com fluxo autenticado normal, incidente pós-mutação real, evidência canônica persistida e leitura estrutural saudável. Essa prova não faz parte da C1.
