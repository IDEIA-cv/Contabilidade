<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Palavra-passe de acesso opcional à aplicação (protege todas as empresas desta instalação).
 * Guardada como hash (password_hash) em <pasta de dados>/acesso.json; a pasta de dados está fechada ao browser (.htaccess).
 * Sessão PHP com cookie HttpOnly/SameSite=Strict, fecho automático por inatividade e bloqueio após tentativas falhadas.
 */
final class Auth
{
    public const MAX_FAILURES = 5;
    public const LOCK_SECONDS = 300;
    public const IDLE_SECONDS = 4 * 3600;

    private static function file(): string
    {
        return Companies::dataDir() . '/acesso.json';
    }

    private static function read(): array
    {
        $f = self::file();
        if (!is_file($f)) {
            return [];
        }
        return json_decode((string) file_get_contents($f), true) ?: [];
    }

    private static function write(array $data): void
    {
        if (@file_put_contents(self::file(), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            throw new UserError('Não foi possível guardar a palavra-passe: a pasta de dados não tem permissão de escrita.');
        }
        @chmod(self::file(), 0600);
    }

    public static function enabled(): bool
    {
        return !empty(self::read()['hash']);
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

    public static function loggedIn(): bool
    {
        if (!self::enabled()) {
            return true;
        }
        self::session();
        $t = $_SESSION['ideia_auth'] ?? 0;
        if (!$t || time() - $t > self::IDLE_SECONDS) {
            unset($_SESSION['ideia_auth']);
            return false;
        }
        $_SESSION['ideia_auth'] = time();
        return true;
    }

    public static function status(): array
    {
        return ['enabled' => self::enabled(), 'logged' => self::loggedIn()];
    }

    public static function login(string $password): array
    {
        $data = self::read();
        if (empty($data['hash'])) {
            return self::status();
        }
        $locked = (int) ($data['locked_until'] ?? 0);
        if ($locked > time()) {
            throw new UserError('Demasiadas tentativas falhadas. Aguarde ' . (int) ceil(($locked - time()) / 60) . ' minuto(s) e tente de novo.');
        }
        if (!password_verify($password, $data['hash'])) {
            $data['failures'] = (int) ($data['failures'] ?? 0) + 1;
            if ($data['failures'] >= self::MAX_FAILURES) {
                $data['locked_until'] = time() + self::LOCK_SECONDS;
                $data['failures'] = 0;
            }
            self::write($data);
            usleep(700000); // atrasa tentativas automáticas
            throw new UserError('Palavra-passe errada.');
        }
        $data['failures'] = 0;
        unset($data['locked_until']);
        if (password_needs_rehash($data['hash'], PASSWORD_DEFAULT)) {
            $data['hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        self::write($data);
        self::session();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['ideia_auth'] = time();
        return self::status();
    }

    public static function logout(): void
    {
        self::session();
        $_SESSION = [];
        if (PHP_SAPI !== 'cli') {
            session_destroy();
        }
    }

    /** Define, altera ou retira (palavra-passe nova vazia) a palavra-passe. Exige a atual quando já existe. */
    public static function set(string $current, string $new): array
    {
        $data = self::read();
        if (!empty($data['hash']) && !password_verify($current, $data['hash'])) {
            usleep(700000);
            throw new UserError('A palavra-passe atual está errada.');
        }
        if ($new === '') {
            self::write([]);
            return self::status();
        }
        if (mb_strlen($new) < 6) {
            throw new UserError('A palavra-passe deve ter pelo menos 6 caracteres.');
        }
        self::write(['hash' => password_hash($new, PASSWORD_DEFAULT), 'changed_at' => date('c')]);
        self::session();
        $_SESSION['ideia_auth'] = time();
        return self::status();
    }
}
