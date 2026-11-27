# 8. Estatísticas

Módulo `Ideia\Stats` (ecrãs Painel e Estatísticas). Princípios:

- calculado **localmente** a partir dos lançamentos validados (`v_movements`), sem cloud;
- tudo comparável com o **mesmo período do ano anterior** quando existe;
- **do número ao documento**: gráficos e linhas são clicáveis e levam aos lançamentos ou ao extrato da conta;
- tudo exporta para CSV ou imprime/guarda em PDF.

O período por omissão é de janeiro até ao **último mês com movimentos** (`Stats::lastMonth`), para que um exercício a decorrer não seja comparado com um ano inteiro.

## Painel (`stats.dashboard`)

KPIs com variação face ao ano anterior: volume de negócios, margem bruta, EBITDA, resultado líquido, meios financeiros líquidos, IVA apurado. Séries mensais: vendas, gastos, resultado e tesouraria (meios financeiros no fim de cada mês). Os meses depois do período ficam vazios nos gráficos.

## Rácios (`stats.ratios`)

Calculados a partir do balanço à data e da DR do período (`dias = meses × 30,4`).

| Grupo | Rácio | Fórmula |
|-------|-------|---------|
| Liquidez | Liquidez geral | Ativo corrente / Passivo corrente |
| | Liquidez reduzida | (Ativo corrente − Inventários) / Passivo corrente |
| | Liquidez imediata | Meios financeiros líquidos / Passivo corrente |
| Estrutura | Autonomia financeira | Capital próprio / Ativo |
| | Solvabilidade | Capital próprio / Passivo |
| | Endividamento | Passivo / Ativo |
| Rendibilidade | ROE | Resultado líquido / Capital próprio |
| | ROA | Resultado líquido / Ativo |
| | Rendibilidade das vendas | Resultado líquido / Volume de negócios |
| Atividade | PMR | Clientes / (Vendas × 1,15) × dias |
| | PMP | Fornecedores / ((CMVMC + FSE) × 1,15) × dias (aproximação) |

O fator 1,15 assume IVA a 15 % nos saldos de clientes e fornecedores. Cada rácio traz uma frase de leitura para o empresário.

## Estrutura de gastos (`stats.costs`)

Gastos da classe 6 agrupados por conta de 2 dígitos (61, 62, 63…), com detalhe por conta de 3 dígitos e peso percentual.

## Clientes e fornecedores

- `stats.top`: maiores clientes (vendas, débitos em 21) ou fornecedores (créditos em 22) com peso no total.
- `stats.open`: saldos em aberto por terceiro com **antiguidade** em escalões 0–30, 31–60, 61–90 e mais de 90 dias. Os pagamentos são imputados às faturas mais antigas primeiro (FIFO).

## Orçamento (`stats.budget`, `stats.budget_save`)

Orçamento mensal por conta (qualquer nível, ex. `62` para todos os FSE). Compara real e orçamentado por mês e acumulado, com desvio absoluto e percentual.

## Previsão (`stats.forecast`)

Projeção do fecho do ano para vendas, gastos e resultado:

- **sazonal**, quando há ano anterior com movimentos no mesmo período: `projeção = acumulado × (total do ano anterior / acumulado do ano anterior no mesmo período)`;
- **linear**, caso contrário: `projeção = acumulado / meses × 12`.

O método usado é mostrado ao utilizador.

## Indicadores próprios (`stats.custom`, `stats.custom_save`)

O contabilista define indicadores com uma fórmula simples, guardados em `meta.custom_indicators`:

| Elemento | Significado |
|----------|-------------|
| `C(71,72)` | Saldo **credor** líquido das contas começadas por 71 ou 72 (em ECV) |
| `D(62)` | Saldo **devedor** líquido das contas começadas por 62 |
| números | `100`, `0,5`, `1.15` |
| operadores | `+ - * /`, parênteses, menos unário |

Exemplos: `(C(72) - D(62)) / C(72) * 100` (margem de serviços, %), `D(63) / C(71,72) * 100` (peso dos salários).

O avaliador (`Stats::evaluate`) é um analisador descendente recursivo próprio: não usa `eval`, só aceita os elementos acima e recusa divisões por zero e parênteses por fechar.

## Relatório de gestão

Página imprimível que junta KPIs, gráfico mensal, DR resumida, rácios principais, principais gastos e clientes, e uma frase com a previsão do fecho. Pensada para o contabilista entregar mensalmente ao cliente.

## Carteira (`portfolio`)

No ecrã inicial, para gabinetes com várias empresas: para cada empresa, volume de negócios e resultado do ano, último lançamento, última cópia e **alertas** (rascunhos por validar, sem cópia de segurança há mais de 7 dias, cadeia de integridade quebrada, ficheiro ilegível).
