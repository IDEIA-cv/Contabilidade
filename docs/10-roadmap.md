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

## Próximo — validação (antes de qualquer cliente real)

| Tarefa | Porquê |
|--------|--------|
| Rever plano de contas, balanço e DR com contabilista certificado (OPACC) | Os mapeamentos são uma primeira versão |
| Obter o XSD oficial do SAF-T CV junto da DNRE e validar a exportação no SAFTPRO | Namespace e campos obrigatórios ainda por confirmar |
| Recolher SAF-T reais e anonimizados do Primavera, PHC, Sage e exportações do Vendus | Os testes usam um ficheiro sintético |
| Confirmar a licença open-source (o plano propõe GPL-3.0 para a aplicação e Apache-2.0 para as bibliotecas de e-Fatura e SAF-T) | Necessário antes de divulgar |
| Piloto com 2 ou 3 gabinetes de contabilidade | Validar fluxos reais |

## Fase 2 — e-Fatura e faturação (depende da DNRE)

A emissão de documentos já existe (v0.3). Falta transformá-los em DFE válidos e comunicá-los, o que só arranca com as especificações e credenciais de teste da DNRE (portão da Fase 2 no plano). Os documentos emitidos até lá ficam com `efatura_status = 'pendente'` para poderem ser tratados.


- Leitura dos DFE (documentos fiscais eletrónicos) emitidos e recebidos, para conferência com a contabilidade e lançamento automático de compras/vendas.
- Geração de DFE (XML com IUD), assinatura e envio pela API da DNRE, com **fila de contingência** offline e reenvio quando houver internet.
- Mapa de conferência IVA: e-Fatura vs contabilidade.
- Requer: documentação técnica e credenciais de teste da DNRE; certificado digital.

## Fase 3 — produto

- Empacotamento como aplicação de ambiente de trabalho (Tauri ou PHP embebido) com instalador para Windows e macOS, sem precisar de XAMPP. Ver [ADR 0001](adr/0001-php-sqlite-htdocs.md).
- Multiutilizador com perfis (contabilista, colaborador, só leitura) e registo de autor no audit log.
- Encriptação opcional do ficheiro `.ideia`.
- Migrações de esquema automáticas e testadas.
- Módulos: imobilizado com mapas de depreciação, centros de custo (campo `cost_center` já existe), declarações (Modelo 1B, IUR/IRPC) com exportação.
- Sincronização opcional de cópias para uma pasta na cloud escolhida pelo utilizador.

## Melhorias técnicas conhecidas

- PMP mostra valores baixos quando os fornecedores estão todos pagos (correto, mas a aproximação CMVMC+FSE deve ser revista).
- "Financiamentos obtidos" (25) está todo no passivo não corrente; separar curto e longo prazo.
- Tabelas largas em ecrãs pequenos (ex.: IVA por terceiro) deslocam-se na horizontal; considerar vista compacta.
- Paginação no ecrã de lançamentos para empresas com muitos milhares de lançamentos.
