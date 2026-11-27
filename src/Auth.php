<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Acessos à aplicação (protegem todas as empresas desta instalação).
 *
 * Sem utilizadores configurados, a aplicação abre diretamente (uso individual). Com utilizadores, cada pessoa entra
 * com o seu nome de utilizador e palavra-passe e tem um perfil:
 *   - admin       Contabilista / administrador: tudo, incluindo utilizadores, dados da empresa, plano de contas, exercícios.
 *   - colaborador Lança, fatura, compra, reconcilia; não gere utilizadores, empresa, plano de contas nem fecha exercícios.
 *   - leitura     Só consulta (relatórios, listas, impressões e exportações).
 *
 * Guardado em <pasta de dados>/acesso.json (só hashes de password_hash); a pasta de dados está fechada ao browser (.htaccess).
 * Sessão PHP com cookie HttpOnly/SameSite=Strict, fecho por inatividade e bloqueio por utilizador após tentativas falhadas.
 * O ficheiro antigo (uma só palavra-passe, chave "hash") é convertido no utilizador "admin".
 */
final class Auth
{
    public const MAX_FAILURES = 5;
    public const LOCK_SECONDS = 300;
    public const IDLE_SECONDS = 4 * 3600;
    public const ROLES = [
        'admin' => ['name' => 'Contabilista (administrador)', 'help' => 'Tudo, incluindo utilizadores, dados da empresa, plano de contas, exercícios e importações.'],
        'colaborador' => ['name' => 'Colaborador', 'help' => 'Lançamentos, vendas, compras, bancos e e-Fatura. Não gere utilizadores, a empresa, o plano de contas nem fecha exercícios.'],
        'leitura' => ['name' => 'Só leitura', 'help' => 'Consulta relatórios, listas e documentos, imprime e exporta. Não altera nada.'],
    ];

    /** Utilizador da sessão atual (para o registo de auditoria e as permissões); nos testes pode ser definido à mão. */
    private static ?array $current = null;

    private static function file(): string
    {
        return Companies::dataDir() . '/acesso.json';
    }

    private static function read(): array
    {
        $f = self::file();
        $data = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        if (!empty($data['hash']) && empty($data['users'])) { // formato antigo: uma só palavra-passe
            $data = ['users' => [['username' => 'admin', 'name' => 'Administrador', 'role' => 'admin', 'hash' => $data['hash'], 'active' => true, 'created_at' => $data['changed_at'] ?? date('c')]]];
        }
        $data['users'] ??= [];
        return $data;
    }

