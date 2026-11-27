<?php
use Ideia\Api;
use Ideia\Auth;

/** Chama a API como o browser faria e devolve a resposta JSON descodificada. */
function callApi(string $action, array $get = [], string $method = 'GET', array $body = [], array $server = []): array
{
    $_GET = ['action' => $action] + $get;
    $_POST = [];
    $_SERVER = array_merge(['REQUEST_METHOD' => $method, 'REMOTE_ADDR' => '127.0.0.1'], $server);
    if ($method === 'POST') {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = $body;
    }
    ob_start();
    Api::handle();
    $out = (string) ob_get_clean();
    return json_decode($out, true) ?? ['raw' => $out];
}

return [
    'API: regras de pedido (POST, cabeçalho, rede local, ação desconhecida)' => function () {
        $db = demoCompany();
        $c = basename($db->path, '.ideia');
        same(true, callApi('ping')['ok']);
        same('Esta ação exige POST.', callApi('sales.save', ['c' => $c])['error']);
        check(str_contains(callApi('sales.save', ['c' => $c], 'POST', ['type' => 'FT'])['error'], 'cabeçalho'), 'POST sem cabeçalho recusado');
        check(str_contains(callApi('ping', [], 'GET', [], ['REMOTE_ADDR' => '192.168.1.20'])['error'], 'apenas a partir deste computador'), 'rede externa recusada');
        check(str_contains(callApi('nao.existe', ['c' => $c], 'POST', [], ['HTTP_X_REQUESTED_WITH' => 'IDEIA'])['error'], 'desconhecida'));
        $r = callApi('items.save', ['c' => $c], 'POST', ['name' => 'Artigo via API', 'price' => '100'], ['HTTP_X_REQUESTED_WITH' => 'IDEIA']);
        same(true, $r['ok']);
        same('P0001', $r['data']['code']);
        $r = callApi('sales.list', ['c' => $c, 'type' => 'XX']);
        same('Tipo de documento desconhecido.', $r['error']);
        check(str_contains(callApi('company.info', ['c' => '../etc'])['error'] ?? '', 'mpresa'), 'identificador de empresa inválido recusado');
    },
    'palavra-passe: ativar, bloquear a API, entrar, bloqueio por tentativas e retirar' => function () {
        $db = demoCompany();
        $c = basename($db->path, '.ideia');
        same(false, Auth::status()['enabled']);
        throws(fn() => Auth::set('', '123'), 'pelo menos 6');
        Auth::set('', 'segredo-forte');
        same(true, Auth::enabled());
        check(!str_contains((string) file_get_contents(Ideia\Companies::dataDir() . '/acesso.json'), 'segredo-forte'), 'só o hash fica guardado');
        Auth::logout();
        $r = callApi('company.info', ['c' => $c]);
        same(true, $r['auth'] ?? false, 'sem sessão a API pede a palavra-passe');
        same(true, callApi('auth.status')['ok'], 'estado acessível sem sessão');
        throws(fn() => Auth::login('errada'), 'Palavra-passe errada');
        Auth::login('segredo-forte');
        same(true, callApi('company.info', ['c' => $c])['ok']);
        throws(fn() => Auth::set('errada', ''), 'atual está errada');
        // bloqueio após 5 tentativas
        Auth::logout();
        for ($i = 0; $i < Auth::MAX_FAILURES; $i++) {
            try { Auth::login('x' . $i); } catch (Throwable) {}
        }
        throws(fn() => Auth::login('segredo-forte'), 'Demasiadas tentativas');
        // retirar (com a palavra-passe atual) volta a abrir a aplicação
        Auth::set('segredo-forte', '');
        same(false, Auth::enabled());
        same(true, callApi('company.info', ['c' => $c])['ok']);
    },
    'diagnóstico da instalação' => function () {
        $r = Ideia\System::check();
        check(count($r['checks']) >= 5);
        same(true, $r['checks'][1]['ok'], 'pdo_sqlite disponível');
        same(true, array_values(array_filter($r['checks'], fn($c) => $c['id'] === 'data'))[0]['ok'], 'pasta de testes com escrita');
    },
];
