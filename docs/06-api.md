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
| `company.info` | GET | — (nome, NIF, morada, regime, ficheiro, exercícios, diários, códigos de IVA, nº de rascunhos) |
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
| `entries.get` | GET | `id` (com linhas) |
| `entries.save` | POST | `id?`, `date`, `journal`, `description`, `document`, `lines[]`, `validate` (bool) |
| `entries.validate` | POST | `id` |
| `entries.delete` | POST | `id` (só rascunhos) |
| `entries.reverse` | POST | `id`, `date?` |

Cada linha: `account`, `debit` ou `credit` (texto, ex. `"1.234,56"`) **ou** `debit_cents`/`credit_cents`, `third_party` (id), `description`, `vat_code`, `vat_base` ou `vat_base_cents`.

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

## Integridade, cópias e importação

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `chain.verify` | GET | — devolve `{ok, count, broken_at?}` |
| `audit.list` | GET | — últimas 300 operações |
| `imports.list` | GET | — histórico de importações |
| `backup.create` | POST | — grava cópia em `backup_dir` |
| `backup.download` | GET | — descarrega cópia `.ideia` |
| `saft.export` | GET | `year` — descarrega `SAFT_CV_<NIF>_<ano>_C.xml` |
| `saft.preview` | POST | ficheiro XML (ou `.gz`) |
| `saft.import` | POST | ficheiro, `map` (JSON `{código_origem: conta_destino}`), `create_opening` |
| `import.template` | GET | `type` = `contas`, `terceiros`, `saldos`, `lancamentos` |
| `csv.preview` / `csv.import` | POST | ficheiro, `type`, `year` (saldos), `validate` (1/0) |

## Acrescentar uma ação

1. Implemente a lógica numa classe de domínio (`src/`), com testes em `tests/unit/`.
2. Acrescente o `case` em `Api::dispatch()`; se for de leitura, junte o nome a `Api::READ`; se não precisar de empresa, a `Api::GLOBAL`.
3. Lance `UserError` para erros que o utilizador deve ver.
4. Documente-a nesta página.
