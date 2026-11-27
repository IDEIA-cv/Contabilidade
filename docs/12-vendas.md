# 12. Vendas e documentos comerciais

Módulo antecipado da Fase 2 por decisão do responsável do projeto (27/11/2026): a emissão de documentos está completa e a **comunicação à DNRE (e-Fatura) fica para quando houver especificações e credenciais**. Até lá, os documentos fiscais ficam marcados como *e-Fatura: por comunicar*.

Código: `src/Sales.php` (regras), `src/SalesPrint.php` (impressão A4), `assets/js/views/sales.js` (ecrãs). Testes: `tests/unit/SalesTest.php` e o fluxo de vendas em `tests/e2e/smoke.mjs`.

## Tipos de documento

| Código | Documento | Cliente obrigatório | Lançamento contabilístico | Tipo na e-Fatura (futuro) | Prazo por omissão |
|---|---|---|---|---|---|
| OR | Orçamento | sim | não | — | validade 30 dias |
| PF | Fatura pró-forma | sim | não | — | validade 15 dias |
| GE | Guia de entrega | sim | não | DTE | — |
| GT | Guia de transporte | sim | não | DTE | — |
| FT | Fatura | sim | sim (cliente a débito) | FTE | vencimento 30 dias |
| FR | Fatura-recibo | não (consumidor final) | sim (caixa/banco a débito) | FRE | — |
| ND | Nota de débito | sim | sim | NDE | vencimento 30 dias |
| NC | Nota de crédito | da fatura de origem | sim (movimentos ao contrário) | NCE | — |
| RC | Recibo | sim | sim (caixa/banco a débito, cliente a crédito) | RCE | — |

NC e RC não se criam diretamente. A nota de crédito parte de uma fatura emitida (**Emitir nota de crédito**) e o recibo de **Registar pagamento** numa fatura ou nota de débito.

## Numeração

- Cada tipo tem a sua numeração e a série é o ano da data do documento: `FT 2026/1`, `FT 2026/2`, `OR 2026/1`…
- O número só é atribuído na **emissão**. Os rascunhos não têm número e podem ser apagados sem deixar buracos.
- A numeração segue a ordem das datas: não é possível emitir um documento com data anterior ao último da mesma série.
- Documentos fiscais não podem ter data futura.
- Um número nunca é reutilizado: um documento anulado mantém o número.
- Tabela `doc_series` guarda o último número, a última data e o último hash de cada série.

## Integridade

- Ao emitir calcula-se `hash = SHA-256(data; momento da emissão; n.º; total; NIF do cliente; hash anterior da série)`.
- `Sales::verifyChain` verifica números sem saltos e hashes. O resultado aparece em **Empresa e cópias → Numeração dos documentos de venda**.
- Os dados do cliente (nome, NIF, morada) são copiados para o documento na emissão. Alterar a ficha do cliente depois não altera documentos emitidos.
- Documentos emitidos não podem ser alterados. Corrige-se com nota de crédito, ou anula-se com motivo, o que estorna o lançamento. Uma fatura com recibos ou notas de crédito ativos só se anula depois de anular esses documentos.

## Cálculos

Valores em centavos e quantidades em milésimas (`qty_milli`). Para cada linha:

```
bruto    = arred(qtd × preço)
desconto = arred(bruto × desconto%)
líquido  = bruto − desconto
IVA      = arred(líquido × taxa)
```

Arredondamento ao centavo, metade para cima. A mesma função existe no servidor (`Sales::lineAmounts`) e no browser (`lineAmounts` em `sales.js`), para o total no ecrã ser igual ao gravado. Linhas isentas exigem motivo de isenção nos documentos fiscais e nas guias.

## Lançamento automático

Na emissão de FT, FR, ND, NC e RC é criado e validado um lançamento, ligado ao documento por `entry_id`.

| Documento | Diário | Débito | Crédito |
|---|---|---|---|
| FT / ND | VEN | conta corrente do cliente (`third_parties.account`, por omissão 211) | rendimentos por conta do artigo (71x/72x) + IVA liquidado (conta do código de IVA, 2433) |
| FR | VEN | caixa (111) ou banco (121), conforme o meio de pagamento | igual à FT |
| NC | VEN | rendimentos + IVA (base negativa no mapa do IVA) | cliente |
| RC | CXA (numerário) ou BCO | caixa / banco | cliente |

