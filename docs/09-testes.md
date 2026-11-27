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
| `ImportStatsTest.php` | SAF-T (pré-visualização, conflitos, importação com abertura), CSV (erros e tudo-ou-nada), exportação SAF-T, painel, rácios, orçamento, fórmulas |

Estado atual: **22 testes, todos a passar**.

## Teste no browser (Playwright)

```bash
php -S 127.0.0.1:8765 &                      # com config.php a apontar para uma pasta de dados de teste
php tools/seed-demo.php /pasta/de/teste      # empresa de demonstração
node tests/e2e/smoke.mjs                     # BASE e COMPANY configuráveis por variáveis de ambiente
```

O teste percorre **todos os ecrãs** (25), cria uma empresa nova, faz e valida um lançamento, anexa-lhe um PDF e exporta o balancete para Excel (verifica que o ficheiro é um XLSX). Falha se houver erros na consola do browser, exceções de JavaScript, respostas 5xx da API ou alertas de erro no ecrã. Guarda capturas em `tests/e2e/output/` (ignorado pelo Git).

Requer Node.js e o pacote `playwright` com Chromium disponível.

## Antes de cada push

1. `php -l` nos ficheiros alterados (ou `find src -name '*.php' -exec php -l {} \;`).
2. `php tests/run.php`.
3. `node tests/e2e/smoke.mjs` se mexeu na interface ou na API.
