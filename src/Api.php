<?php
declare(strict_types=1);

namespace Ideia;

use Ideia\Import\Csv;
use Ideia\Saft\Exporter;
use Ideia\Saft\Importer;

/**
 * Encaminhamento das ações da API. Cada ação recebe ($db|null, $in) e devolve dados serializáveis em JSON.
 */
final class Api
{
    /** Ações que não precisam de empresa aberta. */
    private const GLOBAL = ['ping', 'auth.status', 'auth.login', 'auth.logout', 'auth.setup', 'auth.users', 'auth.user_save', 'auth.user_delete', 'auth.disable', 'auth.password', 'system.check', 'companies.list', 'companies.create', 'companies.restore', 'portfolio'];

    /** Ações disponíveis sem sessão iniciada (quando há palavra-passe). */
    private const PUBLIC = ['ping', 'auth.status', 'auth.login'];

    /** Ações só para o perfil administrador (contabilista), quando há utilizadores. */
    private const ADMIN = ['auth.users', 'auth.user_save', 'auth.user_delete', 'auth.disable', 'company.update', 'company.logo', 'accounts.save',
        'years.create', 'years.lock', 'years.close', 'years.open_next', 'vat.post', 'efatura.save', 'saft.import', 'csv.import', 'companies.create', 'companies.restore',
        'assets.post', 'assets.unpost', 'assets.dispose'];

    /** Ações permitidas ao perfil só leitura além das de leitura (GET). */
    private const READONLY_EXTRA = ['auth.logout', 'auth.password', 'auth.login'];

    /** Ações de leitura (GET). As restantes exigem POST. */
    private const READ = [
        'ping', 'auth.status', 'auth.users', 'system.check', 'companies.list', 'portfolio', 'company.info', 'accounts.list', 'thirdparties.list', 'entries.list', 'entries.get',
        'reports.trial', 'reports.ledger', 'reports.journal', 'reports.balance', 'reports.income', 'vat.settlement',
        'stats.dashboard', 'stats.ratios', 'stats.costs', 'stats.top', 'stats.open', 'stats.forecast', 'stats.budget', 'stats.custom',
        'chain.verify', 'audit.list', 'imports.list', 'backup.download', 'saft.export', 'saft.validate', 'import.template', 'attachments.list', 'attachments.download',
        'bank.accounts', 'bank.template', 'bank.statements', 'bank.pending', 'bank.matches', 'bank.report',
        'items.list', 'items.families', 'sales.debtors', 'sales.customer', 'sales.statement', 'party.statement', 'party.statement_print', 'sales.meta', 'sales.list', 'sales.export_zip', 'sales.get', 'sales.print', 'sales.series', 'sales.summary', 'sales.verify',
        'purchases.meta', 'purchases.list', 'purchases.export_zip', 'purchases.get', 'purchases.creditors', 'purchases.schedule', 'purchases.supplier', 'purchases.summary', 'purchases.last_account',
        'assets.meta', 'assets.list', 'assets.get', 'assets.preview',
        'efatura.overview', 'efatura.documents', 'efatura.xml', 'efatura.check', 'efatura.doc', 'efatura.codes', 'efatura.conference',
    ];

