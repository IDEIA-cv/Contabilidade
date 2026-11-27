# 7. Importação e exportação

Princípios comuns a todas as importações:

- **Pré-visualizar antes de gravar.** Nada é escrito na fase de análise.
- **Tudo ou nada.** A gravação corre numa transação; havendo um erro, nada fica gravado (`Import\ImportFailed`).
- **Rastreabilidade.** Cada importação fica em `import_log`, com o ficheiro original comprimido, e no registo de auditoria.

## 1. SAF-T de contabilidade (`Saft\Importer`)

Serve para migrar o histórico do **Primavera, PHC, Sage, Vendus** ou outro programa que exporte SAF-T com contabilidade.

### Formatos aceites
- SAF-T **CV** ou **PT** (o namespace por omissão é removido antes de ler, por isso as duas variantes funcionam). Versões testadas: estrutura PT 1.04 (fixture `tests/fixtures/saft-primavera-exemplo.xml`).
- Ficheiro `.xml` ou comprimido `.gz`.
- Um SAF-T só de faturação (sem `GeneralLedgerAccounts` nem `GeneralLedgerEntries`) é recusado com mensagem clara.

### O que é lido
| Origem no XML | Destino |
|---------------|---------|
| `Header` (CompanyName, TaxRegistrationNumber, FiscalYear, StartDate, EndDate, ProductID) | Informação da pré-visualização; aviso se o NIF for diferente do da empresa |
| `MasterFiles/GeneralLedgerAccounts/Account` | Contas novas (com as contas-mãe do ficheiro); saldos de abertura (`OpeningDebitBalance`/`OpeningCreditBalance`) |
| `MasterFiles/Customer` e `Supplier` | Terceiros (reaproveita os existentes com o mesmo NIF e tipo; NIF genérico 999999999 fica vazio; `external_id` guarda o código de origem) |
| `GeneralLedgerEntries/Journal` | Diários novos com o mesmo código |
| `Journal/Transaction/Lines/DebitLine` e `CreditLine` | Lançamentos validados, com terceiro (`CustomerID`/`SupplierID`) |

### Pré-visualização (`saft.preview`)
Mostra empresa, programa de origem, exercício, nº de lançamentos e diários, totais, contas novas, terceiros e avisos:

- débitos ≠ créditos no ficheiro, ou `TotalDebit` declarado ≠ soma das linhas;
- o exercício já tem lançamentos nesta empresa (**a importação só é permitida num exercício vazio**);
- NIF diferente.

**Contas a mapear (conflitos):** se o ficheiro lança numa conta que, no plano atual, tem subcontas (ex.: `62` no ficheiro, mas aqui existem `621…628`), o utilizador indica a conta de movimento de destino. Sem mapeamento a importação é recusada.

**Abertura:** se as contas trazem saldos iniciais e o ficheiro não tem um diário de abertura, sugere-se criar um lançamento de abertura (`ABE`, 01/01) a partir dos saldos iniciais **das contas folha** do ficheiro.

### Gravação (`saft.import`)
Ordem: exercício (criado se não existir) → contas → terceiros → diários → abertura (opcional) → lançamentos (cada um validado, com numeração e hash próprios desta aplicação; o número/documento de origem fica no campo documento).

## 2. Folhas Excel / CSV (`Import\Csv`)

Para quem tem o histórico em Excel ou num programa sem SAF-T. Guarde a folha como **CSV (separador ponto e vírgula)**. Descarregue os modelos no ecrã Importar.

Ordem recomendada: **contas → terceiros → saldos de abertura → lançamentos**.

| Tipo | Colunas do modelo |
|------|-------------------|
| `contas` | `codigo; nome` |
| `terceiros` | `nif; nome; tipo; morada; cidade; pais; email; telefone; conta` (tipo: C, F, A, O ou cliente/fornecedor) |
| `saldos` | `conta; debito; credito` → cria um lançamento de abertura no exercício escolhido |
| `lancamentos` | `lancamento; data; diario; documento; descricao; conta; debito; credito; nif; descricao_linha; codigo_iva; base_iva` → linhas com o mesmo valor em `lancamento` formam um lançamento |

Tolerâncias:
- separador `;`, `,` ou tabulação detetado automaticamente; codificação Windows-1252 convertida para UTF-8; BOM ignorado;
- nomes de colunas alternativos (ex.: `code`, `account`, `designacao`, `debit`, `date`, `contribuinte`);
- datas `AAAA-MM-DD` ou `DD/MM/AAAA`; valores `1.234,56`, `1234.56`, `(50)`.

Validações (com nº de linha no relatório de erros): conta inexistente ou que não é de movimento, lançamento desequilibrado, data inválida ou fora de exercício, NIF não encontrado, tipo de terceiro inválido. Os lançamentos podem entrar **validados** ou **como rascunho** (para revisão).

## 3. Exportação SAF-T CV (`Saft\Exporter`)

Segue o XSD oficial **SAF-T CV 1.01_01** (`resources/efatura/xsd/saftcv1.01_01.xsd`, namespace `urn:OECD:StandardAuditFile-Tax:CV_1.01_01`). No ecrã Importar escolhe-se o exercício e o tipo, e o botão **Verificar com o XSD oficial** valida o ficheiro antes de o descarregar.

`saft.export&year=2025&type=C|F` gera `SAFT_CV_<NIF>_<ano>_<tipo>.xml`:

| Tipo | Conteúdo |
|---|---|
| **C** Contabilidade | `Header`, `MasterFiles` (contas, clientes, fornecedores, tabela de IVA) e `GeneralLedgerEntries` |
| **F** Completo | O mesmo e ainda `Product` e `SourceDocuments`: `WorkingDocuments` (orçamentos e pró-formas), `Payments` (recibos RC e pagamentos a fornecedores PF) |

Regras aplicadas para cumprir o XSD:

- `PostalCode` tem o padrão `####-###`; como Cabo Verde não usa código postal, vai `0000-000`. `Region` leva a cidade.
- As classes do plano (1 dígito) não são exportadas, porque `AccountID` exige pelo menos 2 carateres; as contas de 2 dígitos são `GR`. `TaxonomyReference` = `S` (SNCRF).
- Período 1–12 pelo mês; o apuramento de dezembro vai no período 13.
- Em cada transação, as linhas a débito vêm antes das linhas a crédito. `DocArchivalNumber` sem espaços e com até 20 carateres.
- Linhas sem artigo usam o produto `DIVERSOS`. Isenções com o código oficial (`TaxExemptionCode`) e a menção (`TaxExemptionReason`).
- **Faturas, faturas-recibo, talões, notas e guias não entram no SAF-T**: são comunicadas à e-Fatura documento a documento.

`saft.validate&year=…&type=…` devolve `{ok, errors[]}`. Antes de entregar à DNRE convém ainda validar o ficheiro no SAFTPRO (saft.efatura.cv).

## Notas por programa de origem

| Programa | Como obter o ficheiro |
|----------|----------------------|
| Primavera | Exportação SAF-T de contabilidade do exercício (módulo Contabilidade) |
| PHC | Exportação SAF-T (contabilidade) |
| Sage | Exportação SAF-T de contabilidade |
| Vendus | Normalmente só faturação: use as exportações Excel → modelos CSV de terceiros e lançamentos |
| Excel do gabinete | Adaptar aos modelos CSV |

Estas indicações devem ser confirmadas com ficheiros reais de cada programa; recolher amostras anonimizadas é uma tarefa do [roadmap](10-roadmap.md).
