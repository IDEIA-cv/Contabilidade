# 6. API (`api.php`)

API JSON local, usada pela interface. Pode também ser usada por scripts na mesma máquina.

## Convenções

- URL: `api.php?action=<ação>&c=<empresa>&…`
- Ações de leitura aceitam **GET**; as restantes exigem **POST** com o cabeçalho `X-Requested-With: IDEIA`.
- Corpo do POST: JSON (`Content-Type: application/json`) ou `multipart/form-data` (envio de ficheiros, campo `file`; os campos `map` e `options` podem vir como texto JSON).
- Resposta: `{"ok": true, "data": …}` ou `{"ok": false, "error": "mensagem"}` com HTTP 400 (erro de utilizador) ou 500 (erro interno, registado com `error_log`).
- Ações de descarga devolvem o ficheiro diretamente (`Content-Disposition: attachment`).
- Parâmetros comuns: `c` (id da empresa), `year` (exercício), `from` e `to` (meses 1–12).
- Só responde a pedidos locais, salvo `allow_remote = true` em `config.php`.

Exemplo:

```bash
curl 'http://localhost/contabilidade/api.php?action=reports.trial&c=empresa-demonstracao-lda&year=2025&from=1&to=10'
curl -X POST -H 'X-Requested-With: IDEIA' -H 'Content-Type: application/json' \
     -d '{"c":"empresa-demonstracao-lda","id":42}' 'http://localhost/contabilidade/api.php?action=entries.validate'
```

## Ações globais (sem empresa)

| Ação | Método | Parâmetros | Devolve |
|------|--------|-----------|---------|
| `ping` | GET | — | versão da aplicação, PHP e SQLite |
| `companies.list` | GET | — | lista de empresas (id, nome, NIF, exercícios, tamanho, data de alteração) |
| `companies.create` | POST | `name`, `nif`, `year`, `address`, `city`, `regime` | empresa criada |
| `companies.restore` | POST | ficheiro `.ideia` | empresa restaurada (sempre com id novo) |
| `portfolio` | GET | — | carteira: KPIs e alertas de todas as empresas |

## Empresa, plano e terceiros

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `company.info` | GET | — (faz a cópia automática diária, se devida; devolve nome, NIF, morada, regime, ficheiro, exercícios, diários, códigos de IVA, nº de rascunhos) |
| `company.update` | POST | `name`, `nif`, `address`, `city`, `regime` |
| `accounts.list` | GET | — (com saldo, nível e indicação de movimento) |
| `accounts.save` | POST | `code`, `name`, `active` |
| `thirdparties.list` | GET | `q` (pesquisa) |
| `thirdparties.save` | POST | `id?`, `nif`, `name`, `kind` (C/F/A/O), `address`, `city`, `country`, `email`, `phone`, `account` |

## Exercícios

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `years.create` | POST | `year` |
| `years.lock` | POST | `year`, `until` (data; vazio desbloqueia) |
| `years.close` | POST | `year` |
| `years.open_next` | POST | `year` (o que fecha); devolve `{year, entry}` |

## Lançamentos

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `entries.list` | GET | `year`, `journal`, `status`, `account`, `from`, `to` (datas), `q`, `limit`, `offset` |
| `entries.get` | GET | `id` (com linhas e anexos) |
| `entries.save` | POST | `id?`, `date`, `journal`, `description`, `document`, `lines[]`, `validate` (bool) |
| `entries.validate` | POST | `id` |
| `entries.delete` | POST | `id` (só rascunhos) |
| `entries.reverse` | POST | `id`, `date?` |
| `attachments.list` | GET | `entry_id` |
| `attachments.upload` | POST | ficheiro (`file`), `entry_id` |
| `attachments.download` | GET | `id`, `inline=1` (abre PDF/imagem no browser) |
| `attachments.delete` | POST | `id` (só em rascunhos) |

Cada linha: `account`, `debit` ou `credit` (texto, ex. `"1.234,56"`) **ou** `debit_cents`/`credit_cents`, `third_party` (id), `description`, `vat_code`, `vat_base` ou `vat_base_cents`.

