<?php
declare(strict_types=1);

namespace Ideia;

final class Config
{
    private static ?array $values = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$values === null) {
            $file = IDEIA_ROOT . '/config.php';
            if (!is_file($file)) {
                $file = IDEIA_ROOT . '/config.sample.php';
            }
            self::$values = require $file;
            // Versão portátil: o lançador indica a pasta de dados (fora da pasta da aplicação, para sobreviver a atualizações).
            $env = getenv('IDEIA_DATA_DIR');
            if (is_string($env) && $env !== '') {
                self::$values['data_dir'] = rtrim($env, '/\\');
                self::$values['backup_dir'] = self::$values['data_dir'] . '/backups';
            }
        }
        return self::$values[$key] ?? $default;
    }

    /** Usado pelos testes para apontar para pastas temporárias. */
    public static function override(array $values): void
    {
        self::get('data_dir');
        self::$values = array_merge(self::$values ?? [], $values);
    }
}
