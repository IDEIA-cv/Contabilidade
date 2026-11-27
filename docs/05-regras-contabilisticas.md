# 5. Regras contabilísticas

Este documento descreve **o que o código faz** em termos contabilísticos. Deve ser revisto por um contabilista certificado antes de uso em produção.

## Plano de contas (SNCRF)

- Plano base em `database/sncrf.php` (classes 1 a 8), carregado ao criar a empresa.
- A hierarquia vem do código: a mãe de `24331` é a conta existente com o maior prefixo (`2433`, depois `243`…).
- **Só contas sem filhas (de movimento) recebem lançamentos.** Ao criar `62681` debaixo de `6268`, a `6268` deixa de ser de movimento; por isso é recusado se `6268` já tiver movimentos.

## Ciclo de vida de um lançamento

```
 novo ──guardar──► rascunho ──validar──► validado ──estornar──► (novo lançamento validado com débitos/créditos trocados)
                     │  ▲
                     └──┘ editar / apagar
```

- **Rascunho**: editável e apagável, não tem número, **não entra em relatórios** (o painel avisa quantos há).
- **Validação** (`Ledger::validateEntry`), dentro de uma transação:
  1. débitos = créditos e total > 0; pelo menos 2 linhas; nenhuma linha com débito e crédito;
  2. todas as contas existem, estão ativas e são de movimento;
  3. a data pertence a um exercício **aberto** e é posterior a `locked_until`;
  4. atribui `number = max(number) + 1` no diário e exercício;
  5. atribui `validated_seq`, `prev_hash` e `hash` (cadeia de integridade).
- **Estorno** (`reverseEntry`): cria e valida um lançamento com as mesmas linhas trocadas (débito ↔ crédito), na data indicada (por omissão a de hoje), e liga os dois por `reverses`/`reversed_by`. Um lançamento só pode ser estornado uma vez.

## Integridade

Cada lançamento validado guarda:

```
hash = SHA-256( prev_hash | id | exercício | diário | número | data | descrição | documento
                | conta:débito:crédito:terceiro  (por cada linha, pela ordem) )
```

`prev_hash` é o `hash` do lançamento validado anterior (por `validated_seq`); o primeiro tem `prev_hash` vazio. `Ledger::verifyChain` recalcula toda a cadeia e indica o primeiro lançamento quebrado. Assim, qualquer alteração ao ficheiro feita fora da aplicação (ex.: com um editor de SQLite) é detetada no ecrã **Empresa e cópias**. Ver [ADR 0003](adr/0003-imutabilidade-e-cadeia-hash.md).

## Saldos e relatórios

Base: `Reports::leafBalances()` soma débito − crédito por conta de movimento a partir de `v_movements`, para um exercício e intervalo de meses, com ou sem lançamentos de abertura e excluindo tipos de diário quando pedido.

| Relatório | Regra |
|-----------|-------|
| **Balancete** | Saldos das contas folha agregados para todos os níveis (cada conta-mãe soma as filhas). Colunas: abertura, débitos e créditos do período, saldo devedor/credor. Filtro por nível |
| **Extrato (razão)** | Movimentos de uma conta (ou de todas as filhas de uma conta-mãe) com saldo acumulado, a partir do saldo anterior ao período |
| **Diário** | Lançamentos validados por ordem de data/diário/número, opcionalmente de um só diário |
| **Balanço** | À data do mês "até", excluindo o diário de apuramento (para mostrar o resultado do período mesmo depois do fecho) |
| **DR por naturezas** | Classes 6 e 7 do período, excluindo o diário de apuramento, com a coluna do ano anterior |

### Mapeamento do balanço

| Rubrica | Contas | Regra do saldo |
|---------|--------|----------------|
| Ativos fixos tangíveis / Propriedades de investimento / Intangíveis / Investimentos financeiros / Em curso | 43 / 42 / 44 / 41 / 45 | saldo líquido devedor (inclui depreciações acumuladas, que reduzem) |
| Inventários | 3 | líquido devedor |
| Clientes | 21 | só contas folha com saldo **devedor** |
| Estado (ativo) | 24 | contas folha devedoras (ex.: IVA dedutível 2432, IVA a recuperar) |
| Outras contas a receber | 22, 23, 25, 27 | folhas devedoras |
| Meios financeiros líquidos | 1 | líquido devedor |
| Capital, reservas, resultados transitados… | 51 … 59 | líquido credor |
| Resultado líquido do período | 6, 7, 8 | líquido credor |
| Financiamentos obtidos | 25 | folhas credoras (apresentado no passivo não corrente: simplificação a rever) |
| Fornecedores | 22 | folhas credoras |
| Estado (passivo) | 24 | folhas credoras |
| Outras contas a pagar | 21, 23, 27 | folhas credoras |

