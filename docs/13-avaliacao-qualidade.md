# 13. Avaliação de qualidade

## Revisão por módulo (v0.7, 27/11/2026)

Pedido do responsável do projeto: um relatório de qualidade por secção/módulo, com correções e testes até cada módulo ter pelo menos 8 (escala 0–10).

**Como foi avaliado.** Leitura do código e dos testes de cada módulo e uma sonda com 65 casos-limite (datas, valores, duplicados, ordem, perfis). Também um teste de injeção de HTML/JavaScript: nomes, moradas, descrições e notas com código malicioso, em 40 ecrãs e na impressão. Por fim, o teste no browser e a auditoria de acessibilidade (axe, WCAG A/AA) em 35 páginas, mais a verificação a 390 px. A nota junta funcionalidade, correção, testes e facilidade de uso.

| Módulo | Inicial | Final | Encontrado e corrigido | O que falta para subir |
|---|---|---|---|---|
| Lançamentos e integridade | 9 | **9** | Nada a corrigir: exercício fechado, período bloqueado, datas inválidas, estorno duplo, valores negativos e diário inexistente são recusados | — |
| Plano de contas | 7 | **8,5** | Um código com letras (ex.: `62A`) era limpo para `62`. Uma conta nova com código já existente mudava em silêncio o nome dessa conta. Agora é recusado, com mensagem. Novo teste | — |
| Clientes e fornecedores | 8 | **8** | NIF com 9 dígitos e duplicados já validados | Validar o dígito de controlo do NIF quando a DNRE publicar a regra |
| Vendas | 8 | **8,5** | Um recibo e uma nota de crédito ou de débito podiam ter data anterior à da fatura. Agora é recusado. Novo teste | — |
| Compras | 7,5 | **8,5** | Um pagamento e uma nota de crédito de fornecedor podiam ter data anterior à da fatura. Agora é recusado. Novo teste | — |
| Fatura eletrónica | 8 | **8** | Sem falhas na sonda | Homologação com a DNRE (credenciais de teste e Middleware) |
| Reconciliação bancária | 8 | **8** | Sem falhas na sonda | Extratos reais de mais bancos cabo-verdianos |
| Imobilizado | 7,5 | **8,5** | Duas tabelas sem acesso pelo teclado (acessibilidade). Testes das proteções: apagar, alterar valores, lançar duas vezes, ordem dos exercícios, abate repetido. 1 000 bens: lista em 10 ms, lançamento em 50 ms | Taxas confirmadas por contabilista certificado |
| Relatórios | 8 | **8** | Sem falhas na sonda | Validação do balanço e da DR por contabilista certificado (OPACC) |
| Apuramento do IVA | 6,5 | **8,5** | **O mesmo período podia ser apurado duas vezes**, o que duplicava o lançamento. Agora é recusado e o ecrã mostra "já apurado", com ligação ao lançamento. Para refazer, estorna-se o lançamento. Meses invertidos também são recusados. Novo teste | — |
| Importação e exportação | 8,5 | **8,5** | Sem falhas na sonda | SAF-T reais do Primavera, PHC e Sage |
| Exercícios e fecho | 8,5 | **8,5** | Fecho com rascunhos, fecho repetido e abertura repetida já recusados | — |
| Empresa, cópias e restauro | 8,5 | **8,5** | Sem falhas na sonda | — |
| Utilizadores e segurança | 7 | **8,5** | O servidor já recusava, mas o colaborador e o só leitura viam botões que davam erro. Agora o ecrã esconde as ações que o perfil não pode fazer e mostra o aviso "Só leitura". A injeção de código não passou em nenhum dos 40 ecrãs. Um pedido JSON vazio (ex.: sair) dava erro e foi corrigido. Novo teste das regras dos utilizadores | Encriptação opcional do ficheiro `.ideia` |
| Interface geral | 7,5 | **8,5** | Ao abrir uma empresa por ligação direta, dois carregamentos seguidos podiam dar erro num relatório. Agora esperam pelo mesmo carregamento. 0 violações de acessibilidade em 35 páginas e nenhuma página com deslocamento horizontal a 390 px | Ajuda contextual (?) nos ecrãs contabilísticos |
| Painel e estatísticas | 8 | **8** | Sem falhas na sonda | — |

**Resultado: todos os módulos com 8 ou mais (média 8,4).** Testes: 66 unitários (antes 61, com o novo `RulesTest.php`) e o teste no browser (agora também verifica o que cada perfil vê). Ficam abaixo de 9 os módulos que dependem de validação externa (DNRE, OPACC, ficheiros reais), indicados na última coluna.

---

## Avaliação anterior (v0.3, 27/11/2026)

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
| Linguagem clara | 8 | Português corrente nas vendas ("Por receber", "Vencido", "Emitir recibo"). Mensagens de erro dizem como corrigir (ex.: ordem das datas, motivo de isenção). A parte contabilística usa termos técnicos, adequados a contabilistas. |
| Prevenção de erros | 8,5 | Confirmação antes de emitir, a explicar que o documento deixa de ser editável. Anular pede motivo. Valores calculados ao vivo. Cliente inexistente é assinalado. Preço com IVA calculado automaticamente. |
| Eficiência | 8 | Pesquisa de artigos e clientes por escrita, criação de cliente sem sair da fatura, Enter acrescenta linha, conversão orçamento → fatura num clique, pagamento direto da lista de dívidas. |
| Ecrãs pequenos | 8 | As linhas da fatura passam a cartões no telemóvel, sem deslizar na horizontal. Barra de ações fixa no fundo. Nenhuma página com deslocamento horizontal a 390 px. |
| Consistência visual | 8,5 | Mesmos componentes em toda a aplicação; estados com cores e texto (não só cor). |

### Melhorias seguintes sugeridas (não bloqueiam)

- Envio do documento por email diretamente da aplicação (precisa de configuração SMTP).
- Leitura de código de barras na linha da fatura (o campo já existe no artigo).
- Ajuda contextual (ícone "?") nos ecrãs contabilísticos.
- Modo "só faturação" que esconde o menu contabilístico a quem não é contabilista (com os perfis da Fase 3).