    public static function handle(): void
    {
        try {
            self::guard();
            $action = (string) ($_GET['action'] ?? '');
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            if ($action === '') {
                throw new UserError('Ação em falta.');
            }
            if (!in_array($action, self::READ, true) && $method !== 'POST') {
                throw new UserError('Esta ação exige POST.');
            }
            $in = $_GET;
            if ($method === 'POST') {
                $ct = $_SERVER['CONTENT_TYPE'] ?? '';
                if (str_starts_with($ct, 'application/json')) {
                    $body = json_decode((string) file_get_contents('php://input'), true);
                    if (!is_array($body)) {
                        throw new UserError('Pedido JSON inválido.');
                    }
                    $in = array_merge($in, $body);
                } else {
                    $in = array_merge($in, $_POST);
                    foreach (['options', 'map'] as $k) {
                        if (isset($in[$k]) && is_string($in[$k])) {
                            $in[$k] = json_decode($in[$k], true) ?: [];
                        }
                    }
                }
            }
            if (!in_array($action, self::PUBLIC, true) && !Auth::loggedIn()) {
                self::json(['ok' => false, 'auth' => true, 'error' => 'A sessão terminou. Introduza a palavra-passe.'], 401);
                return;
            }
            self::authorize($action);
            $db = null;
            if (!in_array($action, self::GLOBAL, true)) {
                $db = Companies::open((string) ($in['c'] ?? ''));
            }
            $result = self::dispatch($action, $db, $in);
            if ($result instanceof Download) {
                $result->send();
                return;
            }
            self::json(['ok' => true, 'data' => $result]);
        } catch (UserError $e) {
            self::json(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            error_log('[IDEIA] ' . $e);
            $msg = $e->getMessage();
            if (str_contains($msg, 'readonly database') || str_contains($msg, 'unable to open database')) {
                // causa mais comum no XAMPP: a pasta de dados não pertence ao utilizador do Apache
                $dir = Companies::dataDir();
                self::json(['ok' => false, 'error' => "Sem permissão para escrever na pasta de dados ({$dir}). "
                    . "No Mac, abra o Terminal e corra: sudo chown -R daemon \"{$dir}\" — no Windows, dê permissão de escrita à pasta. Depois recarregue a página."], 500);
                return;
            }
            self::json(['ok' => false, 'error' => 'Erro interno: ' . $msg], 500);
        }
    }

    private static function guard(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (!Config::get('allow_remote', false) && !in_array($ip, ['127.0.0.1', '::1'], true)) {
            throw new UserError('Acesso permitido apenas a partir deste computador. Altere allow_remote em config.php para usar na rede local.');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'IDEIA') {
            throw new UserError('Pedido recusado (cabeçalho em falta).');
        }
    }

    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Permissões por perfil (só quando há utilizadores configurados). */
    private static function authorize(string $action): void
    {
        if (in_array($action, self::PUBLIC, true) || !Auth::enabled()) {
            return;
        }
        $role = Auth::role();
        if ($role === 'leitura' && !in_array($action, self::READ, true) && !in_array($action, self::READONLY_EXTRA, true)) {
            throw new UserError('O seu perfil é "Só leitura": pode consultar, imprimir e exportar, mas não alterar dados.');
        }
        if ($role !== 'admin' && in_array($action, self::ADMIN, true)) {
            throw new UserError('Esta operação é reservada ao contabilista (administrador). O seu perfil é "' . (Auth::ROLES[$role]['name'] ?? $role) . '".');
        }
    }

    private static function int(array $in, string $k, int $default): int
    {
        return isset($in[$k]) && $in[$k] !== '' ? (int) $in[$k] : $default;
    }

    private static function upload(string $field = 'file'): array
    {
        $f = $_FILES[$field] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
            throw new UserError($code === UPLOAD_ERR_INI_SIZE ? 'O ficheiro excede o tamanho máximo permitido pelo PHP (upload_max_filesize).' : 'Escolha um ficheiro.');
        }
        return [$f['tmp_name'], (string) $f['name'], (string) file_get_contents($f['tmp_name'])];
    }

