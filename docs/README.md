# Documentação técnica — IDEIA Contabilidade

Software de contabilidade open-source para empresas de Cabo Verde, que funciona **no computador do utilizador** (PC ou Mac, sem cloud), com a base de dados num único ficheiro portátil por empresa.

Esta pasta reúne toda a documentação técnica. Leia pela ordem abaixo se está a chegar ao projeto.

| # | Documento | Para quem |
|---|-----------|-----------|
| 1 | [Visão geral](01-visao-geral.md) | Todos: o que o sistema faz e o estado atual |
| 2 | [Instalação e configuração](02-instalacao.md) | Quem instala (XAMPP Mac/Windows, servidor PHP) |
| 3 | [Arquitetura](03-arquitetura.md) | Programadores: camadas, pastas, fluxo de um pedido |
| 4 | [Modelo de dados](04-modelo-de-dados.md) | Programadores: tabelas, vista, invariantes |
| 5 | [Regras contabilísticas](05-regras-contabilisticas.md) | Programadores e contabilistas: lançamentos, integridade, relatórios, IVA, fecho |
| 6 | [API](06-api.md) | Programadores: todas as ações de `api.php` |
| 7 | [Importação e exportação](07-importacao-exportacao.md) | SAF-T (Primavera, PHC, Sage, Vendus…), CSV/Excel, exportação SAF-T CV |
| 8 | [Estatísticas](08-estatisticas.md) | Painel, rácios, previsão, orçamento, indicadores próprios |
| 9 | [Testes](09-testes.md) | Como correr os testes unitários e o teste no browser |
| 10 | [Roadmap](10-roadmap.md) | Próximas fases (e-Fatura, multiutilizador, empacotamento) |
| 11 | [Reconciliação bancária](11-reconciliacao-bancaria.md) | Importação de extratos, conciliação e mapa |
| 12 | [Vendas e documentos comerciais](12-vendas.md) | Faturas, orçamentos, guias, recibos, numeração, dívidas de clientes |
| 14 | [Compras e dívidas a fornecedores](14-compras.md) | Faturas de fornecedores, pagamentos, dívidas a fornecedores, mapa de pagamentos |
| 15 | [Fatura eletrónica](15-efatura.md) | Regras da e-Fatura (DNRE), IUD, XML, contingência, Middleware e menu e-Fatura |
| 13 | [Avaliação de qualidade](13-avaliacao-qualidade.md) | Notas por categoria (software e UI/UX) e próximos passos |
| — | [Decisões de arquitetura (ADR)](adr/) | Porque se escolheu cada solução |

## Decisões registadas

- [ADR 0001 — PHP + SQLite servido pelo htdocs (em vez de Tauri na fase 1)](adr/0001-php-sqlite-htdocs.md)
- [ADR 0002 — Valores monetários em centavos inteiros](adr/0002-dinheiro-em-centavos.md)
- [ADR 0003 — Lançamentos imutáveis e cadeia de integridade SHA-256](adr/0003-imutabilidade-e-cadeia-hash.md)
- [ADR 0004 — Um ficheiro `.ideia` por empresa](adr/0004-um-ficheiro-por-empresa.md)

## Convenções

- Código em inglês (nomes de classes e funções); interface, mensagens e documentação em português.
- Valores em ECV, guardados em centavos (`*_cents`). Datas ISO `AAAA-MM-DD`.
- Nada de dependências externas: PHP 8.0+ com PDO SQLite e JavaScript nativo (módulos ES), para funcionar offline.
