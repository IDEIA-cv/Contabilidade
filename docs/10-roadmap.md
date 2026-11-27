# 10. Roadmap

## Feito — versão 0.1 (fase 1: núcleo)

Núcleo contabilístico SNCRF, relatórios legais, IVA, fecho/abertura, importação SAF-T e CSV, exportação SAF-T CV, estatísticas, cópias e integridade. Ver [visão geral](01-visao-geral.md).

## Feito — versão 0.2

- Fecho do MVP: documentos anexos aos lançamentos, exportação Excel (.xlsx), cópias automáticas diárias.
- Fase 2 (parte que não depende da DNRE): **reconciliação bancária** com importação de extratos.

## Próximo — validação (antes de qualquer cliente real)

| Tarefa | Porquê |
|--------|--------|
| Rever plano de contas, balanço e DR com contabilista certificado (OPACC) | Os mapeamentos são uma primeira versão |
| Obter o XSD oficial do SAF-T CV junto da DNRE e validar a exportação no SAFTPRO | Namespace e campos obrigatórios ainda por confirmar |
| Recolher SAF-T reais e anonimizados do Primavera, PHC, Sage e exportações do Vendus | Os testes usam um ficheiro sintético |
| Definir a licença open-source (sugestão: AGPL-3.0 para o núcleo) | Necessário antes de divulgar |
| Piloto com 2 ou 3 gabinetes de contabilidade | Validar fluxos reais |

## Fase 2 — e-Fatura e faturação (depende da DNRE)

Inclui a **faturação** (produtos e serviços, emissão de FTE, FRE, TVE, RCE, NCE, NDE, lista de documentos), sempre como DFE válidos. Só arranca com as especificações e credenciais de teste da DNRE (portão da Fase 2 no plano).


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