A classe 2 é repartida por **sinal de cada conta folha**: um cliente com saldo credor (adiantamento) vai para o passivo. O balanço verifica e mostra se ativo = capital próprio + passivo.

### Linhas da DR

| Chave | Rubrica | Contas |
|-------|---------|--------|
| `vn` | Vendas e serviços prestados | 71, 72 |
| `sub` | Subsídios à exploração | 75 |
| `var` | Variação nos inventários da produção | 73 |
| `tpe` | Trabalhos para a própria entidade | 74 |
| `cmv` | Custo das mercadorias vendidas e das matérias consumidas | 61 |
| `fse` | Fornecimentos e serviços externos | 62 |
| `pes` | Gastos com o pessoal | 63 |
| `imp` | Imparidades, provisões e reversões | 65, 67, 76 |
| `jv` | Aumentos/reduções de justo valor | 66, 77 |
| `or` | Outros rendimentos | 78 |
| `og` | Outros gastos | 68 |
| — | **EBITDA** (soma das linhas acima), depreciações (64), **EBIT**, juros obtidos (79) e suportados (69), resultado antes de impostos, imposto (812), **resultado líquido** | |

Rendimentos com sinal positivo e gastos com sinal negativo (`Reports::incomeFromLeaf()`).

## IVA

- Contas: **2432** IVA dedutível, **2433** IVA liquidado, **2434** regularizações, **2436** IVA a pagar, **2437** IVA a recuperar.
- `Vat::settlement(ano, de, até)` soma os movimentos dessas contas no período, ignorando lançamentos de apuramento anteriores (descrição começada por "Apuramento de IVA"). Devolve totais, resultado (a pagar / a recuperar), mapa por código de IVA (taxa, base, imposto) e por terceiro (NIF, base, imposto).
- A **base** vem do campo "Base IVA" das linhas. Linhas sem base aparecem só no total.
- `Vat::postSettlement` cria e valida no diário `DIV`, no último dia do período, o lançamento que salda 2432/2433/2434 contra 2436 ou 2437. Um período (ou parte dele) já apurado e não estornado não pode ser apurado outra vez: `settlement` devolve `posted` com os períodos já apurados e o ecrã mostra a ligação ao lançamento.
- Taxa normal de Cabo Verde: 15 %. Outras taxas configuram-se como novos códigos de IVA.

## Inventário e custo das mercadorias vendidas (CMVMC)

Sistema de inventário periódico (`Ideia\Inventory`, ecrã **Exercícios e fecho → Inventário e custo das mercadorias vendidas**):

- As faturas de fornecedor de mercadorias e matérias são lançadas em **311/312 (Compras)**.
- No apuramento, a uma data dentro do exercício, o utilizador indica o **inventário final contado** de cada natureza. É criado um lançamento validado (diário `DIV`, `source = cmvmc`, documento `CMVMC aaaa-mm-dd`):
  1. compras para o inventário: débito **32/33**, crédito **311/312** (saldo da 31 até à data);
  2. custo: débito **611/612**, crédito **32/33** = inventário inicial + compras − inventário final.
- Pode repetir-se (por exemplo todos os meses); cada apuramento tem de ter data posterior ao anterior e só o mais recente se anula (estorno).
- Recusado: inventário final negativo ou maior do que inventário inicial + compras; data fora do exercício; contas inexistentes ou com subcontas.
- O **fecho do exercício exige** a conta 31 saldada (todas as compras passadas ao inventário). O inventário final passa para o exercício seguinte no lançamento de abertura.
- O painel avisa quando há compras por passar ao inventário (a margem bruta ainda não desconta o custo do que foi vendido).

## Fecho do exercício

`Closing::closeYear(ano)`:

1. Lançamento no diário `APU` (31/12): salda cada conta folha das classes 6 e 7, com contrapartida em **811** (resultado antes de impostos).
2. Segundo lançamento: transfere 811 e 812 (imposto) para **818** (resultado líquido).
3. Marca o exercício como `fechado`. Não há reabertura: faça cópia de segurança antes.

`Closing::openNextYear(ano)`:

1. Cria o exercício seguinte, se não existir.
2. Lançamento no diário `ABE` (01/01) com os saldos de todas as contas folha das classes 1 a 5; o saldo da classe 8 passa para **56 — Resultados transitados**.
3. Recusa se o exercício seguinte já tiver lançamento de abertura.

## Bloqueio de período

Depois de entregar uma declaração (ex.: IVA de março), bloqueie até 31/03: deixam de ser aceites validações com data igual ou anterior.
