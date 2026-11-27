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
    private const GLOBAL = ['ping', 'companies.list', 'companies.create', 'companies.restore', 'portfolio'];

    /** Ações de leitura (GET). As restantes exigem POST. */
    private const READ = [
        'ping', 'companies.list', 'portfolio', 'company.info', 'accounts.list', 'thirdparties.list', 'entries.list', 'entries.get',
        'reports.trial', 'reports.ledger', 'reports.journal', 'reports.balance', 'reports.income', 'vat.settlement',
        'stats.dashboard', 'stats.ratios', 'stats.costs', 'stats.top', 'stats.open', 'stats.forecast', 'stats.budget', 'stats.custom',
        'chain.verify', 'audit.list', 'imports.list', 'backup.download', 'saft.export', 'import.template', 'attachments.list', 'attachments.download',
        'bank.accounts', 'bank.template', 'bank.statements', 'bank.pending', 'bank.matches', 'bank.report',
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
            self::json(['ok' => false, 'error' => 'Erro interno: ' . $e->getMessage()], 500);
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
                ];
            case 'company.update':
                $nif = preg_replace('/\D/', '', (string) ($in['nif'] ?? '')) ?? '';
                if ($nif !== '' && strlen($nif) !== 9) {
                    throw new UserError('O NIF deve ter 9 dígitos.');
                }
                if (trim((string) ($in['name'] ?? '')) === '') {
                    throw new UserError('Indique o nome da empresa.');
                }
                foreach (['name' => 'company_name', 'address' => 'company_address', 'city' => 'company_city', 'regime' => 'tax_regime'] as $k => $meta) {
                    if (isset($in[$k])) {
                        $db->setMeta($meta, trim((string) $in[$k]));
                    }
                }
                $db->setMeta('company_nif', $nif);
                $db->audit('empresa.alterada', []);
                return true;

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
                return Reports::ledger($db, (string) ($in['account'] ?? ''), $year(), $from, $to);
            case 'reports.journal':
                return Reports::journal($db, $year(), $from, $to, ($in['journal'] ?? '') ?: null);
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
                return new Download(Exporter::export($db, $y), "SAFT_CV_{$nif}_{$y}_C.xml", 'application/xml');
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
