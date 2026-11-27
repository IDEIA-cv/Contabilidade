<?php
declare(strict_types=1);

/**
 * Executor de testes sem dependências: php tests/run.php [filtro]
 * Cada ficheiro tests/unit/*Test.php devolve um array ['nome do teste' => callable].
 */

require __DIR__ . '/../src/bootstrap.php';

use Ideia\Config;

$tmp = sys_get_temp_dir() . '/ideia-tests-' . getmypid();
@mkdir($tmp, 0775, true);
Config::override(['data_dir' => $tmp, 'backup_dir' => $tmp . '/backups']);

function check(bool $cond, string $msg = 'condição falhou'): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}

function same(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($msg ? "$msg: " : '') . 'esperado ' . var_export($expected, true) . ', obtido ' . var_export($actual, true));
    }
}

function throws(callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) {
            throw new RuntimeException("exceção com mensagem inesperada: {$e->getMessage()}");
        }
        return;
    }
    throw new RuntimeException('era esperada uma exceção');
}

$filter = $argv[1] ?? '';
$passed = 0;
$failed = 0;
foreach (glob(__DIR__ . '/unit/*Test.php') as $file) {
    $tests = require $file;
    foreach ($tests as $name => $fn) {
        $full = basename($file, '.php') . ' :: ' . $name;
        if ($filter !== '' && !str_contains($full, $filter)) {
            continue;
        }
        try {
            $fn();
            $passed++;
            echo "  ok   $full\n";
        } catch (Throwable $e) {
            $failed++;
            echo "  FAIL $full\n       " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
        }
    }
}

array_map('unlink', glob("$tmp/backups/*") ?: []);
@rmdir("$tmp/backups");
array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);

echo "\n$passed passaram, $failed falharam\n";
exit($failed > 0 ? 1 : 0);
