# 16. Imobilizado

Menu **Contabilidade → Imobilizado**. Regista os bens da empresa (edifícios, equipamentos, viaturas, mobiliário, programas), calcula as depreciações e amortizações e lança-as na contabilidade. Código em `src/Assets.php` e `assets/js/views/assets.js`.

## Ficha do bem

| Campo | Notas |
|-------|-------|
| Código | Automático: `IM0001`, `IM0002`… |
| Categoria | Sugere a conta e a taxa anual (tabela abaixo) |
| Conta | Conta de movimento 43 (tangível) ou 44 (intangível), sem ser 438/448 |
| Aquisição / entrada em funcionamento | A depreciação começa no mês de entrada em funcionamento (vazio = data de aquisição) |
| Custo, valor residual | O valor a depreciar é custo − residual |
| Taxa anual | Quotas constantes. Confirmar com o contabilista a tabela legal em vigor |
| Depreciações anteriores | Para bens que vêm de outro programa: valor acumulado e o último exercício incluído |
| Fornecedor, fatura, localização, notas | Informativos |

Depois de haver depreciações lançadas, o custo, a taxa e as datas ficam fechados (anule primeiro as depreciações para os corrigir). Só se apaga um bem sem depreciações lançadas nem abate.

O registo do bem **não lança a compra**: a aquisição é lançada como sempre (fatura de fornecedor em Compras, ou lançamento manual a débito da conta 43/44).

### Categorias e taxas sugeridas

| Categoria | Conta | Taxa |
|-----------|-------|------|
| Edifícios e outras construções | 432 | 2% |
| Equipamento básico | 433 | 12,5% |
| Viaturas ligeiras | 434 | 25% |
| Viaturas pesadas | 434 | 20% |
| Mobiliário e equipamento de escritório | 435 | 12,5% |
| Equipamento informático | 435 | 33,33% |
| Outros ativos fixos tangíveis | 437 | 20% |
| Programas de computador | 443 | 33,33% |
| Outros ativos intangíveis | 442 | 20% |

## Cálculo

- Quota anual = (custo − residual) × taxa. No primeiro ano contam os meses desde a entrada em funcionamento (inclusive); no ano do abate, até ao mês do abate.
- A última quota é o que falta para chegar ao valor a depreciar (nunca passa).
- Os valores já lançados têm prioridade sobre o cálculo, para o plano bater sempre com a contabilidade.

## Lançar as depreciações do exercício

No ecrã do Imobilizado, com o exercício escolhido no topo, **Lançar depreciações de AAAA** cria um lançamento em 31/12 no diário de operações diversas, documento `IMOB AAAA`, com uma linha por bem:

| Bem | Débito | Crédito |
|-----|--------|---------|
| Tangível | 642 Depreciações do exercício | 438 Depreciações acumuladas |
| Intangível | 643 Amortizações do exercício | 448 Amortizações acumuladas |

Regras: os exercícios têm de ser lançados por ordem; **Anular** estorna o lançamento (fica o registo) e só é possível no último exercício lançado. Fazer antes do fecho do exercício.

## Abate ou venda

**Abater ou vender** na ficha do bem pede data, motivo (venda, abate, roubo, sinistro, doação), valor de venda e conta onde entra o dinheiro. Num só lançamento:

1. depreciação do ano até ao mês do abate (se as do ano ainda não foram lançadas);
2. saída das depreciações acumuladas (débito 438/448) e do custo (crédito 43/44);
3. valor de venda a débito da conta indicada;
4. diferença para 688 (perda) ou 788 (ganho).

O IVA da venda de um bem não é tratado aqui: se a venda precisar de fatura, emita-a e acerte as contas com o contabilista.

## Mapa de depreciações

A tabela do ecrã é o mapa do exercício: custo, acumuladas no início, do exercício (lançada ou por lançar), acumuladas no fim e valor líquido, com totais. Exporta para Excel e imprime.

## Dados (esquema v11)

- `fixed_assets`: ficha do bem, `status` `ativo` ou `abatido`, dados do abate e `disposal_entry_id`.
- `fixed_asset_depreciations`: (`asset_id`, `year`) → `amount_cents`, `entry_id`. Uma linha por bem e exercício lançado.

## API

| Ação | Método | Parâmetros |
|------|--------|-----------|
| `assets.meta` | GET | — categorias e contas 43/44 de movimento |
| `assets.list` | GET | `year`, `status?`, `category?`, `q?` — mapa do exercício com totais |
| `assets.get` | GET | `id` — ficha e plano |
| `assets.save` | POST | campos da ficha (`id` para alterar) |
| `assets.delete` | POST | `id` |
| `assets.preview` | GET | `year` — linhas do lançamento e exercícios em falta |
| `assets.post` / `assets.unpost` | POST | `year` (contabilista) |
| `assets.dispose` | POST | `id`, `date`, `reason`, `value`, `account` (contabilista) |