## Reconciliação bancária

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `bank.accounts` | GET | — contas de depósitos com nº de movimentos e pendentes |
| `bank.template` | GET | — modelo CSV de extrato |
| `bank.preview` / `bank.import` | POST | ficheiro, `account`, `opening?` (saldo inicial); a importação concilia automaticamente |
| `bank.statements` | GET | `account` |
| `bank.delete_statement` | POST | `id` |
| `bank.pending` | GET | `account` — pendentes dos dois lados e resumo |
| `bank.set_start` | POST | `account`, `date` (vazio repõe o valor por omissão) |
| `bank.match` | POST | `account`, `bank_ids[]`, `ledger_ids[]` (totais iguais) |
| `bank.unmatch` | POST | `match_id` |
| `bank.matches` | GET | `account` — últimas 100 conciliações |
| `bank.auto` | POST | `account` |
| `bank.create_entry` | POST | `bank_line_id`, `counterpart`, `description?`, `third_party?` |
| `bank.report` | GET | `account`, `date?` (por omissão, a data do último movimento do extrato) |

## Vendas

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `sales.meta` | GET | — tipos de documento, conversões possíveis, meios de pagamento, motivos de isenção (código => menção), unidades comuns, motivos das notas, limite do talão, `efatura` (ativa) |
| `items.list` | GET | `q?`, `kind?` (P/S), `family?` (id ou `none`), `active?` (1, 0, all) |
| `items.save` | POST | `id?`, `code?` (automático), `name`, `kind`, `unit`, `price` ou `price_cents`, `vat_code`, `exemption?`, `account?`, `barcode?`, `family_id?`, `active?` |
| `items.delete` | POST | `id` — apaga se nunca usado, senão inativa |
| `items.families` | GET | — famílias com o número de artigos |
| `items.family_save` | POST | `id?`, `name`, `kind`, `vat_code?`, `exemption?`, `account?`, `active?` |
| `items.family_delete` | POST | `id` — os artigos ficam sem família |
| `sales.list` | GET | `type?` (código, `faturas` ou `creditaveis` = FT, FR, TV, ND), `status?`, `pay?` (aberto, vencido, pago), `party?`, `q?`, `from?`, `to?`, `limit`, `offset` |
| `sales.get` | GET | `id` |
| `sales.save` | POST | `id?`, `type`, `date`, `due_date?`, `third_party?`, `customer_name?`/`customer_nif?` (FR e TV sem ficha), `reference?`, `notes?`, `payment_method?`, `delivery_place?`, `origin_id?`, `issue_reason?` (NC, ND), `lines[]`, `issue?` |
| `sales.issue` | POST | `id` — atribui número, hash e lançamento |
| `sales.cancel` | POST | `id`, `reason` |
| `sales.delete` | POST | `id` (só rascunhos) |
| `sales.derive` | POST | `id`, `type`, `mode` (convert, credit, copy), `link?` (só em copy: associa a cópia ao original) — novo rascunho a partir de outro documento |
| `sales.link` | POST | `id`, `other` — associa dois documentos do mesmo cliente; devolve o documento com `links` |
| `sales.unlink` | POST | `id`, `other` — remove a associação |
| `sales.pay` | POST | `id` (FT/ND), `date`, `amount?` (por omissão o valor em dívida), `payment_method`, `notes?` — emite recibo |
| `sales.print` | GET | `id` — página HTML A4 |
| `sales.export_zip` | GET | `ids` (lista separada por vírgulas) ou os mesmos filtros de `sales.list` — ZIP com HTML, XML da e-Fatura e CSV |
| `sales.summary` | GET | `year` — faturado, por receber, vencido, rascunhos, orçamentos em aberto |
| `sales.debtors` | GET | `q?`, `overdue?`, `as_of?` — dívida por cliente com antiguidade |
| `sales.customer` | GET | `id`, `year` — ficha do cliente com faturas por pagar |
| `sales.statement` | GET | `id`, `from`, `to` — movimentos dos documentos de venda do cliente |
| `party.statement` / `party.statement_print` | GET | `id` (cliente ou fornecedor), `year`, `from?`, `to?`, `account?` — extrato de conta corrente a partir da contabilidade (JSON / página A4) |
| `sales.series` / `sales.verify` | GET | — numeração por série / verificação da cadeia |
| `company.logo` | POST | ficheiro (`file`) ou `remove=1` |

