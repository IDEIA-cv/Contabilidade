<?php
declare(strict_types=1);

/**
 * Arranque comum: autoload PSR-4 simples (namespace Ideia\ => src/) e configuração.
 * Sem dependências externas: basta PHP 8.0+ com pdo_sqlite.
 */

define('IDEIA_ROOT', dirname(__DIR__));
define('IDEIA_VERSION', '0.2.0');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Ideia\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

mb_internal_encoding('UTF-8');
date_default_timezone_set('Atlantic/Cape_Verde');
