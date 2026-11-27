<?php
declare(strict_types=1);

/**
 * Router do servidor embutido do PHP (php -S) usado pela versão portátil.
 * O php -S não lê os .htaccess, por isso as pastas internas são fechadas aqui.
 */

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path = '/' . ltrim(str_replace('\\', '/', $path), '/');

if (str_contains($path, '/.') || str_contains($path, '..')
    || preg_match('#^/(data|src|database|tests|tools|docs|resources|config(\.sample)?\.php)(/|$)#i', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não encontrado.';
    return true;
}

if ($path === '/' || $path === '/index.php') {
    require dirname(__DIR__, 2) . '/index.php';
    return true;
}
if ($path === '/api.php') {
    require dirname(__DIR__, 2) . '/api.php';
    return true;
}
if (preg_match('#^/assets/#', $path) && is_file(dirname(__DIR__, 2) . $path)) {
    return false; // ficheiro estático servido pelo php -S
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Não encontrado.';
return true;
