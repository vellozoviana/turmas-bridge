# P3 — observabilidade futura

Somente plano, nenhum observador instalado. Separar observação de mutação.
Não registrar secret, assinatura/header HMAC, nonce, cookie, licença, motivo
com PII, dumps completos ou payload de Entry. Vetores de testes unitários
são fictícios e não são credenciais operacionais.

Para cada checkpoint coletar:
- hora UTC, operação/key sanitizada ou hash correlacionável, publication/class;
- origem EPF row_version, Vagas, capacidade desejada, ledger state/revision,
  attempts, error_code, created_at/updated_at;
- Bridge ledger before/after capacity/consumed e histórico de observação;
- Form ID/state, Resource ID/owner, limite do postmeta, cada field/choice
  correspondente e limite, conjunto exato de gpi_field;
- contagens de Forms, Resources, mappings, Entries da fixture nova;
- contadores de GF update e postmeta write se observador seguro for aprovado;
- duração/timeout dos locks, CAS afetou 0/1 linha, resultado HTTP redigido.

Zero escrita exige evidência de chamada/escrita, não apenas valor final igual.
Se instrumentação física não estiver aprovada/disponível, classificar esse
item NÃO OBSERVADO, sem inferir PASS. Não instalar logging global com dados
de usuários. Na P3-D só foram usados doubles/injeção de falhas em memória.

GET /capacidades/{key} é histórico read-only de produto; autenticação consome
nonce no mecanismo anti-replay. Reconciliação é read-only para inventário,
mas grava transição/evidência no ledger. Diferenciar essas categorias.
