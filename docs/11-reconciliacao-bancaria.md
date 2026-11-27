# 11. Reconciliação bancária

Módulo `Ideia\Bank` (ecrã **Contabilidade → Reconciliação bancária**). Faz parte da Fase 2 do plano e não depende da DNRE.

## Fluxo

1. **Importar extrato** da conta de depósitos (12x, 13x ou 14x): CSV exportado do homebanking (ou Excel guardado como CSV). Pré-visualização antes de gravar.
2. **Conciliação automática** (corre logo após a importação e pode repetir-se): liga movimentos com o mesmo valor e datas a até 7 dias.
3. **Conciliação manual** dos restantes: selecionar um ou mais movimentos de cada lado com totais iguais (N para M, ex.: um depósito no banco que junta três recebimentos).
4. **Criar lançamento** para o que falta na contabilidade (comissões, juros, débitos diretos): gera e valida o lançamento no diário BCO e concilia-o.
5. **Mapa de reconciliação** à data, imprimível e exportável para Excel.

## Leitura do extrato (`Bank::parse`)

- Procura a linha de cabeçalho nas primeiras 20 linhas (os bancos põem nome, conta e moeda antes da tabela).
- Separador `;`, `,` ou tabulação; Windows-1252 convertido para UTF-8.
- Colunas reconhecidas (com variantes de nome): **Data** (data mov., data valor…), **Descritivo**, **Referência**, **Débito** e **Crédito** *ou* **Montante** com sinal, **Saldo**.
- Linhas de rodapé ("Saldo final", "Total") e linhas sem valor são ignoradas.
- Convenção: montante positivo = entrada no banco = débito da conta 12x.

Modelo: `api.php?action=bank.template` (colunas `data; descricao; referencia; debito; credito; saldo`).

## Duplicados

Cada movimento tem uma impressão digital `SHA-256(data | montante | descritivo | referência | ocorrência)`. Reimportar um extrato, ou um extrato que se sobrepõe ao anterior, só grava os movimentos novos. Dois movimentos iguais no mesmo dia continuam distintos (pela ordem de ocorrência).

## Conciliação automática

Para cada movimento do extrato por conciliar, procura movimentos contabilísticos por conciliar da mesma conta com **o mesmo valor** e **até 7 dias** de diferença:

- se um só candidato tem a referência do banco no campo documento do lançamento (ou vice-versa), é esse;
- senão, o candidato com a data mais próxima, **desde que seja único**;
- havendo empate, não concilia (fica para o utilizador).

Considera apenas lançamentos validados de diários normais (exclui abertura e apuramento) desde a data de início da reconciliação.

## Data de início

Por omissão é o primeiro dia do mês do primeiro movimento de extrato importado; pode mudar-se no ecrã ("Reconciliar desde", guardado em `meta.bank_start_<conta>`). Os movimentos contabilísticos anteriores consideram-se incluídos no saldo inicial do extrato.

## Mapa de reconciliação (`Bank::report`)

```
  Saldo do extrato à data
+ entradas registadas na contabilidade e ainda não no extrato   (depósitos em trânsito)
− saídas registadas na contabilidade e ainda não no extrato     (cheques em circulação)
− entradas no extrato ainda não registadas na contabilidade     (juros, transferências recebidas)
+ saídas no extrato ainda não registadas na contabilidade       (comissões, débitos diretos)
= Saldo contabilístico calculado
  Saldo contabilístico real da conta (exercício da data, com abertura)
  Diferença por explicar   → deve ser 0
```

**Saldo do extrato**: o da coluna Saldo da última linha até à data; se o extrato não tiver saldo, o saldo inicial indicado na importação mais os movimentos. Sem nenhum dos dois, o mapa mostra os pendentes mas não o saldo.

Uma diferença diferente de zero indica saldo inicial errado, extrato em falta (meses sem importar), ou movimentos contabilísticos anteriores à data de início que não estão no saldo inicial.

## Dados (esquema v3)

| Tabela | Conteúdo |
|--------|----------|
| `bank_statements` | Cada importação: conta, ficheiro, período, saldo inicial, nº de movimentos |
| `bank_lines` | Movimentos do extrato: data, descritivo, referência, montante, saldo, impressão digital, `match_id` |
| `bank_matches` | Cada conciliação (manual ou automática) |
| `bank_match_ledger` | Linhas de lançamento conciliadas (`entry_line_id` único: uma linha só pode estar numa conciliação) |

Apagar um extrato remove os seus movimentos e desfaz as conciliações; os lançamentos não são alterados. As conciliações podem desfazer-se individualmente.