Linhas: `item_id?`, `description`, `qty` (texto, ex. `"1,5"`) ou `qty_milli`, `price` ou `price_cents`, `discount` (%) ou `discount_bp`, `vat_code`, `exemption?` (código oficial, 1–21), `unit?` (código UN/ECE, ex. `EA`, `KGM`), `account?`.

## e-Fatura

| Ação | Método | Parâmetros / resposta |
|---|---|---|
| `efatura.overview` | GET | — configuração (sem a chave), o que falta, contagens por estado, repositórios, motivos de contingência, documentos oficiais, ligações, extensões PHP |
| `efatura.codes` | GET | — listas oficiais: isenções, unidades, motivos das notas |
| `efatura.save` | POST | `enabled`, `since`, `repo` (1/2/3), `led`, `software_code`, `transmitter_nif`, `contingency_reason`, `mw_url`, `mw_key?`, `mw_key_clear?`, `mw_verify` |
| `efatura.test` | POST | — `{ok, message}` do pedido ao Middleware |
| `efatura.documents` | GET | `status?`, `from?`, `to?` — documentos fiscais com estado, IUD e resposta; `counts`; `since` |
| `efatura.check` | GET | `id` — `{ok, errors[]}` (dados em falta ou erros do XSD) |
| `efatura.xml` | GET | `id` — descarrega `<IUD>.xml` (sem assinatura) |
| `efatura.send` | POST | `ids[]` ou `pending=1` — resultado por documento (`status`, `message`) |
| `efatura.exclude` | POST | `ids[]`, `exclude` (true = não comunicar, false = repor na fila) |
| `efatura.doc` | GET | `id` (manual, certificado, motivos, unidades) — ficheiro oficial |
| `efatura.received_preview` | POST | ficheiro XML ou ZIP — `rows[]` (`status` novo/duplicado/ignorado/erro, `message`, tipo, número, fornecedor, IVA, total) e `counts` |
| `efatura.received_import` | POST | ficheiro, `register` (registar quando o fornecedor tem conta habitual) — `rows[]` com `created_id`, `created` |
| `efatura.conference` | GET | `year` — `rows[]` por mês (IVA das vendas por estado, IVA das compras da e-Fatura e manuais, contabilidade, diferenças, `before_since`), `totals`, `manual_docs[]` |

## Compras

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `purchases.meta` | GET | — tipos (FC, FP, NF, PG), meios de pagamento, códigos de IVA de compras, contas permitidas (classes 3, 4, 6) |
| `purchases.list` | GET | `type?`, `status?`, `pay?` (aberto, vencido, pago), `party?`, `q?`, `from?`, `to?`, `limit`, `offset` |
| `purchases.export_zip` | GET | `ids` ou os filtros de `purchases.list` — ZIP com CSV e anexos |
| `purchases.get` | GET | `id` |
| `purchases.save` | POST | `id?`, `type`, `date`, `due_date?`, `third_party?`, `supplier_doc`, `payment_method?`, `notes?`, `origin_id?` (NF), `lines[]` (com `account`), `settlements[]` (PG), `register?` |
| `purchases.register` | POST | `id` — número interno e lançamento |
| `purchases.cancel` | POST | `id`, `reason` |
| `purchases.delete` | POST | `id` (só rascunhos) |
| `purchases.credit` | POST | `id` (FC registada) — rascunho de nota de crédito com as mesmas linhas |
| `purchases.pay` | POST | `invoices[]` ou `id`, `amount?` (só com uma fatura), `date`, `payment_method`, `notes?` |
| `purchases.creditors` | GET | `q?`, `overdue?`, `as_of?` — dívida por fornecedor com antiguidade |
| `purchases.schedule` | GET | `as_of?` — mapa de pagamentos |
| `purchases.supplier` | GET | `id`, `year` — ficha do fornecedor |
| `purchases.summary` | GET | `year` — compras, por pagar, vencido, próximos 7 dias, rascunhos |
| `purchases.last_account` | GET | `id` (fornecedor) — última conta usada |

