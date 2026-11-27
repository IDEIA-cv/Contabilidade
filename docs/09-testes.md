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
| `SalesTest.php` | Artigos, cálculo de linhas (desconto, IVA, isenção), numeração por tipo/série e ordem das datas, lançamento e IVA, recibos parciais, notas de crédito, anulação, conversão, dívidas por antiguidade, extrato de cliente e de fornecedor (contabilidade), famílias de artigos e migração v5→v6, impressão |
| `PurchasesTest.php` | Faturas de fornecedor (validações, lançamento, IVA dedutível, conta sugerida), pagamentos parciais e de várias faturas, nota de crédito, anulação, despesa paga, dívidas por antiguidade, mapa de pagamentos, ficha e extrato, migração v6→v7 |
| `EfaturaTest.php` | IUD e dígito Luhn (exemplo do manual), códigos oficiais de isenção e unidades, configuração, XML de FT, FR, TV, RC, NC, ND, GE e GT validado com o XSD oficial, numeração comum das guias, documentos anteriores à adesão, envio simulado pelo Middleware (autorizado, rejeitado, sem ligação), QR e aviso de contingência na impressão, migração v7→v8 |
| `EfaturaReceivedTest.php` | Leitura dos DFE recebidos, importação de FT, NC (com a fatura de origem) e FR a partir de XML e ZIP, duplicados, recibos e vendas próprias ignorados, fornecedor novo, registo automático, conferência do IVA (incluindo meses antes da adesão), migração v8→v9 |
| `DocExportTest.php` | ZIP de vendas (filtro, seleção, HTML, XML da e-Fatura, CSV, limites) e de compras (anexos) |
| `SalesLinksTest.php` | Associar documentos (regras, nos dois sentidos, remover, orçamento faturado no SAF-T), duplicar para outro tipo com associação, apagar rascunho, migração v9→v10 |
| `SaftTest.php` | SAF-T CV C e F validados com o XSD oficial 1.01_01 (orçamento faturado, pró-forma, recibo, pagamento a fornecedor, linha sem artigo, isenção) |
| `ApiAuthTest.php` | Regras da API (POST, cabeçalho, rede local), acessos (ativar, entrada, bloqueio por tentativas, desligar), perfis (colaborador e só leitura travados, contabilista obrigatório), autor no registo de auditoria, ficheiro antigo só com palavra-passe, diagnóstico |
| `AssetsTest.php` | Plano de depreciações (meses do primeiro ano, residual, última quota, depreciações anteriores), lançamento e anulação por exercício e por ordem, abate com venda (ganho/perda, depreciação até ao mês), migração v10→v11 |
| `BusinessStatsTest.php` | Estatísticas a partir dos documentos: vendas por artigo/família/cliente com notas de crédito e margem pelo custo médio, filtros, compras por conta e fornecedor, prazos reais (e nos rácios), imobilizado no painel |
| `InventoryTest.php` | Apuramento do CMVMC: compras → inventário → custo, validações, apuramentos sucessivos, anular o último, fecho exige o apuramento, inventário passa para o ano seguinte |
| `RulesTest.php` | Regras da revisão por módulo: IVA não apurado duas vezes, código de conta, datas de recibos, notas e pagamentos, proteções do imobilizado, regras dos utilizadores |
| `PagingTest.php` | Extrato e diário paginados iguais à versão completa |
| `ImportStatsTest.php` | SAF-T (pré-visualização, conflitos, importação com abertura), CSV (erros e tudo-ou-nada), exportação SAF-T, painel, rácios, orçamento, fórmulas |

Estado atual: **70 testes, todos a passar**.

## Teste no browser (Playwright)

```bash
php -S 127.0.0.1:8765 &                      # com config.php a apontar para uma pasta de dados de teste
php tools/seed-demo.php /pasta/de/teste      # empresa de demonstração
node tests/e2e/smoke.mjs                     # BASE e COMPANY configuráveis por variáveis de ambiente
```

O teste percorre **todos os ecrãs** (28, incluindo o menu e-Fatura com verificação do XML pelo XSD e descarga do manual), cria uma empresa nova, faz e valida um lançamento, anexa-lhe um PDF importa um extrato bancário, cria o lançamento da comissão a partir do extrato e confirma diferença zero na reconciliação, e exporta o balancete para Excel (verifica que o ficheiro é um XLSX). Falha se houver erros na consola do browser, exceções de JavaScript, respostas 5xx da API ou alertas de erro no ecrã. Guarda capturas em `tests/e2e/output/` (ignorado pelo Git).

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
