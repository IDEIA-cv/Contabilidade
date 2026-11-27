-- IDEIA Contabilidade — esquema do ficheiro da empresa (.ideia, SQLite)
-- Versão do esquema: ver tabela meta (schema_version). Valores monetários em centavos de ECV (INTEGER).

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS fiscal_years (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    year         INTEGER NOT NULL UNIQUE,
    start_date   TEXT NOT NULL,
    end_date     TEXT NOT NULL,
    locked_until TEXT,                      -- datas <= locked_until não aceitam lançamentos
    status       TEXT NOT NULL DEFAULT 'aberto' CHECK (status IN ('aberto','fechado'))
);

CREATE TABLE IF NOT EXISTS accounts (
    code       TEXT PRIMARY KEY,            -- código SNCRF, só dígitos
    name       TEXT NOT NULL,
    parent     TEXT REFERENCES accounts(code),
    class      INTEGER NOT NULL,            -- 1 a 8
    level      INTEGER NOT NULL,
    taxonomy   TEXT,                        -- reservado para códigos de taxonomia oficiais
    active     INTEGER NOT NULL DEFAULT 1
);
CREATE INDEX IF NOT EXISTS ix_accounts_parent ON accounts(parent);

CREATE TABLE IF NOT EXISTS third_parties (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    nif      TEXT,
    name     TEXT NOT NULL,
    kind     TEXT NOT NULL DEFAULT 'C' CHECK (kind IN ('C','F','A','O')),  -- Cliente, Fornecedor, Ambos, Outro
    address  TEXT,
    city     TEXT,
    country  TEXT NOT NULL DEFAULT 'CV',
    email    TEXT,
    phone    TEXT,
    account  TEXT REFERENCES accounts(code),  -- conta corrente por omissão
    external_id TEXT                          -- identificador no sistema de origem (importação)
);
CREATE INDEX IF NOT EXISTS ix_tp_nif ON third_parties(nif);

CREATE TABLE IF NOT EXISTS journals (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    kind TEXT NOT NULL DEFAULT 'normal'      -- normal, abertura, apuramento
);

CREATE TABLE IF NOT EXISTS vat_codes (
    code     TEXT PRIMARY KEY,
    name     TEXT NOT NULL,
    rate_bp  INTEGER NOT NULL,               -- taxa em pontos base (1500 = 15%)
    kind     TEXT NOT NULL CHECK (kind IN ('liquidado','dedutivel','isento')),
    account  TEXT REFERENCES accounts(code)
);

CREATE TABLE IF NOT EXISTS entries (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    fiscal_year   INTEGER NOT NULL REFERENCES fiscal_years(year),
    journal       TEXT NOT NULL REFERENCES journals(code),
    number        INTEGER,                   -- sequencial por diário e exercício, atribuído na validação
    date          TEXT NOT NULL,
    description   TEXT NOT NULL,
    document      TEXT,
    status        TEXT NOT NULL DEFAULT 'rascunho' CHECK (status IN ('rascunho','validado')),
    source        TEXT NOT NULL DEFAULT 'manual',
    reverses      INTEGER REFERENCES entries(id),
    reversed_by   INTEGER REFERENCES entries(id),
    total_cents   INTEGER NOT NULL DEFAULT 0,
    created_at    TEXT NOT NULL DEFAULT (datetime('now')),
    validated_at  TEXT,
    validated_seq INTEGER,                   -- ordem de validação (cadeia de hash)
    hash          TEXT,
    prev_hash     TEXT,
    UNIQUE (fiscal_year, journal, number)
);
CREATE INDEX IF NOT EXISTS ix_entries_date ON entries(date);
CREATE INDEX IF NOT EXISTS ix_entries_seq ON entries(validated_seq);              -- v4: cadeia de hash e numeração
CREATE INDEX IF NOT EXISTS ix_entries_year ON entries(fiscal_year, status, date);  -- v4: relatórios por exercício

CREATE TABLE IF NOT EXISTS entry_lines (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id      INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
    line_no       INTEGER NOT NULL,
    account       TEXT NOT NULL REFERENCES accounts(code),
    third_party   INTEGER REFERENCES third_parties(id),
    description   TEXT,
    debit_cents   INTEGER NOT NULL DEFAULT 0 CHECK (debit_cents >= 0),
    credit_cents  INTEGER NOT NULL DEFAULT 0 CHECK (credit_cents >= 0),
    vat_code      TEXT REFERENCES vat_codes(code),
    vat_base_cents INTEGER,
    cost_center   TEXT
);
CREATE INDEX IF NOT EXISTS ix_lines_entry ON entry_lines(entry_id);
CREATE INDEX IF NOT EXISTS ix_lines_account ON entry_lines(account);

