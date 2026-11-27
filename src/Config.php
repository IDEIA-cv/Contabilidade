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