    private static function write(array $data): void
    {
        if (empty($data['users'])) {
            if (is_file(self::file()) && !@unlink(self::file())) {
                @file_put_contents(self::file(), '{}', LOCK_EX);
            }
            return;
        }
        if (@file_put_contents(self::file(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new UserError('Não foi possível guardar os acessos: a pasta de dados não tem permissão de escrita.');
        }
        @chmod(self::file(), 0600);
    }

    public static function enabled(): bool
    {
        return (bool) array_filter(self::read()['users'], static fn($u) => !empty($u['active']));
    }

    private static function session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
            return;
        }
        session_name('IDEIASESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
        session_start();
    }

    private static function find(array $data, string $username): ?int
    {
        $username = mb_strtolower(trim($username));
        foreach ($data['users'] as $i => $u) {
            if (mb_strtolower($u['username']) === $username) {
                return $i;
            }
        }
        return null;
    }

    private static function public(array $u): array
    {
        return ['username' => $u['username'], 'name' => $u['name'] ?? $u['username'], 'role' => $u['role'], 'role_name' => self::ROLES[$u['role']]['name'] ?? $u['role'],
            'active' => !empty($u['active']), 'last_login' => $u['last_login'] ?? null];
    }

    /** Utilizador com sessão ativa (null sem acessos configurados ou sem sessão). */
    public static function user(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        if (!self::enabled()) {
            return null;
        }
        self::session();
        $t = $_SESSION['ideia_auth'] ?? 0;
        $name = (string) ($_SESSION['ideia_user'] ?? '');
        if (!$t || $name === '' || time() - $t > self::IDLE_SECONDS) {
            unset($_SESSION['ideia_auth'], $_SESSION['ideia_user']);
            return null;
        }
        $data = self::read();
        $i = self::find($data, $name);
        if ($i === null || empty($data['users'][$i]['active'])) {
            unset($_SESSION['ideia_auth'], $_SESSION['ideia_user']);
            return null;
        }
        $_SESSION['ideia_auth'] = time();
        return self::$current = self::public($data['users'][$i]);
    }

    public static function loggedIn(): bool
    {
        return !self::enabled() || self::user() !== null;
    }

    /** Perfil efetivo: sem acessos configurados, quem usa a aplicação é o administrador. */
    public static function role(): string
    {
        return self::enabled() ? (self::user()['role'] ?? 'leitura') : 'admin';
    }

    /** Nome para o registo de auditoria. */
    public static function author(): ?string
    {
        $u = self::$current ?? (self::enabled() ? self::user() : null);
        return $u ? $u['username'] : null;
    }

    /** Só para testes e scripts: age como este utilizador (null = sem utilizador). */
    public static function actAs(?array $user): void
    {
        self::$current = $user;
    }

    public static function status(): array
    {
        $user = self::enabled() ? self::user() : null;
        return ['enabled' => self::enabled(), 'logged' => self::loggedIn(), 'user' => $user, 'role' => self::role(),
            'roles' => array_map(static fn($r) => $r['name'], self::ROLES)];
    }

    public static function login(string $username, string $password): array
    {
        $data = self::read();
        if (!$data['users']) {
            return self::status();
        }
        if (trim($username) === '' && count($data['users']) === 1) {
            $username = $data['users'][0]['username']; // instalação antiga com uma só palavra-passe
        }
        $i = self::find($data, $username);
        $fail = static function () use (&$data, $i): never {
            if ($i !== null) {
                $data['users'][$i]['failures'] = (int) ($data['users'][$i]['failures'] ?? 0) + 1;
                if ($data['users'][$i]['failures'] >= self::MAX_FAILURES) {
                    $data['users'][$i]['locked_until'] = time() + self::LOCK_SECONDS;
                    $data['users'][$i]['failures'] = 0;
                }
                self::write($data);
            }
            usleep(700000); // atrasa tentativas automáticas
            throw new UserError('Utilizador ou palavra-passe errados.');
        };
        if ($i === null) {
            $fail();
        }
        $u = $data['users'][$i];
        $locked = (int) ($u['locked_until'] ?? 0);
        if ($locked > time()) {
            throw new UserError('Demasiadas tentativas falhadas. Aguarde ' . (int) ceil(($locked - time()) / 60) . ' minuto(s) e tente de novo.');
        }
        if (empty($u['active'])) {
            usleep(700000);
            throw new UserError('Este utilizador está desativado. Fale com o administrador.');
        }
        if (!password_verify($password, $u['hash'])) {
            $fail();
        }
        $data['users'][$i]['failures'] = 0;
        unset($data['users'][$i]['locked_until']);
        $data['users'][$i]['last_login'] = date('Y-m-d H:i');
        if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) {
            $data['users'][$i]['hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        self::write($data);
        self::start($data['users'][$i]);
        return self::status();
    }

    private static function start(array $u): void
    {
        self::session();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['ideia_auth'] = time();
        $_SESSION['ideia_user'] = $u['username'];
        self::$current = self::public($u);
    }

    public static function logout(): void
    {
        self::session();
        $_SESSION = [];
        self::$current = null;
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    // ------------------------------------------------------------------ gestão de utilizadores

    public static function users(): array
    {
        self::requireAdmin();
        return array_map([self::class, 'public'], self::read()['users']);
    }

    /** Primeiro utilizador (administrador): liga os acessos. Só possível quando ainda não há utilizadores. */
    public static function setup(string $username, string $name, string $password): array
    {
        $data = self::read();
        if ($data['users']) {
            throw new UserError('Os acessos já estão ativos.');
        }
        $u = self::validUser($data, $username, $name, 'admin', $password, true);
        $data['users'][] = $u;
        self::write($data);
        self::start($u);
        return self::status();
    }

    /** Cria ou altera um utilizador (só administrador). Palavra-passe vazia ao alterar = mantém a atual. */
    public static function saveUser(array $in): array
    {
        self::requireAdmin();
        $data = self::read();
        $username = (string) ($in['username'] ?? '');
        $i = self::find($data, $username);
        $isNew = empty($in['existing']);
        if ($isNew && $i !== null) {
            throw new UserError('Já existe um utilizador com esse nome.');
        }
        if (!$isNew && $i === null) {
            throw new UserError('Utilizador não encontrado.');
        }
        $role = (string) ($in['role'] ?? 'colaborador');
        $active = !isset($in['active']) || !in_array($in['active'], [false, 0, '0', 'false', ''], true);
        $u = self::validUser($data, $username, (string) ($in['name'] ?? ''), $role, (string) ($in['password'] ?? ''), $isNew, $isNew ? null : $data['users'][$i]);
        $u['active'] = $active;
        if ($isNew) {
            $data['users'][] = $u;
        } else {
            $data['users'][$i] = $u;
        }
        self::checkAdmins($data);
        self::write($data);
        if (!$isNew && self::$current && mb_strtolower(self::$current['username']) === mb_strtolower($u['username'])) {
            self::$current = self::public($u);
        }
        return self::public($u);
    }

    public static function deleteUser(string $username): void
    {
        self::requireAdmin();
        $data = self::read();
        $i = self::find($data, $username);
        if ($i === null) {
            throw new UserError('Utilizador não encontrado.');
        }
        if (self::$current && mb_strtolower(self::$current['username']) === mb_strtolower($data['users'][$i]['username'])) {
            throw new UserError('Não pode apagar o seu próprio utilizador.');
        }
        array_splice($data['users'], $i, 1);
        self::checkAdmins($data);
        self::write($data);
    }

    /** Desliga os acessos (apaga todos os utilizadores): a aplicação volta a abrir sem palavra-passe. */
    public static function disable(string $password): array
    {
        self::requireAdmin();
        $data = self::read();
        $me = self::$current ? self::find($data, self::$current['username']) : null;
        if ($me === null || !password_verify($password, $data['users'][$me]['hash'])) {
            usleep(700000);
            throw new UserError('A sua palavra-passe está errada.');
        }
        self::write(['users' => []]);
        self::$current = null;
        return self::status();
    }

    /** O próprio utilizador muda a sua palavra-passe. */
    public static function changePassword(string $current, string $new): void
    {
        $data = self::read();
        $me = self::$current ?? self::user();
        $i = $me ? self::find($data, $me['username']) : null;
        if ($i === null) {
            throw new UserError('Inicie sessão primeiro.');
        }
        if (!password_verify($current, $data['users'][$i]['hash'])) {
            usleep(700000);
            throw new UserError('A palavra-passe atual está errada.');
        }
        self::checkPassword($new);
        $data['users'][$i]['hash'] = password_hash($new, PASSWORD_DEFAULT);
        $data['users'][$i]['changed_at'] = date('c');
        self::write($data);
    }

    private static function validUser(array $data, string $username, string $name, string $role, string $password, bool $isNew, ?array $old = null): array
    {
        $username = trim($username);
        if (!preg_match('/^[\p{L}\p{N}._-]{2,40}$/u', $username)) {
            throw new UserError('Nome de utilizador inválido: 2 a 40 letras, algarismos, ponto, hífen ou sublinhado, sem espaços.');
        }
        if (!isset(self::ROLES[$role])) {
            throw new UserError('Perfil inválido.');
        }
        if ($isNew || $password !== '') {
            self::checkPassword($password);
        }
        return [
            'username' => $old['username'] ?? $username,
            'name' => mb_substr(trim($name) ?: $username, 0, 80),
            'role' => $role,
            'hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $old['hash'],
            'active' => $old['active'] ?? true,
            'created_at' => $old['created_at'] ?? date('c'),
            'last_login' => $old['last_login'] ?? null,
        ];
    }

    private static function checkPassword(string $p): void
    {
        if (mb_strlen($p) < 6) {
            throw new UserError('A palavra-passe deve ter pelo menos 6 caracteres.');
        }
    }

    private static function checkAdmins(array $data): void
    {
        if ($data['users'] && !array_filter($data['users'], static fn($u) => $u['role'] === 'admin' && !empty($u['active']))) {
            throw new UserError('Tem de haver pelo menos um administrador ativo.');
        }
    }

    private static function requireAdmin(): void
    {
        if (self::enabled() && self::role() !== 'admin') {
            throw new UserError('Só o administrador pode gerir os utilizadores.');
        }
    }
}
