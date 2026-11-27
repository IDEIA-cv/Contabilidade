# ADR 0003 — Lançamentos imutáveis e cadeia de integridade SHA-256

- **Estado:** aceite

## Decisão
1. Um lançamento validado não pode ser alterado nem apagado; corrige-se com um **estorno** (lançamento simétrico ligado ao original).
2. A numeração é sequencial por diário e exercício e só é atribuída na validação.
3. Cada lançamento validado guarda o SHA-256 dos seus dados e do hash anterior (`prev_hash`), formando uma cadeia pela ordem de validação. A aplicação verifica a cadeia e mostra o primeiro lançamento adulterado.

## Razões
- Exigência de auditabilidade e de confiança do fisco e dos revisores.
- Como o ficheiro está no computador do utilizador, não é possível impedir alterações externas com SQLite; é possível **detetá-las**.

## Consequências
- Erros exigem estorno + novo lançamento (prática contabilística normal).
- A cadeia não protege contra quem recalcule todos os hashes; uma fase futura pode assinar periodicamente o último hash (ex.: com a chave do e-Fatura ou guardando-o fora do ficheiro).
