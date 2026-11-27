# 1. Visão geral

## Objetivo

Dar às micro, pequenas e médias empresas de Cabo Verde (e aos gabinetes de contabilidade) uma contabilidade completa em SNCRF que:

- funciona sem internet, no próprio computador;
- guarda cada empresa num único ficheiro (`.ideia`) que se copia para outro PC ou Mac;
- importa o histórico de outros programas (SAF-T do Primavera, PHC, Sage, Vendus; folhas Excel);
- exporta o SAF-T CV de contabilidade e, numa fase seguinte, liga-se ao e-Fatura.

## Funcionalidades da versão 0.1

| Área | O que existe |
|------|--------------|
| Empresas | Criar, listar (carteira com alertas), abrir cópia `.ideia`, várias empresas no mesmo computador |
| Plano de contas | Plano base SNCRF (classes 1–8), contas novas com hierarquia automática pelo código, só contas de movimento recebem lançamentos |
| Terceiros | Clientes, fornecedores e outros, com validação do NIF de 9 dígitos |
| Lançamentos | Rascunho → validado; numeração sequencial por diário e exercício; estorno em vez de apagar; códigos de IVA por linha |
| Integridade | Cadeia SHA-256 dos lançamentos validados, verificável no ecrã Empresa; registo de auditoria |
| Exercícios | Criar, bloquear período (após declarações), fechar (apuramento 6/7 → 811 → 818) e abrir o seguinte (saldos de abertura, resultado para 56) |
| Relatórios | Balancete por nível, extrato (razão), diário, balanço, demonstração dos resultados por naturezas com comparação ao ano anterior; exportação CSV e impressão/PDF |
| IVA | Apuramento por período (2432/2433/2434), mapa por código e por terceiro, lançamento automático de apuramento (2436/2437) |
| Importação | SAF-T CV/PT com pré-visualização e mapeamento de contas; CSV de contas, terceiros, saldos e lançamentos; tudo-ou-nada |
| Exportação | SAF-T CV de contabilidade (C) |
| Estatísticas | Painel com KPIs e gráficos clicáveis, rácios, estrutura de gastos, clientes/fornecedores com antiguidade de saldos, orçamento vs real, previsão, indicadores com fórmulas próprias, relatório de gestão imprimível |
| Cópias | Cópia verificada (`VACUUM INTO` + `integrity_check`), descarga e restauro |

## Fora do âmbito desta versão

- Ligação ao e-Fatura (emissão/receção de DFE) — ver [roadmap](10-roadmap.md).
- Faturação, salários, imobilizado detalhado e stocks como módulos próprios.
- Multiutilizador com permissões (hoje assume um utilizador por computador).

## Avisos importantes

- O plano de contas base (`database/sncrf.php`) e o modelo de balanço/DR seguem o SNCRF mas **têm de ser validados por um contabilista certificado (OPACC)** antes de uso em produção.
- O namespace do SAF-T CV exportado (`urn:OECD:StandardAuditFile-Tax:CV_1.0`) tem de ser confirmado com o XSD oficial da DNRE e o ficheiro validado no SAFTPRO.
