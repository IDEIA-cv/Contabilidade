<?php
declare(strict_types=1);

namespace Ideia;

/** Diagnóstico da instalação, mostrado na página inicial quando algo impede a aplicação de funcionar bem. */
final class System
{
    /** Devolve [{id, ok, label, fix}] — fix é a instrução para o utilizador quando ok = false. */
    public static function check(): array
    {
        $dir = Companies::dataDir();
        $backup = (string) Config::get('backup_dir', $dir . '/backups');
        $mac = PHP_OS_FAMILY === 'Darwin';
        $owner = $mac ? 'daemon' : 'o utilizador do Apache';
        $writable = static fn(string $d) => is_dir($d) ? is_writable($d) : is_writable(dirname($d));
        $files = glob($dir . '/*.ideia') ?: [];
        $readonlyFiles = array_values(array_filter($files, static fn($f) => !is_writable($f)));
        $checks = [
            ['id' => 'php', 'ok' => PHP_VERSION_ID >= 80000, 'label' => 'PHP ' . PHP_VERSION, 'fix' => 'É necessário PHP 8.0 ou mais recente. Atualize o XAMPP.'],
            ['id' => 'sqlite', 'ok' => extension_loaded('pdo_sqlite'), 'label' => 'Extensão PDO SQLite', 'fix' => 'Ative a extensão pdo_sqlite no php.ini (linha extension=pdo_sqlite) e reinicie o Apache.'],
            ['id' => 'data', 'ok' => $writable($dir) && !$readonlyFiles, 'label' => 'Pasta de dados com permissão de escrita',
                'fix' => $mac
                    ? "Abra o Terminal e corra: sudo chown -R {$owner} \"{$dir}\" && sudo chmod -R u+rwX \"{$dir}\""
                    : "Nas propriedades da pasta {$dir}, dê permissão de escrita a {$owner}."],
            ['id' => 'backup', 'ok' => $writable($backup), 'label' => 'Pasta de cópias de segurança com permissão de escrita',
                'fix' => $mac ? "sudo chown -R {$owner} \"{$backup}\"" : "Dê permissão de escrita à pasta {$backup}."],
            ['id' => 'fileinfo', 'ok' => extension_loaded('fileinfo'), 'label' => 'Extensão fileinfo (anexos)', 'fix' => 'Ative a extensão fileinfo no php.ini e reinicie o Apache.'],
            ['id' => 'upload', 'ok' => self::bytes((string) ini_get('upload_max_filesize')) >= 8 * 1024 * 1024, 'label' => 'Tamanho máximo de envio: ' . ini_get('upload_max_filesize'),
                'fix' => 'Aumente upload_max_filesize e post_max_size para 16M no php.ini, para poder importar SAF-T e anexos grandes.', 'warn' => true],
        ];
        return ['ok' => !array_filter($checks, static fn($c) => !$c['ok'] && empty($c['warn'])), 'checks' => $checks, 'data_dir' => $dir];
    }

    private static function bytes(string $v): int
    {
        $n = (int) $v;
        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1024 ** 3,
            'm' => $n * 1024 ** 2,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
