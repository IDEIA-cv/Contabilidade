# 14. Compras e dívidas a fornecedores

Pedido do responsável do projeto em 27/11/2026, depois da v0.3. Registo das faturas recebidas, com lançamento automático, valor em dívida, pagamentos, dívidas a fornecedores por antiguidade e mapa de pagamentos.

Código: `src/Purchases.php` (regras), `assets/js/views/purchases.js` (ecrãs). Testes: `tests/unit/PurchasesTest.php` e o fluxo de compras em `tests/e2e/smoke.mjs`.

## Documentos

| Código | Documento | Fornecedor | Linhas | Lançamento (diário) |
|---|---|---|---|---|
| FC | Fatura de fornecedor | obrigatório | sim | COM: débito das contas de gasto/compra (líquido) e IVA dedutível 2432; crédito do fornecedor (`third_parties.account`, por omissão 221) |
| FP | Despesa paga (fatura-recibo, talão) | opcional | sim | COM: igual à FC, mas o crédito vai para a caixa 111 ou o banco 121 |
| NF | Nota de crédito de fornecedor | da fatura | sim | COM: movimentos da FC ao contrário (base de IVA negativa) |
| PG | Pagamento a fornecedor | obrigatório | não, liquida faturas | CXA (numerário) ou BCO: débito do fornecedor, crédito da caixa ou do banco |

- O número do documento do fornecedor (`supplier_doc`) é obrigatório em FC, FP e NF. O mesmo número não pode ser registado duas vezes para o mesmo fornecedor e tipo.
- Cada registo recebe também um número interno por tipo e ano (`FC 2025/1`, `PG 2025/3`…), atribuído só no registo.
- A data não pode ser futura. O vencimento por omissão é a 30 dias.
- **Contas das linhas:** contas de movimento das classes 3 (compras e inventários), 4 (investimentos) ou 6 (gastos).
  - Para cada fornecedor é sugerida a última conta usada.
  - Sem histórico, a sugestão é 311 para produtos e 6268 para o resto.
- **IVA:** só códigos dedutíveis ou isentos (DED, ISE). As linhas de IVA levam `vat_code` e `vat_base_cents`, por isso as compras entram no **Apuramento do IVA**.
- **Correções e anulação:** um registo não se altera.
  - Corrige-se com nota de crédito (**Registar nota de crédito do fornecedor** cria um rascunho com as linhas da fatura) ou anula-se com motivo, o que estorna o lançamento.
  - Uma fatura com pagamentos ou notas de crédito ativos só se anula depois de anular esses documentos.
- A digitalização da fatura anexa-se ao lançamento gerado (ligação na página do documento).

## Pagamentos

- **Registar pagamento** numa fatura: valor parcial ou total.
- Na ficha do fornecedor (**Pagar faturas**), em **Dívidas a fornecedores** (**Pagar**) e no **Mapa de pagamentos**: um pagamento pode liquidar várias faturas do mesmo fornecedor (`purchase_settlements`).
- O valor pago a cada fatura não pode exceder o valor em dívida.

## Em dívida, dívidas e mapa

- **Em dívida** de uma FC = total − pagamentos registados − notas de crédito registadas (`Purchases::openAmount`).
- **Dívidas a fornecedores** (`Purchases::creditors`): total por fornecedor por antiguidade a partir do vencimento (a vencer, 1–30, 31–60, 61–90, mais de 90 dias) e próximo vencimento, à data escolhida. Exporta para Excel e imprime.
- **Mapa de pagamentos** (`Purchases::schedule`): faturas em dívida agrupadas em já vencidas, esta semana (até domingo), próximos 30 dias e mais tarde.
- **Ficha do fornecedor** (`#/c/<empresa>/terceiro/<id>`): por pagar, vencido, compras e pagamentos do ano. Separadores: faturas por pagar, movimentos e extrato (da contabilidade), documentos de compra.
- O painel avisa quando há faturas de fornecedores vencidas ou a vencer nos próximos 7 dias.

## Esquema (v7)

- `purchase_docs`: tipo, série/número interno, estado (rascunho, registado, anulado), `supplier_doc`, datas, fornecedor, origem (NF), meio de pagamento, totais, `entry_id`, motivo de anulação.
- `purchase_doc_lines`: descrição, quantidade, valor, desconto, IVA, conta, líquido e IVA.
- `purchase_settlements`: pagamento → fatura → valor.

Os cálculos das linhas são os das vendas (`Sales::lineAmounts`).

## Ainda não incluído

- Leitura automática das faturas recebidas na e-Fatura (DFE recebidos). Depende do acesso à API.
- Artigos de compra com inventário (stock).
