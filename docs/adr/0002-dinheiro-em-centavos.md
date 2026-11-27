# ADR 0002 — Valores monetários em centavos inteiros

- **Estado:** aceite

## Decisão
Todos os valores são guardados e calculados como **inteiros em centavos de ECV** (`*_cents INTEGER`). A conversão de/para texto faz-se só nas margens (`Money::toCents`, `Money::format` no PHP; `toCents`/`money` no JS).

## Razões
- Números de vírgula flutuante produzem erros de arredondamento (0,1 + 0,2 ≠ 0,3) que desequilibram lançamentos.
- O SQLite soma inteiros de forma exata.

## Consequências
- O IVA calcula-se com divisão inteira (`intdiv`) e o arredondamento é explícito.
- Rácios e indicadores (que são quocientes) usam `float` só no resultado final.
