# 13. Avaliação de qualidade (v0.3, 27/11/2026)

Avaliação pedida pelo responsável do projeto: nota de 0 a 10 por categoria, com iterações até 8 onde depender do projeto. Primeiro foi feita uma avaliação inicial, a seguir as correções, e no fim a reavaliação.

## Software

| Categoria | Inicial | Final | O que foi feito / o que falta |
|---|---|---|---|
| Funcionalidade (cobertura do âmbito) | 6 | **8,5** | Faturação completa: 9 tipos de documento, artigos, numeração automática, recibos, notas de crédito, conversões, impressão, dívidas e extrato por cliente; extrato de conta corrente de clientes e fornecedores a partir da contabilidade. |
| Correção contabilística | 8 | **8** | Partidas dobradas, IVA, fecho e lançamentos de vendas testados. Os mapeamentos ainda têm de ser validados por um contabilista certificado (OPACC). |
| Integridade dos dados | 9 | **9** | Cadeia SHA-256 nos lançamentos e nas séries de documentos, imutabilidade, estornos, cópias automáticas. |
| Segurança | 6 | **8** | Palavra-passe de acesso opcional (hash bcrypt/argon, sessão HttpOnly/SameSite, fecho após 4 h, bloqueio de 5 min após 5 tentativas); Content-Security-Policy, X-Frame-Options, nosniff, no-referrer; já existia: só localhost, POST com cabeçalho anti-CSRF, pasta de dados fechada. Falta: perfis por utilizador (Fase 3), encriptação do ficheiro. |
| Desempenho | 9 | **9** | 50 000 lançamentos: relatórios < 0,5 s. 5 000 faturas: listas e dívidas em 10–25 ms. |
| Testes | 7 | **8,5** | 36 testes unitários (antes 26): vendas, dívidas, API, palavra-passe, diagnóstico. Teste no browser com 30 passos, incluindo emissão de fatura, pagamento e impressão. Auditoria axe-core em 17 páginas. |
| Manutenibilidade | 8 | **8** | Sem dependências; módulos pequenos; regras de cálculo iguais no servidor e no browser e documentadas. |
| Documentação | 9 | **9** | Novo [12-vendas](12-vendas.md) e este documento; API, modelo de dados e roadmap atualizados. |
| Acessibilidade | 8 | **8,5** | 0 violações WCAG A/AA (axe) nas 17 páginas, incluindo as de vendas; ligações sublinhadas em listas. |
| Instalação e suporte | 6 | **8** | Diagnóstico automático na página inicial: versão do PHP, SQLite, permissões das pastas de dados e cópias (com o comando exato para o Mac), fileinfo, tamanho de envio. |
| Conformidade fiscal (e-Fatura, SAF-T) | 4 | **8** | Numeração sequencial por série sem saltos e por ordem de datas, hash encadeado, dados do cliente congelados, correção só por nota de crédito. v0.5: IUD e QR, XML de todos os tipos validado com o XSD oficial da DNRE, listas oficiais, talão de venda, contingência, envio pelo Middleware. v0.6: SAF-T CV 1.01_01 (C e F) validado com o XSD oficial, importação dos DFE recebidos e conferência do IVA. Para passar de 8 falta a homologação real e a validação no SAFTPRO. |

Média das categorias que dependem do projeto: **8,5**. A conformidade fiscal subiu para 8 com a e-Fatura (v0.5); a homologação com a DNRE é o passo seguinte. O caminho está em [10-roadmap](10-roadmap.md).

## UI e UX (utilizadores com conhecimento técnico médio ou baixo)

| Critério | Nota | Notas |
|---|---|---|
| Facilidade de aprendizagem | 8 | "Primeiros passos" no painel: dados da empresa → artigos → primeira fatura. Botões grandes "Nova fatura / Nova fatura-recibo / Novo orçamento". Estados vazios dizem o que fazer. |
| Linguagem clara | 8 | Português corrente nas vendas ("Por receber", "Vencido", "Registar pagamento"). Mensagens de erro dizem como corrigir (ex.: ordem das datas, motivo de isenção). A parte contabilística usa termos técnicos, adequados a contabilistas. |
| Prevenção de erros | 8,5 | Confirmação antes de emitir, a explicar que o documento deixa de ser editável. Anular pede motivo. Valores calculados ao vivo. Cliente inexistente é assinalado. Preço com IVA calculado automaticamente. |
| Eficiência | 8 | Pesquisa de artigos e clientes por escrita, criação de cliente sem sair da fatura, Enter acrescenta linha, conversão orçamento → fatura num clique, pagamento direto da lista de dívidas. |
| Ecrãs pequenos | 8 | As linhas da fatura passam a cartões no telemóvel, sem deslizar na horizontal. Barra de ações fixa no fundo. Nenhuma página com deslocamento horizontal a 390 px. |
| Consistência visual | 8,5 | Mesmos componentes em toda a aplicação; estados com cores e texto (não só cor). |

### Melhorias seguintes sugeridas (não bloqueiam)

- Envio do documento por email diretamente da aplicação (precisa de configuração SMTP).
- Leitura de código de barras na linha da fatura (o campo já existe no artigo).
- Ajuda contextual (ícone "?") nos ecrãs contabilísticos.
- Modo "só faturação" que esconde o menu contabilístico a quem não é contabilista (com os perfis da Fase 3).