## Acesso e diagnóstico

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `auth.status` | GET | — `{enabled, logged, user, role, roles}` (disponível sem sessão) |
| `auth.login` | POST | `username`, `password` (disponível sem sessão) |
| `auth.logout` | POST | — |
| `auth.setup` | POST | `username`, `name`, `password` — ativa os acessos com o primeiro contabilista |
| `auth.password` | POST | `current`, `password` — muda a palavra-passe do próprio |
| `auth.users` | GET | — lista de utilizadores (contabilista) |
| `auth.user_save` | POST | `username`, `name`, `role`, `password?`, `active?`, `existing?` (contabilista) |
| `auth.user_delete` | POST | `username` (contabilista) |
| `auth.disable` | POST | `password` — apaga os utilizadores e desliga os acessos (contabilista) |
| `system.check` | GET | — verificações da instalação, com instruções de correção |

Com acessos ativos, qualquer outra ação sem sessão responde HTTP 401 `{ok:false, auth:true}` e o browser mostra o ecrã de entrada. Uma ação fora do perfil (ver [utilizadores](17-utilizadores.md)) responde com erro: "Só leitura…" ou "…reservada ao contabilista". As ações do imobilizado (`assets.*`) estão em [16-imobilizado](16-imobilizado.md#api).

## Relatórios e IVA

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `reports.trial` | GET | `year`, `from`, `to`, `level?` |
| `reports.ledger` | GET | `account`, `year`, `from`, `to` |
| `reports.journal` | GET | `year`, `from`, `to`, `journal?` |
| `reports.balance` | GET | `year`, `to` |
| `reports.income` | GET | `year`, `from`, `to` (inclui `previous` se existir o ano anterior) |
| `vat.settlement` | GET | `year`, `from`, `to` |
| `vat.post` | POST | `year`, `from`, `to` |

## Estatísticas

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `stats.dashboard` | GET | `year`, `to?` |
| `stats.ratios` | GET | `year`, `to?` |
| `stats.costs` | GET | `year`, `from`, `to` |
| `stats.top` | GET | `year`, `kind` (C/F), `limit` |
| `stats.open` | GET | `year`, `kind` (C/F): saldos em aberto e antiguidade |
| `stats.forecast` | GET | `year` |
| `stats.budget` | GET | `year`, `to?` |
| `stats.budget_save` | POST | `year`, `account`, `months[12]` |
| `stats.custom` | GET | `year`, `to?` |
| `stats.custom_save` | POST | `items[]` = `{name, formula, unit}` |
| `stats.sales` | GET | `year`, `from?`, `to?`, `family?` (id, 0 = sem família), `party?` — vendas por artigo, família, cliente e mês, com margem |
| `stats.purchases` | GET | `year`, `from?`, `to?` — compras por fornecedor, conta e mês |
| `stats.terms` | GET | `year`, `from?`, `to?` — prazos reais de recebimento e pagamento |

## Integridade, cópias e importação

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `chain.verify` | GET | — devolve `{ok, count, broken_at?}` |
| `audit.list` | GET | — últimas 300 operações |
| `imports.list` | GET | — histórico de importações |
| `backup.create` | POST | — grava cópia em `backup_dir` |
| `backup.download` | GET | — descarrega cópia `.ideia` |
| `saft.export` | GET | `year`, `type` (C contabilidade, F completo) — descarrega `SAFT_CV_<NIF>_<ano>_<tipo>.xml` |
| `saft.validate` | GET | `year`, `type` — `{ok, errors[]}` contra o XSD SAF-T CV 1.01_01 |
| `saft.preview` | POST | ficheiro XML (ou `.gz`) |
| `saft.import` | POST | ficheiro, `map` (JSON `{código_origem: conta_destino}`), `create_opening` |
| `import.template` | GET | `type` = `contas`, `terceiros`, `saldos`, `lancamentos` |
| `csv.preview` / `csv.import` | POST | ficheiro, `type`, `year` (saldos), `validate` (1/0) |

## Acrescentar uma ação

1. Implemente a lógica numa classe de domínio (`src/`), com testes em `tests/unit/`.
2. Acrescente o `case` em `Api::dispatch()`; se for de leitura, junte o nome a `Api::READ`; se não precisar de empresa, a `Api::GLOBAL`.
3. Lance `UserError` para erros que o utilizador deve ver.
4. Documente-a nesta página.