As contas de caixa e banco podem ser mudadas em `meta.sales_cash_account` e `meta.sales_bank_account`. As linhas de IVA levam `vat_code` e `vat_base_cents`, e as linhas isentas levam o código ISE na conta de rendimentos. Assim as vendas entram automaticamente no **Apuramento do IVA**.

## Dívidas de clientes

- **Em dívida** de uma FT/ND = total − recibos emitidos − notas de crédito emitidas (`Sales::openAmount`).
- **Dívidas de clientes** (`Sales::debtors`): total por cliente com antiguidade a partir da data de vencimento (a vencer, 1–30, 31–60, 61–90, mais de 90 dias), à data escolhida. Exporta para Excel e imprime.
- **Ficha do cliente** (`#/c/<empresa>/cliente/<id>`): em dívida, vencido, faturado e recebido no ano. Tem três separadores: faturas por pagar (com **Registar pagamento** em cada linha), extrato de conta corrente com saldo acumulado, e últimos documentos.
- **Movimentos e extrato** (clientes e fornecedores): extrato de conta corrente a partir da **contabilidade** (`Reports::partyStatement`), com saldo inicial do exercício, todos os movimentos validados do terceiro nas contas correntes (classe 2, exceto Estado 24: 21 clientes, 22 fornecedores, 27/28 outros), saldo acumulado, filtro por conta, Excel e impressão. Inclui faturas emitidas aqui, faturas de fornecedores, pagamentos e histórico importado.
  - O saldo segue o sentido natural: num cliente é positivo quando o cliente deve; num fornecedor é positivo quando a empresa deve.
  - A ficha do fornecedor abre em `#/c/<empresa>/terceiro/<id>`. Em **Clientes e fornecedores**, cada linha tem a ligação "extrato".
- **Imprimir extrato** (`party.statement_print`) gera uma página A4 para enviar em PDF. No cliente junta a lista de faturas por pagar e o IBAN da empresa.
- O painel mostra o total por receber e o vencido, com ligação para esta página.

## Artigos

Tabela `items`: código (automático P0001/S0001 se vazio), descrição, tipo (produto/serviço), unidade, preço sem IVA, código de IVA, motivo de isenção, conta de rendimentos (por omissão 711 para produtos e 721 para serviços), código de barras e ativo.

- No formulário pode escrever o preço com IVA, e o preço sem IVA é calculado.
- Um artigo já usado em documentos não se apaga: fica inativo.
- **Famílias de artigos** (esquema v6, tabela `item_families`): tipos definidos pelo utilizador, por exemplo Bebidas, Materiais ou Formação. Criam-se em **Artigos → Famílias de artigos**.
  - Cada família tem tipo (produtos/serviços), IVA, motivo de isenção e conta de rendimentos por omissão.
  - Ao escolher a família num artigo, esses campos são preenchidos, e pode alterá-los.
  - A lista de artigos filtra por família.
  - Apagar uma família não apaga os artigos: ficam sem família.

## Impressão

`sales.print` devolve uma página HTML A4 com logótipo, dados da empresa, cliente, linhas, quadro de IVA por taxa com motivos de isenção, totais, observações, IBAN e rodapé.

- O botão **Imprimir / guardar PDF** usa o diálogo do browser, que funciona offline e sem bibliotecas.
- Os rascunhos e os documentos anulados levam uma marca de água.
- Os dados da empresa para os documentos editam-se em **Empresa e cópias**: telefone, email, IBAN, rodapé e logótipo (PNG/JPG até 300 KB, guardado no ficheiro da empresa).

## Esquema (v5 e v6)

Tabelas `item_families` (v6), `items` (com `family_id` desde a v6), `doc_series`, `sales_docs`, `sales_doc_lines`, `sales_settlements` (recibo → faturas liquidadas). Ver [modelo de dados](04-modelo-de-dados.md).

## Desempenho

5 000 faturas e 2 500 recibos foram emitidos em 19 s (cerca de 2,5 ms cada, com lançamento contabilístico). A lista, a lista de dívidas, o resumo e a verificação da cadeia demoram 10 a 25 ms.

## Falta (depende da DNRE)

- Geração do XML do DFE com IUD, assinatura, envio e fila de contingência.
- Secção `SourceDocuments/SalesInvoices` no SAF-T CV, quando houver o XSD oficial.
- Validação dos códigos de motivo de isenção oficiais. Hoje são texto, com sugestões.
