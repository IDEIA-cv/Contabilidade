<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Gestão dos ficheiros das empresas na pasta de dados. Cada empresa = um ficheiro <id>.ideia.
 */
final class Companies
{
    public static function dataDir(): string
    {
        $dir = (string) Config::get('data_dir', IDEIA_ROOT . '/data');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UserError("Não foi possível criar a pasta de dados: {$dir}");
        }
        return $dir;
    }

    public static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id);
    }

    public static function path(string $id): string
    {
        if (!self::validId($id)) {
            throw new UserError('Identificador de empresa inválido.');
        }
        return self::dataDir() . '/' . $id . '.ideia';
    }

    public static function open(string $id): Db
    {
        return new Db(self::path($id));
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dataDir() . '/*.ideia') ?: [] as $file) {
            $id = basename($file, '.ideia');
            if (!self::validId($id)) {
                continue;
            }
            try {
                $db = new Db($file);
                $out[] = [
                    'id' => $id,
                    'name' => $db->meta('company_name') ?? $id,
                    'nif' => $db->meta('company_nif'),
                    'years' => array_map('intval', array_column($db->all('SELECT year FROM fiscal_years ORDER BY year'), 'year')),
                    'size' => filesize($file),
                    'modified' => date('Y-m-d H:i', (int) filemtime($file)),
                ];
            } catch (\Throwable $e) {
                $out[] = ['id' => $id, 'name' => $id, 'error' => 'Ficheiro ilegível: ' . $e->getMessage()];
            }
        }
        usort($out, static fn($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public static function slug(string $name): string
    {
        $s = strtolower(trim($name));
        $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return substr($s !== '' ? $s : 'empresa', 0, 40);
    }

    /**
     * Cria um ficheiro de empresa com plano de contas, diários, códigos de IVA e o primeiro exercício.
     */
    public static function create(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new UserError('Indique o nome da empresa.');
        }
        $nif = preg_replace('/\D/', '', (string) ($data['nif'] ?? '')) ?? '';
        if ($nif !== '' && strlen($nif) !== 9) {
            throw new UserError('O NIF deve ter 9 dígitos.');
        }
        $year = (int) ($data['year'] ?? date('Y'));
        if ($year < 1990 || $year > 2100) {
            throw new UserError('Exercício inválido.');
        }
        $base = self::slug($name);
        $id = $base;
        $n = 2;
        while (is_file(self::path($id))) {
            $id = $base . '-' . $n++;
        }
        $db = new Db(self::path($id), true);
        $db->transaction(static function () use ($db, $name, $nif, $year, $data) {
            $db->setMeta('company_name', $name);
            $db->setMeta('company_nif', $nif);
            $db->setMeta('company_address', (string) ($data['address'] ?? ''));
            $db->setMeta('company_city', (string) ($data['city'] ?? ''));
            $db->setMeta('tax_regime', (string) ($data['regime'] ?? 'contabilidade_organizada'));
            $db->setMeta('currency', 'CVE');
            $db->setMeta('created_at', date('c'));
            Ledger::seedChart($db, require IDEIA_ROOT . '/database/sncrf.php');
            Ledger::seedDefaults($db);
            Ledger::createFiscalYear($db, $year);
            $db->audit('empresa.criada', ['name' => $name, 'year' => $year]);
        });
        return ['id' => $id, 'name' => $name];
    }

    /** Cópia consistente do ficheiro (VACUUM INTO). Devolve o caminho da cópia. */
    public static function backup(string $id, ?string $targetDir = null): string
    {
        $db = self::open($id);
        $dir = $targetDir ?? (string) Config::get('backup_dir', self::dataDir() . '/backups');
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UserError("Não foi possível criar a pasta de cópias: {$dir}");
        }
        $target = $dir . '/' . $id . '_' . date('Ymd_His') . '.ideia';
        $db->pdo->exec('VACUUM INTO ' . $db->pdo->quote($target));
        $check = new Db($target);
        if ($check->value('PRAGMA integrity_check') !== 'ok') {
            @unlink($target);
            throw new UserError('A cópia de segurança falhou a verificação de integridade.');
        }
        $db->audit('copia.seguranca', ['file' => basename($target)]);
        return $target;
    }

    /** Restaura (importa) um ficheiro .ideia enviado. Nunca substitui uma empresa existente. */
    public static function restore(string $tmpFile, string $originalName): array
    {
        $fh = fopen($tmpFile, 'rb');
        $header = $fh ? fread($fh, 16) : '';
        if ($fh) {
            fclose($fh);
        }
        if ($header !== "SQLite format 3\0") {
            throw new UserError('O ficheiro não é um ficheiro de empresa válido (.ideia).');
        }
        $probe = new Db($tmpFile);
        if ($probe->meta('app') !== 'IDEIA Contabilidade') {
            throw new UserError('O ficheiro não foi criado pelo IDEIA Contabilidade.');
        }
        if ($probe->value('PRAGMA integrity_check') !== 'ok') {
            throw new UserError('O ficheiro está danificado (falhou a verificação de integridade).');
        }
        $name = $probe->meta('company_name') ?? pathinfo($originalName, PATHINFO_FILENAME);
        unset($probe);
        $base = self::slug((string) $name);
        $id = $base;
        $n = 2;
        while (is_file(self::path($id))) {
            $id = $base . '-' . $n++;
        }
        if (!copy($tmpFile, self::path($id))) {
            throw new UserError('Não foi possível gravar o ficheiro na pasta de dados.');
        }
        self::open($id)->audit('empresa.restaurada', ['from' => $originalName]);
        return ['id' => $id, 'name' => $name];
    }
}
