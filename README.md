# Contabilidade — IDEIA

Software de contabilidade **open-source** para empresas de **Cabo Verde** (SNCRF), que funciona **no seu computador** (PC ou Mac), sem cloud. Cada empresa é um único ficheiro `.ideia` que se copia para outro computador.

## Funcionalidades (v0.7)

- **Vendas**: artigos (produtos e serviços), faturas, faturas-recibo, orçamentos, pro-formas, guias de entrega e de transporte, notas de crédito e de débito, recibos — numeração automática por tipo e série, impressão A4/PDF, lançamento contabilístico automático (a comunicação e-Fatura à DNRE será ligada quando houver credenciais)
- **Documentos relacionados**: nota de crédito e recibo a partir da lista, associar documentos (ex.: orçamento ↔ fatura), duplicar para outro tipo com associação
- **Exportar documentos em ZIP** (vendas e compras), escolhidos à mão ou pelos filtros; tema claro, escuro ou automático
- **Fatura eletrónica (e-Fatura)**: IUD e código QR, XML validado com o XSD oficial da DNRE, talão de venda, contingência e envio pelo Middleware; importação dos documentos recebidos para as compras; conferência do IVA; documentação oficial incluída
- **Compras**: faturas de fornecedores, despesas pagas, notas de crédito de fornecedores e pagamentos, com lançamento automático; dívidas a fornecedores por antiguidade e mapa de pagamentos
- **Artigos** com famílias definidas por si (IVA e conta sugeridos)
- **Extrato de conta corrente** de clientes e fornecedores (movimentos da contabilidade), em Excel ou PDF
- **Dívidas de clientes** com antiguidade, ficha do cliente com faturas por pagar e extrato de conta corrente imprimível

- Plano de contas SNCRF, clientes e fornecedores (NIF), vários exercícios e várias empresas
- Lançamentos com rascunho, validação, numeração sequencial, estorno, documentos anexos e **cadeia de integridade SHA-256**
- Balancete, extrato de conta, diário, balanço e demonstração dos resultados (com ano anterior), em Excel, CSV ou PDF
- **Reconciliação bancária**: importação de extratos, conciliação automática e manual, mapa de reconciliação
- Apuramento do IVA com mapas por código e por cliente/fornecedor
- **Imobilizado**: fichas dos bens, depreciações e amortizações lançadas por exercício, abate e venda, mapa de depreciações
- Fecho do exercício e abertura do seguinte
- **Importação** do histórico por SAF-T (Primavera, PHC, Sage…) e por folhas Excel/CSV; **exportação** SAF-T CV 1.01_01 (contabilidade ou completo), validada com o XSD oficial
- **Estatísticas**: painel (com imobilizado), rácios, vendas por artigo e família com margem, compras por fornecedor e conta, prazos reais de recebimento e pagamento, gastos, antiguidade de saldos, orçamento, previsão, indicadores próprios, relatório de gestão
- Cópias de segurança verificadas (automáticas todos os dias) e restauro
- **Utilizadores com perfis** (contabilista, colaborador, só leitura), autor no registo de auditoria, e diagnóstico automático da instalação

## Começar

**Utilizadores:** descarregue a versão portátil (zip com o PHP incluído) nos lançamentos do GitHub, descompacte em qualquer pasta e faça duplo clique em `Abrir IDEIA`. Não precisa de instalar nada. Ver [docs/02-instalacao.md](docs/02-instalacao.md).

**Programadores:** PHP 8.0+ com `pdo_sqlite` (incluído no XAMPP).

```bash
# na pasta htdocs do XAMPP, ou em qualquer pasta:
git clone https://github.com/IDEIA-cv/Contabilidade.git contabilidade
cd contabilidade
php tools/seed-demo.php      # opcional: empresa de demonstração
php -S 127.0.0.1:8765 tools/portable/router.php   # ou use o Apache do XAMPP: http://localhost/contabilidade/
```

No Mac com XAMPP, dê permissão de escrita ao Apache: `sudo chown -R daemon data`.

## Documentação

Toda a documentação técnica está em [`docs/`](docs/README.md): instalação, arquitetura, modelo de dados, regras contabilísticas, API, importação/exportação, estatísticas, testes, roadmap e decisões de arquitetura.

## Testes

```bash
php tests/run.php            # testes unitários
node tests/e2e/smoke.mjs     # teste no browser (Playwright), com o servidor a correr
```

## Estado

Versão 0.6, em validação. O plano de contas e os mapas devem ser revistos por um contabilista certificado, a exportação SAF-T (já conforme o XSD 1.01_01) validada no SAFTPRO e a e-Fatura homologada com a DNRE antes de uso em produção ([roadmap](docs/10-roadmap.md)).

## Ramos

- `main`: versões estáveis
- `develop`: integração
- `feature/*`: desenvolvimento de cada funcionalidade
