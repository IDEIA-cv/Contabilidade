# Contabilidade — IDEIA

Software de contabilidade **open-source** para empresas de **Cabo Verde** (SNCRF), que funciona **no seu computador** (PC ou Mac), sem cloud. Cada empresa é um único ficheiro `.ideia` que se copia para outro computador.

## Funcionalidades (v0.1)

- Plano de contas SNCRF, clientes e fornecedores (NIF), vários exercícios e várias empresas
- Lançamentos com rascunho, validação, numeração sequencial, estorno, documentos anexos e **cadeia de integridade SHA-256**
- Balancete, extrato de conta, diário, balanço e demonstração dos resultados (com ano anterior), em Excel, CSV ou PDF
- Apuramento do IVA com mapas por código e por cliente/fornecedor
- Fecho do exercício e abertura do seguinte
- **Importação** do histórico por SAF-T (Primavera, PHC, Sage…) e por folhas Excel/CSV; **exportação** SAF-T CV
- **Estatísticas**: painel, rácios, gastos, antiguidade de saldos, orçamento, previsão, indicadores próprios, relatório de gestão
- Cópias de segurança verificadas (automáticas todos os dias) e restauro

## Começar

Requisitos: PHP 8.0+ com `pdo_sqlite` (incluído no XAMPP).

```bash
# na pasta htdocs do XAMPP, ou em qualquer pasta:
git clone https://github.com/IDEIA-cv/Contabilidade.git contabilidade
cd contabilidade
php tools/seed-demo.php      # opcional: empresa de demonstração
php -S 127.0.0.1:8765        # ou use o Apache do XAMPP: http://localhost/contabilidade/
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

Versão 0.1, em validação. O plano de contas e os mapas devem ser revistos por um contabilista certificado e a exportação SAF-T validada no SAFTPRO antes de uso em produção. A ligação ao e-Fatura é a próxima fase ([roadmap](docs/10-roadmap.md)).

## Ramos

- `main`: versões estáveis
- `develop`: integração
- `feature/*`: desenvolvimento de cada funcionalidade
