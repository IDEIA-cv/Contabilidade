# 4. Modelo de dados

Cada empresa é um ficheiro SQLite `data/<id>.ideia`. O esquema completo está em [`database/schema.sql`](../database/schema.sql); aqui explica-se o significado e as regras que o código garante.

## Diagrama

```
fiscal_years 1───* entries 1───* entry_lines *───1 accounts (código SNCRF, hierarquia por parent)
                     │  *                │ *
                     │  └─1 journals     ├───1 third_parties
                     │                   └───1 vat_codes
                     └ reverses / reversed_by (auto-referência: estornos)

budgets (account, year, month)     meta (chave/valor)     audit_log     import_log
```

## Tabelas

### `meta`
Chave/valor da empresa: `app` (= `ideia-contabilidade`, usado para reconhecer ficheiros no restauro), `schema_version`, `company_name`, `company_nif`, `company_address`, `company_city`, `tax_regime` (`contabilidade_organizada` ou `rempe`), `custom_indicators` (JSON com as fórmulas próprias), `created_at`.

### `fiscal_years`
Um registo por exercício (`year` único). `status` é `aberto` ou `fechado`. `locked_until` bloqueia lançamentos em datas até esse dia inclusive (para períodos já declarados).

### `accounts`
Plano de contas. `code` só com dígitos; `parent` é o maior prefixo existente (ex.: `2432` → `243`); `class` é o primeiro dígito; `level` é a profundidade. **Uma conta é "de movimento" se não tiver filhas**: só essas aceitam linhas de lançamento. Não se pode criar uma subconta debaixo de uma conta que já tem movimentos (a história ficaria na conta-mãe).

### `third_parties`
Clientes (`C`), fornecedores (`F`), ambos (`A`) ou outros (`O`). NIF de Cabo Verde validado com 9 dígitos quando `country = CV`. `account` é a conta corrente habitual (211, 221…). `external_id` guarda o código do sistema de origem numa importação SAF-T.

### `journals`
Diários. Por omissão: `ABE` abertura, `DIV` diversos, `VEN` vendas, `COM` compras, `CXA` caixa, `BCO` bancos, `SAL` salários, `APU` apuramento. `kind` (`normal`, `abertura`, `apuramento`) é usado pelos relatórios: a DR exclui o diário de apuramento e o saldo de abertura vem dos diários de abertura.

### `vat_codes`
`NOR` (IVA liquidado 15 %, conta 2433), `DED` (dedutível 15 %, 2432), `ISE` (isento). `rate_bp` em pontos base (1500 = 15 %).

### `entries`
Cabeçalho do lançamento.

| Campo | Regra |
|-------|-------|
| `status` | `rascunho` (editável, apagável, fora dos relatórios) ou `validado` (imutável) |
| `number` | Atribuído só na validação: sequencial por (`fiscal_year`, `journal`), sem buracos. `UNIQUE` |
| `total_cents` | Soma dos débitos (= soma dos créditos) |
| `source` | `manual`, `saft`, `csv`, `fecho`, `abertura`, `apuramento_iva`, `estorno` |
| `reverses` / `reversed_by` | Ligação entre um lançamento e o seu estorno |
| `validated_seq`, `hash`, `prev_hash` | Cadeia de integridade (ver [regras](05-regras-contabilisticas.md#integridade)) |

### `entry_lines`
Linhas com débito **ou** crédito em centavos (≥ 0; nunca os dois na mesma linha). `vat_code` e `vat_base_cents` alimentam o mapa de IVA por código e por terceiro. `cost_center` está reservado para analítica.

### `budgets`
Orçamento mensal por conta (qualquer nível: `62` orçamenta todos os FSE). Valor com o sinal natural (gastos e rendimentos positivos).

### `entry_attachments` (v2)
Documentos anexos aos lançamentos (PDF, imagens, XML, Excel, Word; até 10 MB cada). O conteúdo fica **dentro do ficheiro `.ideia`** (BLOB), por isso viaja com as cópias. Guarda nome, tipo, tamanho e SHA-256 (impede anexar o mesmo ficheiro duas vezes). Num lançamento validado podem acrescentar-se anexos mas não retirá-los; os anexos não entram no hash do lançamento.

### `audit_log`
Registo de operações (`empresa.criada`, `conta.criada`, `lancamento.validado`, `lancamento.apagado`, `exercicio.fechado`, `periodo.bloqueado`, `importacao.saft`, `importacao.csv`, `saft.exportado`, `copia.seguranca`…), com detalhes em JSON.

### `import_log`
Histórico de importações com o ficheiro original comprimido (gzip) em `content`, para auditoria.

## Vista `v_movements`

Junta linhas, cabeçalho e tipo de diário **só dos lançamentos validados**. Todos os relatórios e estatísticas leem desta vista: um rascunho nunca entra nos números.

## Invariantes garantidas pelo código

1. Lançamento validado: débito total = crédito total, pelo menos 2 linhas, todas as contas de movimento e ativas, data dentro de um exercício aberto e depois de `locked_until`.
2. Lançamento validado nunca é alterado nem apagado: corrige-se com estorno.
3. Numeração sequencial sem buracos por diário e exercício.
4. Cadeia de hash contínua pela ordem `validated_seq`.
5. Exercício fechado não aceita lançamentos.

## Versões do esquema

`Db::SCHEMA_VERSION` (atualmente `3`) é gravado em `meta.schema_version`. Ao abrir um ficheiro de versão inferior, `Db::migrate()` aplica as migrações em falta; um ficheiro de versão superior é recusado ("atualize a aplicação"). As migrações nunca alteram dados de lançamentos validados.

| Versão | Alteração |
|--------|-----------|
| 1 | Esquema inicial |
| 2 | Tabela `entry_attachments` (documentos anexos) |
| 3 | Reconciliação bancária: `bank_statements`, `bank_lines`, `bank_matches`, `bank_match_ledger` (ver [reconciliação](11-reconciliacao-bancaria.md#dados-esquema-v3)) |
| 4 | Índices de desempenho: `ix_entries_seq` (`entries.validated_seq`), `ix_entries_year` (`fiscal_year, status, date`), `ix_audit_action` (`audit_log.action, ts`) |
| 5 | Vendas: `items`, `doc_series`, `sales_docs`, `sales_doc_lines`, `sales_settlements` (ver [vendas](12-vendas.md#esquema-v5-e-v6)) |
| 7 | Compras: `purchase_docs`, `purchase_doc_lines`, `purchase_settlements` (ver [compras](14-compras.md#esquema-v7)) |
| 6 | Famílias de artigos: tabela `item_families` e coluna `items.family_id` (primeira migração com `ALTER TABLE`, feita em `Db::migrate`) |
