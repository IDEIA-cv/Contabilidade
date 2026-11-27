# 3. Arquitetura

## Visão de conjunto

```
Browser (SPA, módulos ES, sem bibliotecas)
   │  fetch JSON  (GET leituras / POST escritas + X-Requested-With: IDEIA)
   ▼
api.php ──► Ideia\Api::handle()  ── guarda (localhost, método, cabeçalho)
                │ dispatch(action)
                ▼
   Domínio (src/): Ledger, Reports, Vat, Closing, Stats, Saft\*, Import\Csv, Companies
                │ PDO
                ▼
   data/<empresa>.ideia   (SQLite, um ficheiro por empresa)
```

Não há framework nem dependências: PHP 8 com PDO SQLite no servidor e JavaScript nativo no browser. Gráficos em SVG gerado à mão (`assets/js/charts.js`), por isso tudo funciona sem internet.

## Pastas

| Pasta / ficheiro | Conteúdo |
|------------------|----------|
| `index.php` | Página única: barra lateral, barra superior, área `#view`, modal e toasts |
| `api.php` | Ponto de entrada da API (uma linha: `Api::handle()`) |
| `src/` | Domínio em PHP, namespace `Ideia\` (autoload PSR-4 em `src/bootstrap.php`) |
| `src/Saft/` | `Exporter` e `Importer` do SAF-T |
| `src/Import/` | `Csv` (modelos, leitura, validação) e `ImportFailed` |
| `database/schema.sql` | Esquema SQLite |
| `database/sncrf.php` | Plano de contas base |
| `assets/js/` | `main.js` (estado, rotas, cromado), `api.js`, `util.js`, `charts.js`, `xlsx.js` (exportação Excel sem bibliotecas), `selection.js` (seleção de documentos e exportação ZIP), `theme.js` (tema escolhido no rodapé do menu, carregado no `<head>`) |
| `assets/js/views/` | Um módulo por ecrã: `home`, `dashboard`, `entries`, `accounts`, `parties`, `bank`, `reports`, `vat`, `stats`, `imports`, `settings` |
| `assets/css/app.css` | Tema: automático (segue o sistema), claro ou escuro (`data-theme` em `<html>`, guardado em `localStorage` `ideia.theme`); layout, impressão |
| `tools/` | Utilitários de linha de comando (`seed-demo.php`) |
| `tests/` | Testes unitários (`run.php`, `unit/`), fixtures e teste no browser (`e2e/smoke.mjs`) |
| `data/` | Ficheiros das empresas (ignorado pelo Git) |
| `docs/` | Esta documentação |

## Classes do domínio

| Classe | Responsabilidade |
|--------|------------------|
| `Config` | Lê `config.php` com valores por omissão; `override()` para testes e ferramentas |
| `Db` | Envolve o PDO: `all/one/value/run/insert`, `transaction()`, `meta()/setMeta()`, `audit()`; cria o esquema num ficheiro novo |
| `Companies` | Lista, cria, abre, cópia (`VACUUM INTO`) e restauro de empresas; valida o identificador |
| `Bank` | Reconciliação bancária: leitura de extratos, conciliação automática e manual, mapa |
| `Attachments` | Documentos anexos aos lançamentos (guardados no ficheiro da empresa) |
| `Money` | Converte texto ↔ centavos (aceita `1.234,56`, `1 234,56`, `1234.56`, `(50)`) e formata |
| `Ledger` | Plano de contas, diários, códigos de IVA, exercícios, terceiros, lançamentos (guardar, validar, estornar), cadeia de hash |
| `Reports` | Saldos por conta folha, balancete, razão, diário, balanço, DR |
| `Vat` | Apuramento do IVA e lançamento de apuramento |
| `Closing` | Fecho do exercício e abertura do seguinte |
| `Stats` | Painel, rácios, gastos, terceiros, antiguidade de saldos, previsão, orçamento, fórmulas próprias, carteira |
| `Saft\Exporter` / `Saft\Importer` | SAF-T de contabilidade |
| `Import\Csv` | Importação de folhas CSV |
| `Api` | Encaminhamento HTTP → domínio, serialização JSON, descargas e envios de ficheiros |
| `UserError` | Exceção com mensagem para o utilizador (HTTP 400); outras exceções dão HTTP 500 |

As classes de domínio são `final` com métodos estáticos que recebem a ligação `Db` como primeiro argumento. Não guardam estado entre pedidos.

## Fluxo de um pedido

1. A vista chama `api.get('reports.trial', { year, from, to })`. O `api.js` junta o parâmetro `c` (empresa ativa) e, nos POST, o cabeçalho `X-Requested-With`.
2. `Api::handle()` valida origem e método, junta parâmetros GET, corpo JSON ou multipart, e abre a empresa com `Companies::open($c)` (salvo nas ações globais).
3. `dispatch()` chama o domínio e devolve `{ "ok": true, "data": … }` ou `{ "ok": false, "error": "…" }`. Um objeto `Download` é enviado como ficheiro.

## Front-end

- **Rotas por hash**: `#/` (empresas) e `#/c/<empresa>/<secção>[/<sub>][?query]`. `main.js` resolve a rota, importa a vista e chama `render(ctx)` com `{ view, company, section, sub, q }`.
- **Estado** em `state` (`company`, `year`, `info`). O exercício escolhido fica em `localStorage` por empresa.
- **Cromado**: seletor de empresa e de exercício, emblema de rascunhos, migalhas.
- Gráficos clicáveis levam ao detalhe (lançamentos do mês, extrato da conta), segundo o princípio "do número ao documento".
- **Exportação Excel**: `xlsx.js` converte qualquer tabela num `.xlsx` verdadeiro (OOXML + ZIP sem compressão, CRC32 próprio), com valores numéricos, formato `#,##0.00`, cabeçalhos a negrito e título. Funciona offline e não precisa de extensões do PHP.
- Estilos de impressão escondem a navegação: qualquer relatório imprime-se ou guarda-se em PDF pelo browser.

## Concorrência e transações

- Cada escrita relevante corre em `Db::transaction()`. A validação de lançamentos atribui número e hash dentro da mesma transação.
- SQLite em `journal_mode=DELETE` (não WAL) para que a empresa seja sempre **um só ficheiro**, fácil de copiar. Com um utilizador por computador não há perda de desempenho visível.
