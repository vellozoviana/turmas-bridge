# Checklist de homologação — Fase 11

Este checklist prepara a validação do TURMAS-BRIDGE contra um WordPress com
Gravity Forms institucional. A Fase 11 é exclusivamente de leitura: não cria,
clona, atualiza, exclui ou publica formulários e não lê inscrições.

## 1. Ambiente recomendado

- [ ] Há ambiente de *staging/homologação* separado da produção pública.
- [ ] O ambiente usa HTTPS válido e URL definitiva para o teste.
- [ ] Há backup verificável antes da instalação ou ativação.
- [ ] A equipe definiu uma janela de teste e responsável técnico.
- [ ] O acesso administrativo WordPress é individual; não compartilhar senha.

Se só houver produção, o teste requer autorização explícita do responsável e
deve usar um formulário-modelo sem inscrições reais.

## 2. Inventário técnico a registrar

- [ ] Versão do WordPress, PHP e MySQL/MariaDB.
- [ ] Versão do Gravity Forms, confirmação de que está ativo e licença válida.
- [x] Referência institucional identificada: formulário 199, sem leitura de
  entries; título e demais detalhes sensíveis permanecem fora do repositório.
- [ ] Presença e versão de GP Inventory e FlowSheet, se existirem.
- [ ] Presença do InscriHub e nome/versão do plugin, se instalado.
- [ ] URL base do ambiente e responsável por sua operação.

Não registrar em tickets ou repositórios senhas, chaves, CPFs, e-mails de
inscritos, exports de entries ou o segredo HMAC.

## 3. Preparação do bridge

- [ ] Plugin TURMAS-BRIDGE obtido do commit aprovado pela equipe.
- [ ] Nenhuma pasta `vendor/`, `.env`, log ou configuração local acompanha o
  pacote publicado.
- [ ] Segredo aleatório exclusivo do ambiente gerado e guardado em cofre de
  segredos; nunca enviado por chat ou commit.
- [ ] Segredo configurado em `Configurações > Turmas Bridge` por administrador.
- [ ] Não habilitar `TURMAS_BRIDGE_ALLOW_INSECURE_LOCAL` fora de ambiente local.
- [ ] Confirmar que o endpoint público usa HTTPS.

## 4. Formulário-modelo

- [ ] O formulário escolhido é apenas um template; não é alterado no teste.
- [ ] A equipe confirmou que não há entries reais a serem acessadas.
- [ ] Foram identificados, apenas para conferência humana, os campos de Turma,
  Formação e identificador do participante.
- [ ] O mapeamento futuro será confirmado por `field ID` e `adminLabel` ou
  `inputName`, não somente por label visível.
- [x] O modelo usa 11 campos separados de Turma por CRE; o campo 195 é o
  seletor obrigatório da 1ª CRE e possui inventário por choice.
- [ ] Definir, em fase posterior, o choice `value` estável e o controlador da
  lógica condicional; não inferir pelo texto visível.
- [ ] Identificar tecnicamente a associação FlowSheet e o contrato de
  InscriHub antes de qualquer escrita.

## 5. Critérios de aceite da Fase 11

- [ ] `/ping` assinado responde sem expor segredo ou diagnóstico detalhado.
- [ ] A consulta assinada ao template retorna somente o manifesto permitido.
- [ ] Não há entries, dados pessoais, feeds, notificações ou propriedades
  internas na resposta.
- [ ] A consulta não altera formulário, inscrições, tabelas ou opções.
- [ ] Requisição sem assinatura, assinatura inválida e nonce repetido são
  bloqueados.
- [ ] Evidências técnicas foram registradas sem dados sensíveis.

## 6. Pendências que não devem ser decididas no teste

- Contrato oficial do padrão Fênix.
- Mapeamento definitivo de cada campo institucional.
- Estratégia de clone/publicação e qualquer operação de escrita da Fase 12.
- Regras de GP Inventory, FlowSheet e registro futuro no InscriHub.
