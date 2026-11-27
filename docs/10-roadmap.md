# 10. Roadmap

## Feito — versão 0.1 (fase 1: núcleo)

Núcleo contabilístico SNCRF, relatórios legais, IVA, fecho/abertura, importação SAF-T e CSV, exportação SAF-T CV, estatísticas, cópias e integridade. Ver [visão geral](01-visao-geral.md).

## Feito — versão 0.2

- Fecho do MVP: documentos anexos aos lançamentos, exportação Excel (.xlsx), cópias automáticas diárias.
- Fase 2 (parte que não depende da DNRE): **reconciliação bancária** com importação de extratos.

## Feito — versão 0.3

- Reforço técnico: índices (esquema v4), paginação, desempenho com 50 000 lançamentos, acessibilidade e ecrãs pequenos.
- **Vendas antecipadas da Fase 2** (decisão de 27/11/2026): artigos; orçamentos, pro-formas, guias, faturas, faturas-recibo, notas de débito e de crédito, recibos; numeração automática por tipo e série; lançamento contabilístico automático; dívidas de clientes e extrato. Ver [12-vendas](12-vendas.md).
- Palavra-passe de acesso opcional, cabeçalhos de segurança, diagnóstico da instalação.
- [Avaliação de qualidade](13-avaliacao-qualidade.md).

## Feito — versão 0.4

- **Compras e dívidas a fornecedores** (pedido de 27/11/2026): faturas de fornecedores, despesas pagas, notas de crédito, pagamentos de uma ou várias faturas, dívidas por antiguidade, mapa de pagamentos e ficha do fornecedor. Ver [14-compras](14-compras.md).
- Tipos de artigo criados pelo utilizador, com entrada própria no menu.

## Feito — versão 0.5

- **Fatura eletrónica** (documentos oficiais recebidos a 27/11/2026): IUD e código QR, XML de todos os tipos validado com o XSD oficial, listas oficiais de isenções e unidades, talão de venda (TVE), NIF obrigatório na fatura-recibo, numeração comum das guias, motivo e origem das notas, impressão com aviso de contingência, menu **Fatura eletrónica** (estado e envio, configuração do Middleware, documentação oficial). Ver [15-efatura](15-efatura.md).

## Feito — versão 0.6

- **SAF-T CV 1.01_01** validado com o XSD oficial, nos tipos C (contabilidade) e F (com artigos, orçamentos, pró-formas e pagamentos). Ver [07](07-importacao-exportacao.md#3-exportação-saf-t-cv-saftexporter).
- **Documentos recebidos da e-Fatura**: importação dos XML ou ZIP descarregados da Plataforma Eletrónica para as compras (esquema v9).
- **Conferência do IVA**: e-Fatura contra contabilidade, por mês. Ver [15-efatura](15-efatura.md).

## Feito — versão 0.7

- **Utilizadores com perfis** (contabilista, colaborador, só leitura) e autor no registo de auditoria. Ver [17-utilizadores](17-utilizadores.md).
- **Imobilizado**: fichas dos bens, depreciações por quotas constantes lançadas por exercício, abate e venda, mapa de depreciações (esquema v11). Ver [16-imobilizado](16-imobilizado.md).
- Exportação em ZIP de documentos de venda e de compra, tema claro/escuro/automático, notas de crédito e recibos a partir da lista, associar e duplicar documentos, "Emitir recibo".

- **Estatísticas a partir dos documentos**: vendas por artigo, família e cliente com margem, compras por fornecedor e conta, prazos reais de recebimento e pagamento (também nos rácios PMR/PMP), imobilizado no painel.
- **Inventário periódico**: apuramento do custo das mercadorias vendidas (31 → 32/33 → 611/612) com o inventário contado; o fecho exige-o. Relatório de gestão e carteira usam os dados da faturação.

## Próximo — validação (antes de qualquer cliente real)

| Tarefa | Porquê |
|--------|--------|
| Rever plano de contas, balanço e DR com contabilista certificado (OPACC) | Os mapeamentos são uma primeira versão |
| Validar a exportação SAF-T no SAFTPRO (saft.efatura.cv) | O ficheiro já cumpre o XSD 1.01_01; falta a validação de negócio da DNRE |
| Recolher SAF-T reais e anonimizados do Primavera, PHC, Sage e exportações do Vendus | Os testes usam um ficheiro sintético |
| Confirmar a licença open-source (o plano propõe GPL-3.0 para a aplicação e Apache-2.0 para as bibliotecas de e-Fatura e SAF-T) | Necessário antes de divulgar |
| Piloto com 2 ou 3 gabinetes de contabilidade | Validar fluxos reais |
| Homologação da e-Fatura com conta de teste e Middleware instalado | Confirmar a resposta do serviço e o fluxo completo de envio |

## Fase 2 — e-Fatura e faturação (depende da DNRE)

A geração dos DFE e o envio pelo Middleware existem desde a v0.5. Falta a homologação com credenciais de teste e o resto da fase:


- Descarga automática dos DFE recebidos (`GET /v1/dfe` pelo Middleware), em vez de importar o ficheiro descarregado à mão.
- Requer: documentação técnica e credenciais de teste da DNRE; certificado digital.

## Fase 3 — produto

- Empacotamento como aplicação de ambiente de trabalho (Tauri ou PHP embebido) com instalador para Windows e macOS, sem precisar de XAMPP. Ver [ADR 0001](adr/0001-php-sqlite-htdocs.md).
- Encriptação opcional do ficheiro `.ideia`.
- Migrações de esquema automáticas e testadas.
- Módulos: centros de custo (campo `cost_center` já existe), declarações (Modelo 1B, IUR/IRPC) com exportação.
- Sincronização opcional de cópias para uma pasta na cloud escolhida pelo utilizador.

## Melhorias técnicas conhecidas

- PMP mostra valores baixos quando os fornecedores estão todos pagos (correto, mas a aproximação CMVMC+FSE deve ser revista).
- "Financiamentos obtidos" (25) está todo no passivo não corrente; separar curto e longo prazo.
- Tabelas largas em ecrãs pequenos (ex.: IVA por terceiro) deslocam-se na horizontal; considerar vista compacta.
- Paginação no ecrã de lançamentos para empresas com muitos milhares de lançamentos.
