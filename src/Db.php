<?php
declare(strict_types=1);

namespace Ideia;

use PDO;

/**
 * Ligação ao ficheiro de uma empresa (.ideia = base SQLite).
 */
final class Db
{
    public const SCHEMA_VERSION = 8;

    public PDO $pdo;
    public string $path;

    public function __construct(string $path, bool $create = false)
    {
        if (!$create && !is_file($path)) {
            throw new UserError('Ficheiro da empresa não encontrado.');
        }
        $this->path = $path;
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        // journal DELETE (não WAL): o ficheiro fica sempre completo e pode ser copiado sem ficheiros -wal/-shm
        $this->pdo->exec('PRAGMA journal_mode = DELETE');
        $this->pdo->exec('PRAGMA synchronous = FULL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($create) {
            $this->pdo->exec((string) file_get_contents(IDEIA_ROOT . '/database/schema.sql'));
            $this->setMeta('schema_version', (string) self::SCHEMA_VERSION);
            $this->setMeta('app', 'IDEIA Contabilidade');
        }
        $this->migrate();
    }

    private function migrate(): void
    {
        $v = (int) ($this->meta('schema_version') ?? 0);
        if ($v > self::SCHEMA_VERSION) {
            throw new UserError('Este ficheiro foi criado por uma versão mais recente da aplicação. Atualize a aplicação.');
        }
        if ($v < 8) {
            // v2: anexos; v3: reconciliação bancária; v4: índices; v5: vendas; v6: famílias de artigos; v7: compras;
            // v8: e-Fatura (IUD, estado da comunicação, motivo das notas).
            // O esquema só usa CREATE ... IF NOT EXISTS, por isso reaplicá-lo acrescenta apenas o que falta.
            $this->pdo->exec((string) file_get_contents(IDEIA_ROOT . '/database/schema.sql'));
            // colunas novas em tabelas que já existiam
            $cols = array_column($this->all('PRAGMA table_info(items)'), 'name');
            if (!in_array('family_id', $cols, true)) {
                $this->pdo->exec('ALTER TABLE items ADD COLUMN family_id INTEGER REFERENCES item_families(id)');
            }
            $cols = array_column($this->all('PRAGMA table_info(sales_docs)'), 'name');
            foreach (['iud' => 'TEXT', 'efatura_message' => 'TEXT', 'efatura_at' => 'TEXT', 'efatura_mode' => 'INTEGER', 'issue_reason' => 'TEXT'] as $c => $t) {
                if (!in_array($c, $cols, true)) {
                    $this->pdo->exec("ALTER TABLE sales_docs ADD COLUMN $c $t");
                }
            }
            $this->setMeta('schema_version', (string) self::SCHEMA_VERSION);
        }
        // índice criado aqui (e não no schema.sql) porque a coluna iud só existe depois da migração v8
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS ux_sales_iud ON sales_docs(iud) WHERE iud IS NOT NULL');
        // Próximas migrações (que alterem tabelas existentes): if ($v < 4) { ...; $this->setMeta('schema_version', '4'); }
    }

    public function meta(string $key): ?string
    {
        $st = $this->pdo->prepare('SELECT value FROM meta WHERE key = ?');
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    public function setMeta(string $key, ?string $value): void
    {
        $this->pdo->prepare('INSERT INTO meta(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute([$key, $value]);
    }

    public function all(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function run(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($row));
        return (int) $this->pdo->lastInsertId();
    }

    /** @template T @param callable():T $fn @return T */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function audit(string $action, array $details = []): void
    {
        $this->insert('audit_log', ['action' => $action, 'details' => json_encode($details, JSON_UNESCAPED_UNICODE)]);
    }
}