    private static function dispatch(string $action, ?Db $db, array $in): mixed
    {
        $year = fn() => self::int($in, 'year', (int) ($db?->value('SELECT MAX(year) FROM fiscal_years') ?? date('Y')));
        $from = self::int($in, 'from', 1);
        $to = self::int($in, 'to', 12);

        switch ($action) {
            case 'ping':
                return ['app' => 'IDEIA Contabilidade', 'version' => IDEIA_VERSION, 'php' => PHP_VERSION, 'sqlite' => (new \PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn(), 'data_dir' => Companies::dataDir()];

            // ---------------------------------------------------------------- acesso e diagnóstico
            case 'auth.status':
                return Auth::status();
            case 'auth.login':
                return Auth::login((string) ($in['username'] ?? ''), (string) ($in['password'] ?? ''));
            case 'auth.logout':
                Auth::logout();
                return true;
            case 'auth.setup':
                return Auth::setup((string) ($in['username'] ?? ''), (string) ($in['name'] ?? ''), (string) ($in['password'] ?? ''));
            case 'auth.users':
                return ['users' => Auth::users(), 'roles' => Auth::ROLES];
            case 'auth.user_save':
                return Auth::saveUser($in);
            case 'auth.user_delete':
                Auth::deleteUser((string) ($in['username'] ?? ''));
                return true;
            case 'auth.disable':
                return Auth::disable((string) ($in['password'] ?? ''));
            case 'auth.password':
                Auth::changePassword((string) ($in['current'] ?? ''), (string) ($in['password'] ?? ''));
                return true;
            case 'system.check':
                return System::check();

            // ---------------------------------------------------------------- empresas
            case 'companies.list':
                return Companies::list();
            case 'companies.create':
                return Companies::create($in);
            case 'companies.restore':
                [$tmp, $name] = self::upload();
                return Companies::restore($tmp, $name);
            case 'portfolio':
                return Stats::portfolio();
            case 'company.info':
                Companies::autoBackup(basename($db->path, '.ideia'));
                return [
                    'id' => basename($db->path, '.ideia'),
                    'name' => $db->meta('company_name'),
                    'nif' => $db->meta('company_nif'),
                    'address' => $db->meta('company_address'),
                    'city' => $db->meta('company_city'),
                    'regime' => $db->meta('tax_regime'),
                    'path' => $db->path,
                    'years' => Ledger::fiscalYears($db),
                    'journals' => Ledger::journals($db),
                    'vat_codes' => Ledger::vatCodes($db),
                    'drafts' => (int) $db->value("SELECT COUNT(*) FROM entries WHERE status = 'rascunho'"),
                    'phone' => $db->meta('company_phone'),
                    'email' => $db->meta('company_email'),
                    'bank' => $db->meta('company_bank'),
                    'sales_footer' => $db->meta('sales_footer'),
                    'logo' => $db->meta('company_logo'),
                    'sales_drafts' => (int) $db->value("SELECT COUNT(*) FROM sales_docs WHERE status = 'rascunho'"),
                ];
            case 'company.update':
                $nif = preg_replace('/\D/', '', (string) ($in['nif'] ?? '')) ?? '';
                if ($nif !== '' && strlen($nif) !== 9) {
                    throw new UserError('O NIF deve ter 9 dígitos.');
                }
                if (trim((string) ($in['name'] ?? '')) === '') {
                    throw new UserError('Indique o nome da empresa.');
                }
                foreach (['name' => 'company_name', 'address' => 'company_address', 'city' => 'company_city', 'regime' => 'tax_regime',
                    'phone' => 'company_phone', 'email' => 'company_email', 'bank' => 'company_bank', 'sales_footer' => 'sales_footer'] as $k => $meta) {
                    if (isset($in[$k])) {
                        $db->setMeta($meta, trim((string) $in[$k]));
                    }
                }
                $db->setMeta('company_nif', $nif);
                $db->audit('empresa.alterada', []);
                return true;

            case 'company.logo':
                if (!empty($in['remove']) && $in['remove'] !== 'false') {
                    $db->setMeta('company_logo', null);
                    return true;
                }
                [, $name, $content] = self::upload();
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content) ?: '';
                if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
                    throw new UserError('O logótipo tem de ser uma imagem PNG, JPG ou WEBP.');
                }
                if (strlen($content) > 300 * 1024) {
                    throw new UserError('O logótipo tem de ter menos de 300 KB.');
                }
                $db->setMeta('company_logo', 'data:' . $mime . ';base64,' . base64_encode($content));
                $db->audit('empresa.logotipo', ['file' => $name]);
                return true;

            // ---------------------------------------------------------------- vendas
            case 'sales.meta':
                return ['types' => Sales::TYPES, 'convert' => Sales::CONVERT, 'payment_methods' => Sales::PAYMENT_METHODS,
                    'exemptions' => Sales::exemptions(), 'units' => Efatura::codes()['units_common'], 'issue_reasons' => Efatura::ISSUE_REASONS,
                    'tv_id_limit' => Sales::TV_ID_LIMIT, 'efatura' => Efatura::enabled($db)];
            case 'items.list':
                return Sales::items($db, $in);
            case 'items.families':
                return Sales::families($db);
            case 'items.family_save':
                return Sales::saveFamily($db, $in);
            case 'items.family_delete':
                Sales::deleteFamily($db, self::int($in, 'id', 0));
                return true;
            case 'items.save':
                return Sales::saveItem($db, $in);
            case 'items.delete':
                return ['result' => Sales::deleteItem($db, self::int($in, 'id', 0))];
            case 'assets.meta':
                return Assets::meta($db);
            case 'assets.list':
                return Assets::list($db, ['year' => $year()] + $in);
            case 'assets.get':
                return Assets::get($db, self::int($in, 'id', 0));
            case 'assets.save':
                return Assets::save($db, $in);
            case 'assets.delete':
                Assets::delete($db, self::int($in, 'id', 0));
                return true;
            case 'assets.preview':
                return Assets::preview($db, $year());
            case 'assets.post':
                return Assets::post($db, $year());
            case 'assets.unpost':
                return Assets::unpost($db, $year());
            case 'assets.dispose':
                return Assets::dispose($db, self::int($in, 'id', 0), $in);
            case 'sales.list':
                return Sales::list($db, $in);
            case 'sales.export_zip':
                [$zip, $name] = DocExport::sales($db, $in);
                return new Download($zip, $name, 'application/zip');
            case 'purchases.export_zip':
                [$zip, $name] = DocExport::purchases($db, $in);
                return new Download($zip, $name, 'application/zip');
            case 'sales.get':
                return Sales::get($db, self::int($in, 'id', 0));
            case 'sales.save':
                return Sales::save($db, $in);
            case 'sales.issue':
                return Sales::issue($db, self::int($in, 'id', 0));
            case 'sales.cancel':
                return Sales::cancel($db, self::int($in, 'id', 0), (string) ($in['reason'] ?? ''));
            case 'sales.delete':
                Sales::deleteDraft($db, self::int($in, 'id', 0));
                return true;
            case 'sales.derive':
                return Sales::derive($db, self::int($in, 'id', 0), (string) ($in['type'] ?? ''), (string) ($in['mode'] ?? 'convert'), !empty($in['link']));
            case 'sales.link':
                return Sales::link($db, self::int($in, 'id', 0), self::int($in, 'other', 0));
            case 'sales.unlink':
                return Sales::unlink($db, self::int($in, 'id', 0), self::int($in, 'other', 0));
            case 'sales.pay':
                return Sales::pay($db, self::int($in, 'id', 0), $in);
            case 'sales.series':
                return Sales::series($db);
            case 'sales.summary':
                return Sales::summary($db, $year());
            case 'sales.verify':
                return Sales::verifyChain($db);
            case 'sales.debtors':
                return Sales::debtors($db, $in);
            case 'sales.customer':
                return Sales::customer($db, self::int($in, 'id', 0), $year());
            case 'sales.statement':
                return Sales::statement($db, self::int($in, 'id', 0), (string) ($in['from'] ?? date('Y-01-01')), (string) ($in['to'] ?? date('Y-m-d')));
            case 'party.statement':
            case 'party.statement_print':
                $y = $year();
                $args = [$db, self::int($in, 'id', 0), $y, (string) (($in['from'] ?? '') ?: "$y-01-01"), (string) (($in['to'] ?? '') ?: "$y-12-31"), (string) ($in['account'] ?? '')];
                return $action === 'party.statement' ? Reports::partyStatement(...$args) : new Download(SalesPrint::statement(...$args), 'extrato.html', 'text/html; charset=utf-8', true);
            case 'sales.print':
                $html = SalesPrint::html($db, self::int($in, 'id', 0));
                return new Download($html, 'documento.html', 'text/html; charset=utf-8', true);

            // ---------------------------------------------------------------- e-Fatura
            case 'efatura.overview':
                return [
                    'settings' => Efatura::settings($db), 'readiness' => Efatura::readiness($db), 'enabled' => Efatura::enabled($db),
                    'counts' => Efatura::documents($db, ['status' => '__none__'])['counts'],
                    'repositories' => Efatura::REPOSITORIES, 'contingency_reasons' => Efatura::CONTINGENCY_REASONS,
                    'docs' => array_map(static fn($d) => ['title' => $d[1], 'size' => (int) @filesize(IDEIA_ROOT . '/resources/efatura/docs/' . $d[0]), 'file' => $d[0]], Efatura::DOCS),
                    'links' => Efatura::LINKS, 'middleware' => Efatura::MIDDLEWARE_DOWNLOADS,
                    'php' => ['curl' => function_exists('curl_init'), 'zip' => class_exists(\ZipArchive::class), 'dom' => class_exists(\DOMDocument::class)],
                ];
            case 'efatura.codes':
                return ['exemptions' => Efatura::codes()['exemptions'], 'units' => Efatura::codes()['units'], 'units_common' => Efatura::codes()['units_common'],
                    'issue_reasons' => Efatura::ISSUE_REASONS];
            case 'efatura.save':
                return Efatura::saveSettings($db, $in);
            case 'efatura.test':
                return Efatura::test($db);
            case 'efatura.documents':
                return Efatura::documents($db, $in);
            case 'efatura.check':
                $id = self::int($in, 'id', 0);
                try {
                    $errors = Efatura::validate(Efatura::buildXml($db, $id));
                } catch (UserError $e) {
                    return ['ok' => false, 'errors' => array_values(array_filter(array_map(static fn($l) => trim($l, " •\t"), explode("\n", $e->getMessage()))))];
                }
                return ['ok' => !$errors, 'errors' => $errors];
            case 'efatura.xml':
                $id = self::int($in, 'id', 0);
                $xml = Efatura::buildXml($db, $id);
                $iud = (string) $db->value('SELECT iud FROM sales_docs WHERE id = ?', [$id]);
                return new Download($xml, $iud . '.xml', 'application/xml');
            case 'efatura.send':
                $ids = array_map('intval', (array) ($in['ids'] ?? []));
                if (!$ids && !empty($in['pending'])) {
                    $ids = array_column(array_filter(Efatura::documents($db, ['status' => 'pendente'])['rows'], static fn($r) => $r['status'] === 'emitido'), 'id');
                }
                if (!$ids) {
                    throw new UserError('Não há documentos para enviar.');
                }
                return Efatura::send($db, $ids);
            case 'efatura.exclude':
                return ['changed' => Efatura::exclude($db, array_map('intval', (array) ($in['ids'] ?? [])), !isset($in['exclude']) || !in_array($in['exclude'], [false, 0, '0', 'false'], true))];
            case 'efatura.received_preview':
                [, $name, $content] = self::upload();
                return EfaturaReceived::preview($db, $name, $content);
            case 'efatura.received_import':
                [, $name, $content] = self::upload();
                return EfaturaReceived::import($db, $name, $content, $in);
            case 'efatura.conference':
                return Efatura::conference($db, $year());
            case 'efatura.doc':
                $key = (string) ($in['id'] ?? '');
                if (!isset(Efatura::DOCS[$key])) {
                    throw new UserError('Documento não encontrado.');
                }
                [$file, , $mime] = Efatura::DOCS[$key];
                return new Download((string) file_get_contents(IDEIA_ROOT . '/resources/efatura/docs/' . $file), $file, $mime, $mime === 'application/pdf');

            // ---------------------------------------------------------------- compras
            case 'purchases.meta':
                return ['types' => Purchases::TYPES, 'payment_methods' => Sales::PAYMENT_METHODS,
                    'vat_codes' => $db->all("SELECT code, name, rate_bp, kind FROM vat_codes WHERE kind IN ('dedutivel','isento') ORDER BY rate_bp DESC, code"),
                    'accounts' => $db->all("SELECT a.code, a.name FROM accounts a WHERE (a.code LIKE '3%' OR a.code LIKE '4%' OR a.code LIKE '6%') AND NOT EXISTS (SELECT 1 FROM accounts c WHERE c.parent = a.code) ORDER BY a.code")];
            case 'purchases.list':
                return Purchases::list($db, $in);
            case 'purchases.get':
                return Purchases::get($db, self::int($in, 'id', 0));
            case 'purchases.save':
                return Purchases::save($db, $in);
            case 'purchases.register':
                return Purchases::register($db, self::int($in, 'id', 0));
            case 'purchases.cancel':
                return Purchases::cancel($db, self::int($in, 'id', 0), (string) ($in['reason'] ?? ''));
            case 'purchases.delete':
                Purchases::deleteDraft($db, self::int($in, 'id', 0));
                return true;
            case 'purchases.credit':
                return Purchases::creditFrom($db, self::int($in, 'id', 0));
            case 'purchases.pay':
                return Purchases::pay($db, $in);
            case 'purchases.creditors':
                return Purchases::creditors($db, $in);
            case 'purchases.schedule':
                return Purchases::schedule($db, (string) ($in['as_of'] ?? '') ?: null);
            case 'purchases.supplier':
                return Purchases::supplier($db, self::int($in, 'id', 0), $year());
            case 'purchases.summary':
                return Purchases::summary($db, $year());
            case 'purchases.last_account':
                return ['account' => Purchases::lastAccount($db, self::int($in, 'id', 0))];

            // ---------------------------------------------------------------- plano, terceiros, exercícios
            case 'accounts.list':
                return Ledger::accounts($db);
            case 'accounts.save':
                return Ledger::saveAccount($db, $in);
            case 'thirdparties.list':
                return Ledger::thirdParties($db, (string) ($in['q'] ?? ''));
            case 'thirdparties.save':
                return Ledger::saveThirdParty($db, $in);
            case 'years.create':
                return Ledger::createFiscalYear($db, self::int($in, 'year', 0));
            case 'years.lock':
                Ledger::lockPeriod($db, $year(), ($in['until'] ?? '') !== '' ? (string) $in['until'] : null);
                return true;
            case 'years.close':
                return Closing::closeYear($db, $year());
            case 'years.open_next':
                return Closing::openNextYear($db, $year());

            // ---------------------------------------------------------------- lançamentos
            case 'entries.list':
                return Ledger::listEntries($db, $in);
            case 'entries.get':
                $e = Ledger::getEntry($db, self::int($in, 'id', 0));
                $e['attachments'] = Attachments::list($db, (int) $e['id']);
                return $e;
            case 'attachments.list':
                return Attachments::list($db, self::int($in, 'entry_id', 0));
            case 'attachments.upload':
                [, $name, $content] = self::upload();
                return Attachments::add($db, self::int($in, 'entry_id', 0), $name, $content);
            case 'attachments.download':
                [$content, $name, $mime] = Attachments::get($db, self::int($in, 'id', 0));
                return new Download($content, $name, $mime, !empty($in['inline']) && (str_starts_with($mime, 'image/') || $mime === 'application/pdf'));
            case 'attachments.delete':
                Attachments::delete($db, self::int($in, 'id', 0));
                return true;
            case 'entries.save':
                return Ledger::saveEntry($db, $in);
            case 'entries.validate':
                return Ledger::validateEntry($db, self::int($in, 'id', 0));
            case 'entries.delete':
                Ledger::deleteDraft($db, self::int($in, 'id', 0));
                return true;
            case 'entries.reverse':
                return Ledger::reverseEntry($db, self::int($in, 'id', 0), ($in['date'] ?? '') ?: null);

            // ---------------------------------------------------------------- reconciliação bancária
            case 'bank.accounts':
                return Bank::accounts($db);
            case 'bank.template':
                return new Download(Bank::template(), 'modelo_extrato_bancario.csv', 'text/csv; charset=utf-8');
            case 'bank.preview':
            case 'bank.import':
                [, $name, $content] = self::upload();
                return Bank::import($db, (string) ($in['account'] ?? ''), $content, $action === 'bank.import', $name, isset($in['opening']) ? (string) $in['opening'] : null);
            case 'bank.statements':
                return Bank::statements($db, (string) ($in['account'] ?? ''));
            case 'bank.delete_statement':
                Bank::deleteStatement($db, self::int($in, 'id', 0));
                return true;
            case 'bank.set_start':
                Bank::setStartDate($db, (string) ($in['account'] ?? ''), ($in['date'] ?? '') ?: null);
                return true;
            case 'bank.pending':
                return Bank::pending($db, (string) ($in['account'] ?? ''));
            case 'bank.match':
                return ['match_id' => Bank::match($db, (string) ($in['account'] ?? ''), (array) ($in['bank_ids'] ?? []), (array) ($in['ledger_ids'] ?? []))];
            case 'bank.unmatch':
                Bank::unmatch($db, self::int($in, 'match_id', 0));
                return true;
            case 'bank.matches':
                return Bank::matches($db, (string) ($in['account'] ?? ''));
            case 'bank.auto':
                return Bank::autoMatch($db, (string) ($in['account'] ?? ''));
            case 'bank.create_entry':
                return Bank::createEntry($db, self::int($in, 'bank_line_id', 0), (string) ($in['counterpart'] ?? ''), ($in['description'] ?? '') ?: null, self::int($in, 'third_party', 0) ?: null);
            case 'bank.report':
                $acc = (string) ($in['account'] ?? '');
                $date = (string) (($in['date'] ?? '') ?: ($db->value('SELECT MAX(date) FROM bank_lines WHERE account = ?', [$acc]) ?: date('Y-m-d')));
                return Bank::report($db, $acc, $date);

            // ---------------------------------------------------------------- relatórios
            case 'reports.trial':
                return Reports::trialBalance($db, $year(), $from, $to, isset($in['level']) && $in['level'] !== '' ? (int) $in['level'] : null);
            case 'reports.ledger':
                return Reports::ledger($db, (string) ($in['account'] ?? ''), $year(), $from, $to, self::int($in, 'limit', 0), self::int($in, 'offset', 0));
            case 'reports.journal':
                return Reports::journal($db, $year(), $from, $to, ($in['journal'] ?? '') ?: null, self::int($in, 'limit', 0), self::int($in, 'offset', 0));
            case 'reports.balance':
                return Reports::balanceSheet($db, $year(), $to);
            case 'reports.income':
                $cur = Reports::incomeStatement($db, $year(), $from, $to);
                $prevYear = $year() - 1;
                $cur['previous'] = $db->value('SELECT 1 FROM fiscal_years WHERE year = ?', [$prevYear]) ? Reports::incomeStatement($db, $prevYear, $from, $to) : null;
                return $cur;
            case 'vat.settlement':
                return Vat::settlement($db, $year(), $from, $to);
            case 'vat.post':
                return Vat::postSettlement($db, $year(), $from, $to);

            // ---------------------------------------------------------------- estatísticas
            case 'stats.dashboard':
                return Stats::dashboard($db, $year(), isset($in['to']) ? $to : null);
            case 'stats.ratios':
                return Stats::ratios($db, $year(), isset($in['to']) ? $to : null);
            case 'stats.costs':
                return Stats::costs($db, $year(), $from, $to);
            case 'stats.top':
                return Stats::topParties($db, $year(), ($in['kind'] ?? 'C') === 'F' ? 'F' : 'C', self::int($in, 'limit', 10));
            case 'stats.open':
                return Stats::openBalances($db, $year(), ($in['kind'] ?? 'C') === 'F' ? 'F' : 'C');
            case 'stats.forecast':
                return Stats::forecast($db, $year());
            case 'stats.budget':
                return Stats::budgetVsActual($db, $year(), isset($in['to']) ? $to : null);
            case 'stats.budget_save':
                Stats::saveBudget($db, $year(), (string) ($in['account'] ?? ''), (array) ($in['months'] ?? []));
                return true;
            case 'stats.custom':
                return Stats::customIndicators($db, $year(), isset($in['to']) ? $to : null);
            case 'stats.custom_save':
                Stats::saveCustomIndicators($db, (array) ($in['items'] ?? []));
                return Stats::customIndicators($db, $year());

            // ---------------------------------------------------------------- integridade, cópias, auditoria
            case 'chain.verify':
                return Ledger::verifyChain($db);
            case 'audit.list':
                return $db->all('SELECT * FROM audit_log ORDER BY id DESC LIMIT 300');
            case 'imports.list':
                return $db->all('SELECT id, ts, source, filename, summary FROM import_log ORDER BY id DESC');
            case 'backup.create':
                $file = Companies::backup(basename($db->path, '.ideia'));
                return ['file' => $file];
            case 'backup.download':
                $id = basename($db->path, '.ideia');
                $file = Companies::backup($id);
                return new Download(file_get_contents($file), basename($file), 'application/octet-stream');

            // ---------------------------------------------------------------- SAF-T e importação
            case 'saft.export':
                $y = $year();
                $nif = (string) $db->meta('company_nif');
                $t = strtoupper((string) ($in['type'] ?? 'C')) === 'F' ? 'F' : 'C';
                return new Download(Exporter::export($db, $y, $t), "SAFT_CV_{$nif}_{$y}_{$t}.xml", 'application/xml');
            case 'saft.validate':
                $xml = Exporter::export($db, $year(), (string) ($in['type'] ?? 'C'));
                $errors = Exporter::validate($xml);
                return ['ok' => !$errors, 'errors' => $errors, 'bytes' => strlen($xml)];
            case 'saft.preview':
                [, $name, $content] = self::upload();
                return Importer::preview($db, $content) + ['filename' => $name];
            case 'saft.import':
                [, $name, $content] = self::upload();
                return Importer::commit($db, $content, ['create_opening' => !empty($in['create_opening']) && $in['create_opening'] !== 'false', 'map' => (array) ($in['map'] ?? [])], $name);
            case 'import.template':
                $type = (string) ($in['type'] ?? '');
                return new Download(Csv::template($type), "modelo_{$type}.csv", 'text/csv; charset=utf-8');
            case 'csv.preview':
            case 'csv.import':
                [, $name, $content] = self::upload();
                return Csv::import($db, (string) ($in['type'] ?? ''), $content, $action === 'csv.import',
                    ['year' => self::int($in, 'year', 0), 'validate' => ($in['validate'] ?? '1') !== '0'], $name);
        }
        throw new UserError("Ação desconhecida: {$action}");
    }
}
