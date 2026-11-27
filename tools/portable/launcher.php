<?php
declare(strict_types=1);

/**
 * Lançador da versão portátil: arranca o servidor embutido do PHP e abre o browser.
 * Chamado pelos ficheiros "Abrir IDEIA" (Windows .bat, Mac .command, Linux .sh); também funciona sozinho:
 *   php tools/portable/launcher.php [--no-browser] [--port=8765]
 *
 * Estrutura do pacote portátil (copiar para qualquer pasta, sem instalar nada):
 *   IDEIA-Contabilidade/
 *     Abrir IDEIA ...      lançadores
 *     app/                 a aplicação (substituída nas atualizações)
 *     php/                 PHP portátil para cada sistema
 *     dados/               empresas (.ideia), cópias de segurança e utilizadores — nunca é substituída
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const IDEIA_FIRST_PORT = 8765;
const IDEIA_LAST_PORT = 8799;

$app = dirname(__DIR__, 2);
$opts = getopt('', ['no-browser', 'port:', 'data:']);
$browser = !isset($opts['no-browser']);

// Pasta de dados: ao lado de app/ quando é o pacote portátil; senão a definida em config.php.
$data = $opts['data'] ?? (getenv('IDEIA_DATA_DIR') ?: null);
if ($data === null && basename($app) === 'app') {
    $data = dirname($app) . DIRECTORY_SEPARATOR . 'dados';
}
if ($data !== null) {
    if (!is_dir($data) && !@mkdir($data, 0777, true)) {
        fail("Não foi possível criar a pasta de dados: {$data}\nCopie a pasta do programa para um sítio onde possa escrever (por exemplo Documentos).");
    }
    $data = realpath($data) ?: $data;
    putenv('IDEIA_DATA_DIR=' . $data);
}

$missing = array_values(array_filter(['pdo_sqlite', 'mbstring', 'zip', 'xmlwriter', 'dom', 'simplexml', 'zlib'], static fn($e) => !extension_loaded($e)));
if ($missing) {
    fail('O PHP desta pasta não tem as extensões necessárias: ' . implode(', ', $missing) . '.');
}

$stateFile = ($data ?? sys_get_temp_dir()) . DIRECTORY_SEPARATOR . '.servidor.json';

// Já está aberto? Então só abre o browser.
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : null;
if (is_array($state) && isset($state['port']) && isOurs((int) $state['port'], $data)) {
    $url = 'http://127.0.0.1:' . (int) $state['port'] . '/';
    say("O IDEIA Contabilidade já está aberto em {$url}");
    if ($browser) {
        openBrowser($url);
    }
    exit(0);
}

$port = isset($opts['port']) ? (int) $opts['port'] : freePort();
if (!$port) {
    fail('Não há nenhuma porta livre entre ' . IDEIA_FIRST_PORT . ' e ' . IDEIA_LAST_PORT . '. Feche outros programas e tente de novo.');
}
$url = "http://127.0.0.1:{$port}/";

$cmd = [PHP_BINARY];
if (php_ini_loaded_file()) {
    $cmd[] = '-c';
    $cmd[] = realpath(php_ini_loaded_file()) ?: php_ini_loaded_file();
}
if (PHP_OS_FAMILY === 'Windows' && ini_get('extension_dir')) {
    $cmd[] = '-d';
    $cmd[] = 'extension_dir=' . ini_get('extension_dir');
}
foreach (['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'memory_limit=512M', '-d', 'upload_max_filesize=64M', '-d', 'post_max_size=80M',
          '-S', "127.0.0.1:{$port}", '-t', $app, __DIR__ . DIRECTORY_SEPARATOR . 'router.php'] as $a) {
    $cmd[] = $a;
}
$env = getenv();
if (PHP_OS_FAMILY !== 'Windows') {
    $env['PHP_CLI_SERVER_WORKERS'] = '4'; // vários pedidos em paralelo (não suportado no Windows)
}

$log = ($data ?? sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'servidor.log';
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $app, $env);
if (!is_resource($proc)) {
    fail('Não foi possível arrancar o servidor.');
}

$ready = false;
for ($i = 0; $i < 100; $i++) {
    usleep(100000);
    $st = proc_get_status($proc);
    if (!$st['running']) {
        break;
    }
    if (isOurs($port, $data)) {
        $ready = true;
        break;
    }
}
if (!$ready) {
    proc_terminate($proc);
    fail("O servidor não arrancou. Veja o registo em {$log}");
}

@file_put_contents($stateFile, json_encode(['port' => $port, 'pid' => proc_get_status($proc)['pid'], 'started' => date('c')]));

say('');
say('  IDEIA Contabilidade está aberto em ' . $url);
if ($data !== null) {
    say('  Dados: ' . $data);
}
say('');
say('  Mantenha esta janela aberta enquanto usa o programa.');
say('  Para sair, feche esta janela (ou carregue em Ctrl+C).');
say('');
if ($browser) {
    openBrowser($url);
}

$code = proc_close($proc);
@unlink($stateFile);
exit($code);

// ---------------------------------------------------------------------------------------------

function say(string $s): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

function fail(string $s): never
{
    fwrite(STDERR, PHP_EOL . '  ERRO: ' . $s . PHP_EOL . PHP_EOL);
    exit(1);
}

function freePort(): int
{
    for ($p = IDEIA_FIRST_PORT; $p <= IDEIA_LAST_PORT; $p++) {
        $s = @stream_socket_server("tcp://127.0.0.1:{$p}", $errno, $errstr);
        if ($s) {
            fclose($s);
            return $p;
        }
    }
    return 0;
}

/** O servidor nesta porta é o IDEIA, a usar a mesma pasta de dados? */
function isOurs(int $port, ?string $data): bool
{
    $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
    $r = @file_get_contents("http://127.0.0.1:{$port}/api.php?action=ping", false, $ctx);
    $j = is_string($r) ? json_decode($r, true) : null;
    $info = $j['data'] ?? $j;
    if (!is_array($info) || ($info['app'] ?? '') !== 'IDEIA Contabilidade') {
        return false;
    }
    return $data === null || !isset($info['data_dir']) || (realpath((string) $info['data_dir']) ?: $info['data_dir']) === $data;
}

function openBrowser(string $url): void
{
    $u = escapeshellarg($url);
    if (PHP_OS_FAMILY === 'Windows') {
        pclose(popen('start "" "' . $url . '"', 'r'));
    } elseif (PHP_OS_FAMILY === 'Darwin') {
        exec("open {$u} >/dev/null 2>&1 &");
    } else {
        exec("(xdg-open {$u} || sensible-browser {$u}) >/dev/null 2>&1 &");
    }
}