CREATE TABLE IF NOT EXISTS budgets (
    account      TEXT NOT NULL REFERENCES accounts(code),
    year         INTEGER NOT NULL,
    month        INTEGER NOT NULL CHECK (month BETWEEN 1 AND 12),
    amount_cents INTEGER NOT NULL,          -- valor esperado com o sinal natural (gastos e rendimentos positivos)
    PRIMARY KEY (account, year, month)
);

CREATE TABLE IF NOT EXISTS audit_log (
    id      INTEGER PRIMARY KEY AUTOINCREMENT,
    ts      TEXT NOT NULL DEFAULT (datetime('now')),
    action  TEXT NOT NULL,
    details TEXT
);
CREATE INDEX IF NOT EXISTS ix_audit_action ON audit_log(action, ts);              -- v4

CREATE TABLE IF NOT EXISTS import_log (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    ts        TEXT NOT NULL DEFAULT (datetime('now')),
    source    TEXT NOT NULL,
    filename  TEXT,
    summary   TEXT,
    content   BLOB
);

-- Documentos anexos aos lançamentos (esquema v2). O conteúdo fica dentro do ficheiro da empresa, para a cópia ser completa.
CREATE TABLE IF NOT EXISTS entry_attachments (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    entry_id   INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
    filename   TEXT NOT NULL,
    mime       TEXT NOT NULL,
    size       INTEGER NOT NULL,
    sha256     TEXT NOT NULL,
    content    BLOB NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS ix_attachments_entry ON entry_attachments(entry_id);

-- ---------------------------------------------------------------- reconciliação bancária (esquema v3)

CREATE TABLE IF NOT EXISTS bank_statements (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    account       TEXT NOT NULL REFERENCES accounts(code),   -- conta de depósitos (12x)
    filename      TEXT,
    imported_at   TEXT NOT NULL,
    date_from     TEXT,
    date_to       TEXT,
    opening_cents INTEGER,                                   -- saldo inicial do extrato (indicado ou deduzido da coluna saldo)
    lines         INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bank_matches (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    account    TEXT NOT NULL,
    created_at TEXT NOT NULL,
    auto       INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bank_lines (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    statement_id  INTEGER NOT NULL REFERENCES bank_statements(id) ON DELETE CASCADE,
    account       TEXT NOT NULL,
    date          TEXT NOT NULL,
    description   TEXT NOT NULL,
    reference     TEXT,
    amount_cents  INTEGER NOT NULL,                          -- positivo = entrada no banco, negativo = saída
    balance_cents INTEGER,                                   -- saldo indicado pelo banco após o movimento
    fingerprint   TEXT NOT NULL,                             -- evita importar o mesmo movimento duas vezes
    match_id      INTEGER REFERENCES bank_matches(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS ix_bank_lines_account ON bank_lines(account, date);
CREATE INDEX IF NOT EXISTS ix_bank_lines_fp ON bank_lines(account, fingerprint);

CREATE TABLE IF NOT EXISTS bank_match_ledger (
    match_id      INTEGER NOT NULL REFERENCES bank_matches(id) ON DELETE CASCADE,
    entry_line_id INTEGER NOT NULL UNIQUE REFERENCES entry_lines(id)
);

-- Vista de movimentos validados (base de todos os relatórios e estatísticas)
CREATE VIEW IF NOT EXISTS v_movements AS
SELECT l.id AS line_id, e.id AS entry_id, e.fiscal_year, e.journal, e.number, e.date,
       CAST(substr(e.date, 6, 2) AS INTEGER) AS month,
       e.description AS entry_description, e.document, l.account, l.third_party,
       l.description, l.debit_cents, l.credit_cents, l.vat_code, l.vat_base_cents, l.cost_center,
       j.kind AS journal_kind
FROM entry_lines l
JOIN entries e ON e.id = l.entry_id
JOIN journals j ON j.code = e.journal
WHERE e.status = 'validado';
