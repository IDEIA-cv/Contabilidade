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
    'acessos: primeiro administrador, bloquear a API, entrar, bloqueio por tentativas e desligar' => function () {
        $db = demoCompany();
        $c = basename($db->path, '.ideia');
        Auth::actAs(null);
        same(false, Auth::status()['enabled']);
        same('admin', Auth::role(), 'sem acessos, quem usa é administrador');
        throws(fn() => Auth::setup('ana', 'Ana', '123'), 'pelo menos 6');
        throws(fn() => Auth::setup('com espaço', 'X', 'segredo-forte'), 'Nome de utilizador inválido');
        Auth::setup('ana', 'Ana Contabilista', 'segredo-forte');
        same(true, Auth::enabled());
        same('admin', Auth::status()['user']['role']);
        throws(fn() => Auth::setup('outro', 'Outro', 'segredo-forte'), 'já estão ativos');
        check(!str_contains((string) file_get_contents(Ideia\Companies::dataDir() . '/acesso.json'), 'segredo-forte'), 'só o hash fica guardado');
        Auth::logout();
        $r = callApi('company.info', ['c' => $c]);
        same(true, $r['auth'] ?? false, 'sem sessão a API pede a entrada');
        same(true, callApi('auth.status')['ok'], 'estado acessível sem sessão');
        throws(fn() => Auth::login('ana', 'errada'), 'errados');
        throws(fn() => Auth::login('ninguem', 'segredo-forte'), 'errados');
        Auth::login('ANA', 'segredo-forte'); // o nome não distingue maiúsculas
        same(true, callApi('company.info', ['c' => $c])['ok']);
        // bloqueio após 5 tentativas
        Auth::logout();
        for ($i = 0; $i < Auth::MAX_FAILURES; $i++) {
            try { Auth::login('ana', 'x' . $i); } catch (Throwable) {}
        }
        throws(fn() => Auth::login('ana', 'segredo-forte'), 'Demasiadas tentativas');
        // desligar (com a palavra-passe do administrador) volta a abrir a aplicação
        $data = json_decode(file_get_contents(Ideia\Companies::dataDir() . '/acesso.json'), true);
        unset($data['users'][0]['locked_until']);
        file_put_contents(Ideia\Companies::dataDir() . '/acesso.json', json_encode($data));
        Auth::login('ana', 'segredo-forte');
        throws(fn() => Auth::disable('errada'), 'errada');
        Auth::disable('segredo-forte');
        same(false, Auth::enabled());
        same(true, callApi('company.info', ['c' => $c])['ok']);
    },
    'perfis: colaborador e só leitura, gestão de utilizadores, autor no registo de auditoria e ficheiro antigo' => function () {
        $db = demoCompany();
        $c = basename($db->path, '.ideia');
        $h = ['HTTP_X_REQUESTED_WITH' => 'IDEIA'];
        Auth::actAs(null);
        Auth::setup('chefe', 'Chefe', 'segredo-forte');
        Auth::saveUser(['username' => 'rui', 'name' => 'Rui', 'role' => 'colaborador', 'password' => 'colab-123']);
        Auth::saveUser(['username' => 'lia', 'name' => 'Lia', 'role' => 'leitura', 'password' => 'leitura-1']);
        throws(fn() => Auth::saveUser(['username' => 'rui', 'role' => 'leitura', 'password' => 'outra-123']), 'Já existe');
        throws(fn() => Auth::saveUser(['username' => 'chefe', 'existing' => 1, 'role' => 'colaborador']), 'pelo menos um administrador');
        throws(fn() => Auth::deleteUser('chefe'), 'próprio utilizador');
        same(3, count(Auth::users()));

        Auth::logout();
        Auth::login('rui', 'colab-123');
        same(true, callApi('items.save', ['c' => $c], 'POST', ['name' => 'Artigo do Rui', 'price' => '100'], $h)['ok'], 'colaborador fatura e cria artigos');
        check(str_contains(callApi('accounts.save', ['c' => $c], 'POST', ['code' => '6299', 'name' => 'X'], $h)['error'], 'reservada ao contabilista'));
        check(str_contains(callApi('auth.users')['error'] ?? '', 'reservada ao contabilista'));
        throws(fn() => Auth::saveUser(['username' => 'novo', 'role' => 'admin', 'password' => 'segredo-forte']), 'Só o administrador');
        same('rui', $db->value("SELECT user FROM audit_log WHERE action LIKE 'artigo%' ORDER BY id DESC LIMIT 1"), 'autor no registo de auditoria');

        Auth::logout();
        Auth::login('lia', 'leitura-1');
        same(true, callApi('reports.trial', ['c' => $c, 'year' => 2025])['ok'], 'só leitura consulta');
        check(str_contains(callApi('items.save', ['c' => $c], 'POST', ['name' => 'Não', 'price' => '1'], $h)['error'], 'Só leitura'));
        Auth::changePassword('leitura-1', 'nova-pass-1'); // cada um muda a sua
        Auth::logout();
        Auth::login('lia', 'nova-pass-1');

        // desativar um utilizador impede a entrada
        Auth::logout();
        Auth::login('chefe', 'segredo-forte');
        Auth::saveUser(['username' => 'lia', 'existing' => 1, 'name' => 'Lia', 'role' => 'leitura', 'active' => '0']);
        Auth::logout();
        throws(fn() => Auth::login('lia', 'nova-pass-1'), 'desativado');
        Auth::login('chefe', 'segredo-forte');
        Auth::deleteUser('rui');
        same(['chefe', 'lia'], array_column(Auth::users(), 'username'));
        Auth::disable('segredo-forte');

        // ficheiro antigo (uma palavra-passe) passa a ser o utilizador "admin"; entra só com a palavra-passe
        file_put_contents(Ideia\Companies::dataDir() . '/acesso.json', json_encode(['hash' => password_hash('antiga-123', PASSWORD_DEFAULT)]));
        Auth::actAs(null);
        same(true, Auth::enabled());
        Auth::login('', 'antiga-123');
        same(['admin', 'admin'], [Auth::status()['user']['username'], Auth::role()]);
        Auth::disable('antiga-123');
    },
    'diagnóstico da instalação' => function () {
        $r = Ideia\System::check();
        check(count($r['checks']) >= 5);
        same(true, $r['checks'][1]['ok'], 'pdo_sqlite disponível');
        same(true, array_values(array_filter($r['checks'], fn($c) => $c['id'] === 'data'))[0]['ok'], 'pasta de testes com escrita');
    },
];
