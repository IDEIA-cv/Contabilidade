# 9. Testes

## Testes unitários (PHP)

```bash
php tests/run.php
```

- Executor próprio, sem PHPUnit (o projeto não tem dependências). Cada ficheiro `tests/unit/*Test.php` devolve um array `['nome do teste' => function () { … }]` e usa as asserções `same()`, `check()` e `throws()` definidas em `tests/run.php`.
- `php tests/run.php <filtro>` corre só os testes cujo nome contém o filtro.
- Cada execução usa uma pasta de dados temporária (nunca toca em `data/`).
- `tests/helpers.php` → `demoCompany()` cria uma empresa com capital, uma venda com IVA, uma compra com IVA e um recebimento, com valores conhecidos.

| Ficheiro | Cobre |
|----------|-------|
| `MoneyTest.php` | Conversão de formatos de valores e formatação |
| `LedgerTest.php` | Plano de contas, validação, numeração, estorno, cadeia de hash e deteção de adulteração, balancete, balanço equilibrado, DR, IVA, fecho e abertura |
| `AttachmentsBackupTest.php` | Anexos (binário intacto, duplicados, tipos recusados, proteção em validados), migração v1→v2, cópia automática diária |
| `BankTest.php` | Leitura de extratos (cabeçalho do banco, Windows-1252, débito/crédito ou montante), duplicados, conciliação automática e manual, mapa, criação de lançamento, apagar extrato |
| `ImportStatsTest.php` | SAF-T (pré-visualização, conflitos, importação com abertura), CSV (erros e tudo-ou-nada), exportação SAF-T, painel, rácios, orçamento, fórmulas |

Estado atual: **26 testes, todos a passar**.

## Teste no browser (Playwright)

```bash
php -S 127.0.0.1:8765 &                      # com config.php a apontar para uma pasta de dados de teste
php tools/seed-demo.php /pasta/de/teste      # empresa de demonstração
node tests/e2e/smoke.mjs                     # BASE e COMPANY configuráveis por variáveis de ambiente
```

O teste percorre **todos os ecrãs** (25), cria uma empresa nova, faz e valida um lançamento, anexa-lhe um PDF importa um extrato bancário, cria o lançamento da comissão a partir do extrato e confirma diferença zero na reconciliação, e exporta o balancete para Excel (verifica que o ficheiro é um XLSX). Falha se houver erros na consola do browser, exceções de JavaScript, respostas 5xx da API ou alertas de erro no ecrã. Guarda capturas em `tests/e2e/output/` (ignorado pelo Git).

Requer Node.js e o pacote `playwright` com Chromium disponível.

## Desempenho

`php tools/bench.php [n] [pasta]` cria uma empresa com *n* lançamentos (por omissão 50 000) e mede as operações principais. Referência (50 000 lançamentos, 125 000 linhas, ficheiro de 30 MB, portátil comum):

| Operação | Tempo |
|---|---|
| Criar e validar 50 000 lançamentos | 7,8 s (0,16 ms cada) |
| Lista de lançamentos, balancete, balanço, DR, IVA | 26–160 ms |
| Extrato de conta (ano) | 125–190 ms |
| Painel / carteira de empresas | 0,5 s |
| Verificar cadeia de integridade | 0,3 s (resultado guardado em `meta.chain_check`) |
| Exportar SAF-T | 1,2 s |

As listas grandes são paginadas no servidor: lançamentos (100 por página), razão (500 linhas, saldo acumulado contínuo entre páginas) e diário (200 lançamentos). As exportações Excel/CSV trazem sempre o conjunto completo.

## Acessibilidade e ecrãs pequenos

Auditoria com axe-core (WCAG 2 A/AA) em 13 páginas: 0 violações. Em 390 px de largura nenhuma página tem deslocamento horizontal; tabelas largas deslizam dentro do seu contentor. Há foco visível (`:focus-visible`), rótulos em todos os campos e ligações sublinhadas em texto.

## Antes de cada push

1. `php -l` nos ficheiros alterados (ou `find src -name '*.php' -exec php -l {} \;`).
2. `php tests/run.php`.
3. `node tests/e2e/smoke.mjs` se mexeu na interface ou na API.
