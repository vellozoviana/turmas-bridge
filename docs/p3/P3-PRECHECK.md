# P3 — precheck físico futuro

Não executar agora. Gate humano obrigatório.

- Aprovação de ambos os commits e lista exata de alterações.
- Confirmar host físico local do operador; não usar localhost do agente remoto
  para inferir disponibilidade do Laragon.
- Backup recente válido, restauração planejada e janela de manutenção.
- Autorizar e verificar runtime EPF 0.14.0/schema 0.8.0 e Bridge 0.11.0/schema
  0.9.0; revalidar GF/GP Inventory compatíveis, caches/opcache e symlinks.
- Migration física: verificar tabelas, colunas, defaults, nullability, índices
  únicos, engines InnoDB, dados legados preservados e repetição idempotente.
  Testes de DDL/doubles não substituem esse item.
- Capability/nonce/escopo e HMAC normais, nenhuma credencial no relatório.
- Zero colisões da fixture proposta; lista protegida e baseline histórica.
- Nenhum comando anterior APPLYING/RECONCILIATION_REQUIRED para a nova classe.
- Identidades form/resource/mapping/bindings coerentes e Form fechado/drenado
  para cenários que podem aplicar capacidade.
- Observabilidade suficiente para distinguir zero escrita de valor igual.
- Autorização separada antes de Entries, ativação, instrumentação ou faults.
- Se qualquer item faltar: STOP, sem conserto/retry automático.
