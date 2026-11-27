<?php
declare(strict_types=1);

namespace Ideia;

/**
 * e-Fatura (DNRE, Cabo Verde): configuração, IUD, XML dos DFE segundo o XSD oficial (2024-05-27, manual técnico v11.0),
 * validação prévia, fila de comunicação e envio através do Middleware da e-Fatura.
 *
 * A assinatura XAdES-BES e a autenticação OAuth ficam a cargo do Middleware (instalado pelo contribuinte),
 * que usa os certificados importados na Plataforma Eletrónica. Ver docs/15-efatura.md.
 */
final class Efatura
{
    /** Tipo de documento da aplicação => [código DTC-E, elemento XML, sigla]. */
    public const DOC_TYPES = [
        'FT' => [1, 'Invoice', 'FTE'], 'FR' => [2, 'InvoiceReceipt', 'FRE'], 'TV' => [3, 'SalesReceipt', 'TVE'], 'RC' => [4, 'Receipt', 'RCE'],
        'NC' => [5, 'CreditNote', 'NCE'], 'ND' => [6, 'DebitNote', 'NDE'], 'GE' => [7, 'Transport', 'DTE'], 'GT' => [7, 'Transport', 'DTE'],
    ];
    public const REPOSITORIES = ['3' => 'Teste', '2' => 'Homologação', '1' => 'Produção (principal)'];
    public const CONTINGENCY_REASONS = [
        '4' => 'Sem ligação à Internet', '1' => 'Serviço de autorização da e-Fatura indisponível', '5' => 'Serviço de carimbo temporal indisponível', '0' => 'Outro motivo',
    ];
    /** Motivos de emissão de notas de crédito e de débito (IssueReasonCode). */
    public const ISSUE_REASONS = [
        '2' => ['Art.º 65.º, n.º 2 do CIVA', 'NC,ND'], '3' => ['Art.º 65.º, n.º 3 do CIVA', 'NC,ND'], '4' => ['Art.º 65.º, n.º 4 do CIVA', 'ND'],
        '6' => ['Art.º 65.º, n.º 6 do CIVA', 'NC,ND'], '7' => ['Art.º 65.º, n.º 7 do CIVA', 'NC'], '8' => ['Art.º 65.º, n.º 8 do CIVA', 'NC,ND'],
        '9' => ['Art.º 65.º, n.º 9 do CIVA', 'NC,ND'], 'DD' => ['Débito de despesas', 'ND'], 'DRP' => ['Desconto por rappel', 'NC'],
    ];
    /** Meio de pagamento da aplicação => código UN/ECE 4461 (PaymentMeansCode). */
    public const PAYMENT_MEANS = ['NU' => '10', 'TB' => '31', 'CC' => '48', 'CH' => '20', 'OU' => '1'];
    /** Unidades antigas (texto livre) => código oficial. */
    private const UNIT_ALIASES = [
        'UN' => 'EA', 'UND' => 'EA', 'UNID' => 'EA', 'U' => 'EA', 'PC' => 'H87', 'PÇ' => 'H87', 'KG' => 'KGM', 'G' => 'GRM', 'T' => 'TNE', 'TON' => 'TNE',
        'L' => 'LTR', 'LT' => 'LTR', 'ML' => 'MLT', 'M' => 'MTR', 'CM' => 'CMT', 'KM' => 'KMT', 'M2' => 'MTK', 'M²' => 'MTK', 'M3' => 'MTQ', 'M³' => 'MTQ',
        'H' => 'HUR', 'HR' => 'HUR', 'HORA' => 'HUR', 'MIN' => 'MIN', 'DIA' => 'DAY', 'D' => 'DAY', 'SEM' => 'WEE', 'MES' => 'MON', 'MÊS' => 'MON', 'ANO' => 'ANN',
        'CX' => 'BX', 'PAR' => 'PR', 'KWH' => 'KWH', 'SV' => 'E48', 'SERV' => 'E48', 'VG' => 'LS',
    ];
    private const UNIT_SHORT = [
        'EA' => 'un', 'H87' => 'pç', 'PR' => 'par', 'SET' => 'cj', 'DZN' => 'dz', 'KGM' => 'kg', 'GRM' => 'g', 'TNE' => 't', 'LTR' => 'L', 'MLT' => 'mL',
        'MTR' => 'm', 'CMT' => 'cm', 'KMT' => 'km', 'MTK' => 'm²', 'MTQ' => 'm³', 'KWH' => 'kWh', 'HUR' => 'h', 'MIN' => 'min', 'DAY' => 'dia', 'WEE' => 'sem.',
        'MON' => 'mês', 'ANN' => 'ano', 'E48' => 'serv.', 'LS' => 'vg', 'ACT' => 'ativ.', 'IE' => 'pess.', 'BX' => 'cx',
    ];
    /** Textos de isenção usados antes da lista oficial => código. */
    private const LEGACY_EXEMPTIONS = [
        'Isento nos termos do artigo 9.º do RIVA' => '3',
        'Exportação de bens — isenção (artigo 14.º do RIVA)' => '4',
        'Regime especial dos pequenos contribuintes (REMPE)' => '20',
    ];
    public const SETTINGS = [
        'efatura_enabled' => '0', 'efatura_since' => '', 'efatura_repo' => '3', 'efatura_led' => '', 'efatura_software_code' => '',
        'efatura_transmitter_nif' => '', 'efatura_mw_url' => 'https://localhost:3443', 'efatura_mw_key' => '', 'efatura_mw_verify' => '0',
        'efatura_contingency_reason' => '4',
    ];
    public const DOCS = [
        'manual' => ['manual-tecnico-fatura-eletronica-v11.0.pdf', 'Manual técnico da fatura eletrónica (v11.0, 14/03/2025)', 'application/pdf'],
        'certificado' => ['pedido-certificado-digital-tipos-de-documentos.pdf', 'Pedido de certificado digital: tipos de documentos', 'application/pdf'],
        'motivos' => ['lista-motivos-nao-liquidacao-imposto.xlsx', 'Lista de motivos de não liquidação de imposto (isenções)', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'unidades' => ['codigos-unidades-medidas.xls', 'Códigos de unidades de medida', 'application/vnd.ms-excel'],
    ];
    public const LINKS = [
        ['https://www.efatura.cv/docs/about/e-fatura/', 'O que é a e-Fatura'],
        ['https://www.efatura.cv/guide/', 'Guias práticos (adesão, login, API, Middleware, Emissor Público)'],
        ['https://www.efatura.cv/docs/guides/adesao-pe/', 'Guia de adesão à Plataforma Eletrónica e pedido de certificados'],
        ['https://www.efatura.cv/docs/manual/', 'Manual técnico, XSD e listas de códigos (página oficial)'],
        ['https://pe.efatura.cv/register', 'Registo na Plataforma Eletrónica'],
        ['https://pe.efatura.cv', 'Entrar na Plataforma Eletrónica'],
        ['https://services.efatura.cv/api-list', 'Lista de serviços (API)'],
    ];
    public const MIDDLEWARE_DOWNLOADS = [
        'Windows' => 'https://update.efatura.cv/win/mw-client/mwcore-efatura.zip',
        'macOS' => 'https://update.efatura.cv/mac/mw-client/mwcore-efatura.zip',
        'Linux' => 'https://update.efatura.cv/linux/mw-client/mwcore-efatura.zip',
    ];
    public const NS = 'urn:cv:efatura:xsd:v1.0';
    /** Código das exceções de falta de ligação (o documento fica pendente, em contingência). */
    public const OFFLINE = 599;

    private static ?array $codes = null;

    public static function codes(): array
    {
        return self::$codes ??= require IDEIA_ROOT . '/database/efatura-codes.php';
    }

    // ================================================================== códigos

    /** Código oficial do motivo de isenção a partir do código, da descrição, da menção ou de um texto antigo conhecido. */
    public static function exemptionCode(?string $value): ?string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return null;
        }
        $list = self::codes()['exemptions'];
        if (isset($list[$v])) {
            return $v;
        }
        if (preg_match('/^(\d{1,2})\s*[—–-]/u', $v, $m) && isset($list[$m[1]])) {
            return $m[1];
        }
        $norm = static fn(string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
        foreach ($list as $code => [$desc, $mention]) {
            if ($norm($v) === $norm($desc) || $norm($v) === $norm($mention)) {
                return (string) $code;
            }
        }
        return self::LEGACY_EXEMPTIONS[$v] ?? null;
    }

    /** Texto a imprimir para um motivo de isenção (menção oficial), ou o próprio texto se não for um código. */
    public static function exemptionLabel(?string $value): string
    {
        $code = self::exemptionCode($value);
        return $code ? self::codes()['exemptions'][$code][1] : (string) $value;
    }

    /** Código de unidade oficial (UN/ECE Rec. 20) a partir do código ou de uma abreviatura comum. */
    public static function unitCode(?string $unit): string
    {
        $u = mb_strtoupper(trim((string) $unit));
        if ($u === '') {
            return 'EA';
        }
        if (isset(self::codes()['units'][$u])) {
            return $u;
        }
        return self::UNIT_ALIASES[$u] ?? (self::UNIT_ALIASES[rtrim($u, '.')] ?? '');
    }

    /** Abreviatura para impressão (ex.: KGM => kg). */
    public static function unitShort(?string $unit): string
    {
        $u = trim((string) $unit);
        return self::UNIT_SHORT[$u] ?? $u;
    }

    public static function unitLabel(?string $unit): string
    {
        $c = self::codes();
        $u = trim((string) $unit);
        return $c['units_common'][$u] ?? ($u !== '' ? $u : '');
    }

    // ================================================================== configuração

    public static function settings(Db $db): array
    {
        $s = [];
        foreach (self::SETTINGS as $k => $def) {
            $s[substr($k, 8)] = $db->meta($k) ?? $def;
        }
        $s['mw_key_set'] = $s['mw_key'] !== '';
        unset($s['mw_key']);
        return $s;
    }

    public static function saveSettings(Db $db, array $in): array
    {
        $led = trim((string) ($in['led'] ?? ''));
        if ($led !== '' && (!ctype_digit($led) || (int) $led < 1 || (int) $led > 99999)) {
            throw new UserError('O LED tem de ser um número de 1 a 99999 (está registado na Plataforma Eletrónica).');
        }
        $repo = (string) ($in['repo'] ?? '3');
        if (!isset(self::REPOSITORIES[$repo])) {
            throw new UserError('Repositório inválido.');
        }
        $sw = strtoupper(trim((string) ($in['software_code'] ?? '')));
        if ($sw !== '' && !preg_match('/^[A-Z0-9]{1,10}$/', $sw)) {
            throw new UserError('O código do software tem até 10 letras ou algarismos (sem espaços nem hífenes), tal como registado na Plataforma Eletrónica.');
        }
        $tnif = preg_replace('/\D/', '', (string) ($in['transmitter_nif'] ?? ''));
        if ($tnif !== '' && strlen($tnif) !== 9) {
            throw new UserError('O NIF do transmissor tem 9 dígitos.');
        }
        $url = rtrim(trim((string) ($in['mw_url'] ?? '')), '/') ?: self::SETTINGS['efatura_mw_url'];
        if ($url !== '' && !preg_match('#^https?://[^\s/]+(:\d+)?(/\S*)?$#', $url)) {
            throw new UserError('Endereço do Middleware inválido (ex.: https://localhost:3443).');
        }
        $reason = (string) ($in['contingency_reason'] ?? '4');
        if (!isset(self::CONTINGENCY_REASONS[$reason])) {
            throw new UserError('Motivo de contingência inválido.');
        }
        $since = (string) ($in['since'] ?? '');
        if ($since !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
            throw new UserError('Data de início inválida.');
        }
        $enabled = !empty($in['enabled']) && $in['enabled'] !== 'false' && $in['enabled'] !== '0';
        if ($enabled && $led === '') {
            throw new UserError('Para ativar a e-Fatura indique o LED registado na Plataforma Eletrónica.');
        }
        $db->setMeta('efatura_enabled', $enabled ? '1' : '0');
        $db->setMeta('efatura_since', $since ?: ($enabled ? ($db->meta('efatura_since') ?: date('Y-m-d')) : ''));
        $db->setMeta('efatura_repo', $repo);
        $db->setMeta('efatura_led', $led);
        $db->setMeta('efatura_software_code', $sw);
        $db->setMeta('efatura_transmitter_nif', $tnif);
        $db->setMeta('efatura_mw_url', $url);
        $db->setMeta('efatura_mw_verify', !empty($in['mw_verify']) && $in['mw_verify'] !== 'false' ? '1' : '0');
        $db->setMeta('efatura_contingency_reason', $reason);
        if (array_key_exists('mw_key', $in) && trim((string) $in['mw_key']) !== '') {
            $db->setMeta('efatura_mw_key', trim((string) $in['mw_key']));
        }
        if (!empty($in['mw_key_clear'])) {
            $db->setMeta('efatura_mw_key', '');
        }
        $db->audit('efatura.configuracao', ['ativa' => $enabled, 'repositorio' => $repo, 'led' => $led]);
        return self::settings($db);
    }

    public static function enabled(Db $db): bool
    {
        return $db->meta('efatura_enabled') === '1' && (string) $db->meta('efatura_led') !== '';
    }

    /** Lista do que falta para gerar DFE válidos (dados da empresa e configuração). */
    public static function readiness(Db $db): array
    {
        $p = [];
        if (!preg_match('/^\d{9}$/', (string) $db->meta('company_nif'))) {
            $p[] = 'NIF da empresa com 9 dígitos (Empresa e cópias).';
        }
        if (mb_strlen(trim((string) $db->meta('company_name'))) < 3) {
            $p[] = 'Nome da empresa.';
        }
        if (trim((string) $db->meta('company_address')) === '' && trim((string) $db->meta('company_city')) === '') {
            $p[] = 'Morada da empresa (Empresa e cópias).';
        }
        if (!filter_var((string) $db->meta('company_email'), FILTER_VALIDATE_EMAIL)) {
            $p[] = 'Email da empresa (obrigatório na e-Fatura).';
        }
        if (strlen(preg_replace('/\D/', '', (string) $db->meta('company_phone'))) < 7) {
            $p[] = 'Telefone da empresa (obrigatório na e-Fatura).';
        }
        if ((string) $db->meta('efatura_led') === '') {
            $p[] = 'LED (Lógica de Emissão) registado na Plataforma Eletrónica.';
        }
        if ((string) $db->meta('efatura_software_code') === '') {
            $p[] = 'Código do software registado na Plataforma Eletrónica.';
        }
        return $p;
    }

    // ================================================================== IUD

    public static function luhn(string $digits): int
    {
        $sum = 0;
        $double = true;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = !$double;
        }
        return (10 - $sum % 10) % 10;
    }

    /** IUD de 45 carateres: CV + repositório + AAMMDD + NIF + LED + tipo + n.º + código aleatório + dígito Luhn. */
    public static function makeIud(string $repo, string $date, string $nif, string $led, int $typeCode, int $number, ?string $random = null): string
    {
        $random ??= str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $body = $repo . substr($date, 2, 2) . substr($date, 5, 2) . substr($date, 8, 2) . str_pad($nif, 9, '0', STR_PAD_LEFT)
            . str_pad($led, 5, '0', STR_PAD_LEFT) . str_pad((string) $typeCode, 2, '0', STR_PAD_LEFT) . str_pad((string) $number, 9, '0', STR_PAD_LEFT) . $random;
        return 'CV' . $body . self::luhn($body);
    }

    public static function validIud(string $iud): bool
    {
        return (bool) preg_match('/^CV\d{43}$/', $iud) && self::luhn(substr($iud, 2, 42)) === (int) $iud[44];
    }

    /** Atribui o IUD a um documento fiscal emitido (se a e-Fatura estiver configurada e o documento ainda não o tiver). */
    public static function assignIud(Db $db, int $id): ?string
    {
        $d = $db->one('SELECT id, type, date, number, status, iud FROM sales_docs WHERE id = ?', [$id]);
        if (!$d || $d['iud'] || $d['status'] === 'rascunho' || !isset(self::DOC_TYPES[$d['type']])) {
            return $d['iud'] ?? null;
        }
        $nif = (string) $db->meta('company_nif');
        $led = (string) $db->meta('efatura_led');
        if (!preg_match('/^\d{9}$/', $nif) || $led === '') {
            return null;
        }
        $iud = self::makeIud((string) ($db->meta('efatura_repo') ?: '3'), $d['date'], $nif, $led, self::DOC_TYPES[$d['type']][0], (int) $d['number']);
        $db->run('UPDATE sales_docs SET iud = ? WHERE id = ?', [$iud, $id]);
        return $iud;
    }

    public static function qrUrl(string $iud): string
    {
        return 'https://pe.efatura.cv/dfe/view/' . $iud;
    }

    // ================================================================== XML

    private static function dec(int $cents): string
    {
        $s = sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function milli(int $milli): string
    {
        $s = sprintf('%d.%03d', intdiv($milli, 1000), $milli % 1000);
        return rtrim(rtrim($s, '0'), '.');
    }

    private static function text(?string $s, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $s)), 0, $max);
    }

    private static function digits(?string $s): string
    {
        return preg_replace('/\D/', '', (string) $s);
    }

    /** Gera o XML (sem assinatura) de um documento emitido. Lança UserError com a lista do que falta. */
    public static function buildXml(Db $db, int $id, ?int $issueMode = null): string
    {
        $d = Sales::get($db, $id);
        if (!isset(self::DOC_TYPES[$d['type']])) {
            throw new UserError('Este tipo de documento não é comunicado à e-Fatura.');
        }
        if ($d['status'] === 'rascunho') {
            throw new UserError('Só documentos emitidos podem ser comunicados.');
        }
        [$typeCode, $element] = self::DOC_TYPES[$d['type']];
        $missing = self::readiness($db);
        $iud = $d['iud'] ?: self::assignIud($db, $id);
        $party = $d['third_party'] ? $db->one('SELECT * FROM third_parties WHERE id = ?', [$d['third_party']]) : null;
        $needsReceiver = $d['type'] !== 'TV' || $d['total_cents'] >= 2000000 || $d['customer_nif'];
        $country = strtoupper((string) ($party['country'] ?? 'CV')) ?: 'CV';
        if ($needsReceiver) {
            $rnif = $country === 'CV' ? self::digits($d['customer_nif']) : trim((string) $d['customer_nif']);
            if ($country === 'CV' ? !preg_match('/^\d{9}$/', $rnif) : $rnif === '') {
                $missing[] = 'NIF do cliente' . ($d['type'] === 'TV' ? ' (obrigatório em talões de 20 000 ECV ou mais)' : '') . '.';
            }
            if (mb_strlen(trim((string) $d['customer_name'])) < 3) {
                $missing[] = 'Nome do cliente (mínimo 3 carateres).';
            }
            if ($d['type'] !== 'TV' && trim((string) $d['customer_address']) === '') {
                $missing[] = 'Morada do cliente (preencha a ficha do cliente; o documento guarda a morada no momento da emissão).';
            }
        }
        $lineData = [];
        foreach ($d['lines'] as $l) {
            $unit = self::unitCode($l['unit']);
            if ($unit === '') {
                $missing[] = "Unidade \"{$l['unit']}\" da linha {$l['line_no']} não está na lista oficial de unidades.";
            }
            $exCode = null;
            if ((int) $l['vat_rate_bp'] === 0) {
                $exCode = self::exemptionCode($l['exemption']);
                if (!$exCode) {
                    $missing[] = "Motivo de isenção da linha {$l['line_no']} não corresponde a um código da lista oficial.";
                }
            }
            $lineData[] = [$l, $unit, $exCode];
        }
        if (in_array($d['type'], ['NC', 'ND'], true)) {
            if (!isset(self::ISSUE_REASONS[(string) $d['issue_reason']])) {
                $missing[] = 'Motivo de emissão da nota (artigo 65.º do CIVA) — escolha-o no documento.';
            }
            if (!$d['origin_id']) {
                $missing[] = 'Fatura a que a nota se refere.';
            }
        }
        if ($missing) {
            throw new UserError("Não é possível gerar o documento para a e-Fatura. Falta:\n• " . implode("\n• ", array_unique($missing)));
        }

        $issuedAt = $d['issued_at'] ?: ($d['date'] . ' 00:00:00');
        $time = substr($issuedAt, 11, 8);
        $mode = $issueMode ?? self::issueModeFor($d);
        $x = new \XMLWriter();
        $x->openMemory();
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('Dfe');
        $x->writeAttribute('xmlns', self::NS);
        $x->writeAttribute('Version', '1.0');
        $x->writeAttribute('Id', $iud);
        $x->writeAttribute('DocumentTypeCode', (string) $typeCode);
        $x->startElement($element);
        $x->writeElement('LedCode', (string) (int) $db->meta('efatura_led'));
        $x->writeElement('Serie', (string) $d['series']);
        $x->writeElement('DocumentNumber', (string) $d['number']);
        $x->writeElement('InnerDocumentNumber', str_replace(' ', '', (string) $d['doc_no']));
        $x->writeElement('IssueDate', $d['date']);
        $x->writeElement('IssueTime', $time);
        if ($d['type'] === 'FT' && $d['due_date']) {
            $x->writeElement('DueDate', $d['due_date']);
        }
        if (in_array($d['type'], ['FT', 'FR'], true) && trim((string) $d['reference']) !== '' && preg_match('/^\S{1,50}$/', trim((string) $d['reference']))) {
            $x->startElement('OrderReference');
            $x->writeElement('Id', trim((string) $d['reference']));
            $x->endElement();
        }
        if (in_array($d['type'], ['NC', 'ND'], true)) {
            $x->writeElement('IssueReasonCode', (string) $d['issue_reason']);
        }
        if (in_array($d['type'], ['GE', 'GT'], true)) {
            $x->writeElement('ReceiverTypeCode', '1');
            $x->writeElement('TransportDocumentTypeCode', $d['type'] === 'GT' ? '2' : '1');
        }
        self::writeEmitter($x, $db);
        if ($needsReceiver) {
            self::writeParty($x, 'ReceiverParty', $country, $country === 'CV' ? self::digits($d['customer_nif']) : trim((string) $d['customer_nif']),
                (string) $d['customer_name'], (string) $d['customer_address'], $party['phone'] ?? null, $party['email'] ?? $d['party_email'] ?? null, $d['type'] === 'TV');
        }
        if (in_array($d['type'], ['GE', 'GT'], true)) {
            $x->startElement('TransportServiceProviderParty');
            $x->writeElement('Reference', 'EP');
            $x->endElement();
        }
        if ($d['type'] === 'RC') {
            $kinds = $db->all('SELECT DISTINCT COALESCE(i.kind, \'S\') AS k FROM sales_settlements s JOIN sales_doc_lines l ON l.doc_id = s.invoice_id LEFT JOIN items i ON i.id = l.item_id WHERE s.receipt_id = ?', [$id]);
            $k = array_column($kinds, 'k');
            $x->writeElement('ReceiptTypeCode', $k === ['P'] ? '1' : ($k === ['S'] ? '2' : '3'));
        } else {
            self::writeLines($x, $lineData, in_array($d['type'], ['GE', 'GT'], true));
            if (!in_array($d['type'], ['GE', 'GT'], true)) {
                self::writeTotals($x, $d);
            }
        }
        if (in_array($d['type'], ['GE', 'GT'], true)) {
            self::writeRoute($x, $db, $d, $time);
        }
        // referências: NC/ND => fatura de origem; RC => faturas pagas
        $refs = [];
        if (in_array($d['type'], ['NC', 'ND'], true)) {
            $refs[] = [(int) $d['origin_id'], null];
        } elseif ($d['type'] === 'RC') {
            foreach ($d['settlements'] as $s) {
                $refs[] = [(int) $s['invoice_id'], (int) $s['amount_cents']];
            }
        }
        if ($refs) {
            $x->startElement('References');
            foreach ($refs as [$rid, $amount]) {
                $o = $db->one('SELECT type, series, number, iud, doc_no FROM sales_docs WHERE id = ?', [$rid]);
                $x->startElement('Reference');
                $x->startElement('FiscalDocument');
                if ($o['iud']) {
                    $x->writeAttribute('IsOldDocument', 'false');
                    $x->text($o['iud']);
                } else {
                    $x->writeAttribute('IsOldDocument', 'true');
                    $x->text((self::DOC_TYPES[$o['type']][0] ?? 1) . '/' . $o['series'] . '/' . $o['series'] . '/' . $o['number']);
                }
                $x->endElement();
                $x->writeElement('InnerDocumentNumber', str_replace(' ', '', (string) $o['doc_no']));
                if ($amount) {
                    $x->writeElement('PaymentAmount', self::dec($amount));
                }
                $x->endElement();
            }
            $x->endElement();
        }
        // pagamentos
        if ($d['type'] === 'FT') {
            $nib = self::nib((string) $db->meta('company_bank'));
            if ($d['due_date'] || $nib) {
                $x->startElement('Payments');
                if ($d['due_date']) {
                    $x->writeElement('PaymentDueDate', $d['due_date']);
                }
                if ($nib) {
                    $x->startElement('PayeeFinancialAccount');
                    $x->writeElement('NIB', $nib);
                    $x->writeElement('Name', self::text($db->meta('company_name'), 150));
                    $x->endElement();
                }
                $x->endElement();
            }
        } elseif (in_array($d['type'], ['FR', 'TV', 'RC'], true)) {
            $x->startElement('Payments');
            $x->startElement('Payment');
            $x->writeElement('PaymentMeansCode', self::PAYMENT_MEANS[$d['payment_method'] ?: 'NU'] ?? '1');
            $x->writeElement('PaymentDate', $d['date']);
            if ((int) $d['total_cents'] > 0) {
                $x->writeElement('PaymentAmount', self::dec((int) $d['total_cents']));
            }
            $x->endElement();
            $x->endElement();
        }
        $note = self::text($d['notes'], 500);
        if (mb_strlen($note) >= 10) {
            $x->writeElement('Note', $note);
        }
        $x->endElement(); // documento
        // transmissão
        $x->startElement('Transmission');
        $x->writeElement('IssueMode', (string) $mode);
        $x->startElement('TransmitterTaxId');
        $x->writeAttribute('CountryCode', 'CV');
        $x->text((string) ($db->meta('efatura_transmitter_nif') ?: $db->meta('company_nif')));
        $x->endElement();
        $x->startElement('Software');
        $x->writeElement('Code', (string) $db->meta('efatura_software_code'));
        $x->writeElement('Name', 'IDEIA Contabilidade');
        $x->writeElement('Version', IDEIA_VERSION);
        $x->endElement();
        if ($mode !== 1) {
            $reason = (string) ($db->meta('efatura_contingency_reason') ?: '4');
            $x->startElement('Contingency');
            $x->writeElement('LedCode', (string) (int) $db->meta('efatura_led'));
            $x->writeElement('IssueDate', $d['date']);
            $x->writeElement('IssueTime', $time);
            $x->writeElement('ReasonTypeCode', $reason);
            if ($reason === '0') {
                $x->writeElement('ReasonDescription', 'Documento emitido sem comunicação imediata à e-Fatura.');
            }
            $x->endElement();
        }
        $x->endElement();
        $x->writeElement('RepositoryCode', (string) (int) substr($iud, 2, 1));
        $x->endElement(); // Dfe
        $x->endDocument();
        return $x->outputMemory();
    }

    /** Modo de emissão: online se é comunicado até 24 horas depois da emissão e sem falhas anteriores; senão offline (contingência). */
    public static function issueModeFor(array $d): int
    {
        if (!empty($d['efatura_mode'])) {
            return (int) $d['efatura_mode'];
        }
        // a e-Fatura só aceita emissão online até 24 horas depois da data/hora do documento
        $issued = strtotime($d['date'] . ' ' . substr((string) ($d['issued_at'] ?: $d['date'] . ' 00:00:00'), 11, 8));
        return time() - $issued <= 23 * 3600 ? 1 : 2;
    }

    private static function nib(string $bank): ?string
    {
        $digits = self::digits(preg_replace('/^\s*CV\d{2}/i', '', $bank));
        return strlen($digits) === 21 ? $digits : null;
    }

    private static function writeEmitter(\XMLWriter $x, Db $db): void
    {
        $addr = trim(implode(', ', array_filter([trim((string) $db->meta('company_address')), trim((string) $db->meta('company_city'))])));
        self::writeParty($x, 'EmitterParty', 'CV', (string) $db->meta('company_nif'), (string) $db->meta('company_name'), $addr,
            $db->meta('company_phone'), $db->meta('company_email'), false);
    }

    private static function writeParty(\XMLWriter $x, string $el, string $country, string $nif, string $name, string $address, ?string $phone, ?string $email, bool $optional): void
    {
        $x->startElement($el);
        $x->startElement('TaxId');
        $x->writeAttribute('CountryCode', $country);
        $x->text($nif);
        $x->endElement();
        $x->writeElement('Name', self::text($name, 150));
        if (trim($address) !== '' || !$optional) {
            $x->startElement('Address');
            $x->writeAttribute('CountryCode', $country);
            $x->writeElement('AddressDetail', self::text($address, 100));
            $x->endElement();
        }
        $tel = self::digits($phone);
        $tel = strlen($tel) >= 7 && strlen($tel) <= 20 ? $tel : '';
        $mail = filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL) ? trim((string) $email) : '';
        if ($tel !== '' || $mail !== '') {
            $x->startElement('Contacts');
            if ($tel !== '') {
                $x->writeElement(preg_match('/^(238)?9/', $tel) ? 'Mobilephone' : 'Telephone', $tel);
            }
            if ($mail) {
                $x->writeElement('Email', $mail);
            }
            $x->endElement();
        }
        $x->endElement();
    }

    private static function writeLines(\XMLWriter $x, array $lineData, bool $transport): void
    {
        $x->startElement('Lines');
        foreach ($lineData as [$l, $unit, $exCode]) {
            $gross = self::gross($l);
            $x->startElement('Line');
            $x->writeAttribute('LineTypeCode', 'N');
            $x->writeElement('Id', (string) $l['line_no']);
            $x->startElement('Quantity');
            $x->writeAttribute('UnitCode', $unit);
            $x->text(self::milli((int) $l['qty_milli']));
            $x->endElement();
            $x->writeElement('Price', self::dec((int) $l['price_cents']));
            $x->writeElement('PriceExtension', self::dec($gross));
            if ((int) $l['discount_bp'] > 0) {
                $x->startElement('Discount');
                $x->writeAttribute('ValueType', 'P');
                $x->text(rtrim(rtrim(sprintf('%.2f', (int) $l['discount_bp'] / 100), '0'), '.'));
                $x->endElement();
            }
            $x->writeElement('NetTotal', self::dec((int) $l['net_cents']));
            {
                $x->startElement('Tax');
                if ((int) $l['vat_rate_bp'] > 0) {
                    $x->writeAttribute('TaxTypeCode', 'IVA');
                    $x->writeElement('TaxPercentage', rtrim(rtrim(sprintf('%.2f', (int) $l['vat_rate_bp'] / 100), '0'), '.'));
                } else {
                    $x->writeAttribute('TaxTypeCode', 'NA');
                    $x->writeElement('TaxExemptionReasonCode', (string) $exCode);
                }
                $x->endElement();
            }
            $x->startElement('Item');
            $x->writeElement('Description', self::text($l['description'], 300));
            $code = preg_replace('/\s+/', '-', trim((string) $l['code']));
            $x->writeElement('EmitterIdentification', mb_substr($code !== '' ? $code : 'L' . $l['line_no'], 0, 50));
            $x->endElement();
            $x->endElement();
        }
        $x->endElement();
    }

    /** Valor ilíquido da linha (quantidade × preço, antes do desconto), arredondado como em Sales::lineAmounts. */
    private static function gross(array $l): int
    {
        return intdiv((int) $l['qty_milli'] * (int) $l['price_cents'] + 500, 1000);
    }

    private static function writeTotals(\XMLWriter $x, array $d): void
    {
        $gross = 0;
        foreach ($d['lines'] as $l) {
            $gross += self::gross($l);
        }
        $x->startElement('Totals');
        $x->writeElement('PriceExtensionTotalAmount', self::dec($gross));
        $x->writeElement('ChargeTotalAmount', '0');
        $x->writeElement('DiscountTotalAmount', self::dec((int) $d['discount_cents']));
        $x->writeElement('NetTotalAmount', self::dec((int) $d['net_cents']));
        $x->writeElement('TaxTotalAmount', self::dec((int) $d['vat_cents']));
        $x->writeElement('WithholdingTaxTotalAmount', '0');
        $x->writeElement('PayableAmount', self::dec((int) $d['total_cents']));
        $x->endElement();
    }

    private static function writeRoute(\XMLWriter $x, Db $db, array $d, string $time): void
    {
        $from = trim(implode(', ', array_filter([trim((string) $db->meta('company_address')), trim((string) $db->meta('company_city'))])));
        $to = trim((string) ($d['delivery_place'] ?: $d['customer_address']));
        if ($to === '') {
            throw new UserError('Indique o local de entrega da guia (ou a morada do cliente).');
        }
        $x->startElement('TransportRoute');
        foreach ([$from, $to] as $addr) {
            $x->startElement('TransportLocation');
            $x->startElement('Address');
            $x->writeAttribute('CountryCode', 'CV');
            $x->writeElement('AddressDetail', self::text($addr, 100));
            $x->endElement();
            $x->startElement('Duration');
            $x->writeElement('StartDate', $d['date']);
            $x->writeElement('StartTime', $time);
            $x->endElement();
            $x->writeElement('TransportModeCode', '3');
            $x->endElement();
        }
        $x->endElement();
    }

    /** Validação prévia com o XSD oficial (Dfe com assinatura opcional). Devolve a lista de erros. */
    public static function validate(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $dom = new \DOMDocument();
        $errors = [];
        if (!$dom->loadXML($xml)) {
            $errors[] = 'XML mal formado.';
        } elseif (!$dom->schemaValidate(IDEIA_ROOT . '/resources/efatura/xsd/EnvelopedSignature.xsd')) {
            foreach (libxml_get_errors() as $e) {
                $errors[] = trim($e->message) . ' (linha ' . $e->line . ')';
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $errors;
    }

    // ================================================================== conferência do IVA

    /**
     * Conferência mensal do IVA: documentos (e-Fatura) vs contabilidade.
     * Vendas: IVA dos documentos fiscais emitidos, separado pelo estado na e-Fatura, e IVA liquidado nos lançamentos.
     * Compras: IVA das faturas de fornecedores registadas, separado entre as que vieram da e-Fatura (com IUD) e as
     * registadas à mão, e IVA dedutível nos lançamentos. Diferenças apontam lançamentos manuais ou documentos em falta.
     */
    public static function conference(Db $db, int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => $m, 'sales_authorized' => 0, 'sales_pending' => 0, 'sales_excluded' => 0, 'sales_ledger' => 0,
                'purchases_efatura' => 0, 'purchases_manual' => 0, 'purchases_ledger' => 0, 'purchases_manual_docs' => 0];
        }
        $y = (string) $year;
        foreach ($db->all("SELECT CAST(substr(date,6,2) AS INTEGER) AS m, COALESCE(efatura_status,'pendente') AS s,
                SUM(CASE WHEN type = 'NC' THEN -vat_cents ELSE vat_cents END) AS v
            FROM sales_docs WHERE status = 'emitido' AND type IN ('FT','FR','TV','ND','NC') AND substr(date,1,4) = ? GROUP BY 1, 2", [$y]) as $r) {
            $k = $r['s'] === 'autorizado' ? 'sales_authorized' : ($r['s'] === 'excluido' ? 'sales_excluded' : 'sales_pending');
            $months[(int) $r['m']][$k] += (int) $r['v'];
        }
        foreach ($db->all("SELECT CAST(substr(date,6,2) AS INTEGER) AS m, iud IS NOT NULL AS e, COUNT(*) AS n,
                SUM(CASE WHEN type = 'NF' THEN -vat_cents ELSE vat_cents END) AS v
            FROM purchase_docs WHERE status = 'registado' AND type IN ('FC','FP','NF') AND substr(date,1,4) = ? GROUP BY 1, 2", [$y]) as $r) {
            $months[(int) $r['m']][$r['e'] ? 'purchases_efatura' : 'purchases_manual'] += (int) $r['v'];
            if (!$r['e']) {
                $months[(int) $r['m']]['purchases_manual_docs'] += (int) $r['n'];
            }
        }
        foreach ($db->all("SELECT m.month AS m, v.kind, SUM(m.credit_cents - m.debit_cents) AS c
            FROM v_movements m JOIN vat_codes v ON v.code = m.vat_code AND v.account = m.account
            WHERE m.fiscal_year = ? AND v.kind IN ('liquidado','dedutivel') AND v.rate_bp > 0 AND m.journal_kind <> 'apuramento' GROUP BY 1, 2", [$year]) as $r) {
            if ($r['kind'] === 'liquidado') {
                $months[(int) $r['m']]['sales_ledger'] += (int) $r['c'];
            } else {
                $months[(int) $r['m']]['purchases_ledger'] -= (int) $r['c'];
            }
        }
        // antes da adesão à e-Fatura não há documentos eletrónicos: as diferenças desses meses não contam
        $since = (string) $db->meta('efatura_since');
        $rows = [];
        $tot = [];
        foreach ($months as $m => $r) {
            $r['before_since'] = $since !== '' && sprintf('%04d-%02d-31', $year, $m) < $since;
            $r['sales_docs'] = $r['sales_authorized'] + $r['sales_pending'] + $r['sales_excluded'];
            $r['sales_diff'] = $r['before_since'] ? 0 : $r['sales_ledger'] - $r['sales_docs'];
            $r['purchases_docs'] = $r['purchases_efatura'] + $r['purchases_manual'];
            $r['purchases_diff'] = $r['before_since'] ? 0 : $r['purchases_ledger'] - $r['purchases_docs'];
            foreach ($r as $k => $v) {
                if (is_int($v) && $k !== 'month') {
                    $tot[$k] = ($tot[$k] ?? 0) + $v;
                }
            }
            $rows[] = $r;
        }
        $tot['month'] = null;
        $tot['before_since'] = false;
        return ['year' => $year, 'since' => $since, 'rows' => $rows, 'totals' => $tot,
            'manual_docs' => $db->all("SELECT d.id, d.doc_no, d.supplier_doc, d.date, d.vat_cents, t.name AS supplier, t.nif FROM purchase_docs d LEFT JOIN third_parties t ON t.id = d.third_party
                WHERE d.status = 'registado' AND d.type IN ('FC','FP') AND d.iud IS NULL AND d.vat_cents > 0 AND substr(d.date,1,4) = ? ORDER BY d.date LIMIT 200", [$y])];
    }

    // ================================================================== fila e envio

    /** Documentos fiscais emitidos e o seu estado na e-Fatura. */
    public static function documents(Db $db, array $f = []): array
    {
        $types = "'" . implode("','", array_keys(self::DOC_TYPES)) . "'";
        $where = ["d.type IN ($types)", "d.status <> 'rascunho'"];
        $p = [];
        if (($f['status'] ?? '') !== '') {
            $where[] = 'COALESCE(d.efatura_status, \'pendente\') = ?';
            $p[] = $f['status'];
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (($f[$k] ?? '') !== '') {
                $where[] = "d.date $op ?";
                $p[] = $f[$k];
            }
        }
        $rows = $db->all('SELECT d.id, d.type, d.doc_no, d.date, d.issued_at, d.status, d.total_cents, d.customer_name, d.customer_nif, d.iud,
                COALESCE(d.efatura_status, \'pendente\') AS efatura_status, d.efatura_message, d.efatura_at, d.efatura_mode
            FROM sales_docs d WHERE ' . implode(' AND ', $where) . ' ORDER BY d.date DESC, d.id DESC LIMIT 500', $p);
        $since = (string) $db->meta('efatura_since');
        foreach ($rows as &$r) {
            $r['before_since'] = $since !== '' && $r['date'] < $since;
            $r['days'] = (int) floor((time() - strtotime((string) ($r['issued_at'] ?: $r['date']))) / 86400);
        }
        $counts = [];
        foreach ($db->all("SELECT COALESCE(efatura_status, 'pendente') AS s, COUNT(*) AS n FROM sales_docs WHERE type IN ($types) AND status <> 'rascunho' GROUP BY 1") as $c) {
            $counts[$c['s']] = (int) $c['n'];
        }
        return ['rows' => $rows, 'counts' => $counts, 'since' => $since];
    }

    public static function setStatus(Db $db, int $id, string $status, ?string $message = null, ?int $mode = null): void
    {
        $db->run('UPDATE sales_docs SET efatura_status = ?, efatura_message = ?, efatura_at = ?, efatura_mode = COALESCE(?, efatura_mode) WHERE id = ?',
            [$status, $message, date('Y-m-d H:i:s'), $mode, $id]);
    }

    /** Marca documentos para não comunicar (por exemplo, emitidos antes da adesão) ou repõe-nos na fila. */
    public static function exclude(Db $db, array $ids, bool $exclude): int
    {
        $n = 0;
        foreach ($ids as $id) {
            $s = $db->value('SELECT COALESCE(efatura_status, \'pendente\') FROM sales_docs WHERE id = ?', [(int) $id]);
            if (in_array($s, ['autorizado', 'enviado'], true)) {
                continue;
            }
            self::setStatus($db, (int) $id, $exclude ? 'excluido' : 'pendente', $exclude ? 'Não comunicar (marcado pelo utilizador)' : null);
            $n++;
        }
        $db->audit('efatura.excluir', ['ids' => $ids, 'excluir' => $exclude]);
        return $n;
    }

    /** ZIP (Deflate) com um ou mais XML, com o nome de cada ficheiro igual ao IUD, como pede o serviço de emissão. */
    public static function zip(array $files): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new UserError('A extensão zip do PHP não está ativa.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dfe');
        $z = new \ZipArchive();
        $z->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $xml) {
            $z->addFromString($name, $xml);
            $z->setCompressionName($name, \ZipArchive::CM_DEFLATE);
        }
        $z->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    /** Envia documentos através do Middleware. Devolve o resultado por documento. */
    public static function send(Db $db, array $ids, ?callable $transport = null): array
    {
        if (!self::enabled($db)) {
            throw new UserError('Ative e configure a e-Fatura antes de enviar documentos.');
        }
        $url = rtrim((string) $db->meta('efatura_mw_url'), '/');
        $key = (string) $db->meta('efatura_mw_key');
        if ($url === '' || $key === '') {
            throw new UserError('Indique o endereço e a chave de transmissor (transmitter-key) do Middleware.');
        }
        $transport ??= [self::class, 'httpPost'];
        $results = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            $d = Sales::get($db, $id);
            if (in_array($d['efatura_status'], ['autorizado', 'excluido'], true)) {
                $results[] = ['id' => $id, 'doc_no' => $d['doc_no'], 'ok' => true, 'status' => $d['efatura_status'], 'message' => 'Já tratado.'];
                continue;
            }
            $mode = self::issueModeFor($d);
            try {
                $xml = self::buildXml($db, $id, $mode);
                $errors = self::validate($xml);
                if ($errors) {
                    throw new UserError('O XML não cumpre o XSD oficial: ' . implode(' | ', array_slice($errors, 0, 3)));
                }
                $iud = (string) $db->value('SELECT iud FROM sales_docs WHERE id = ?', [$id]);
                $zip = self::zip([$iud . '.xml' => $xml]);
                [$code, $body] = $transport($url . '/v1/dfe', $zip, [
                    'cv-ef-mw-core-transmitter-key: ' . $key,
                    'cv-ef-repository-code: ' . substr($iud, 2, 1),
                ], $db->meta('efatura_mw_verify') === '1');
                [$ok, $msg] = self::parseResponse($code, $body);
                self::setStatus($db, $id, $ok ? 'autorizado' : 'rejeitado', $msg, $mode);
                $results[] = ['id' => $id, 'doc_no' => $d['doc_no'], 'ok' => $ok, 'status' => $ok ? 'autorizado' : 'rejeitado', 'message' => $msg];
            } catch (\Throwable $e) {
                $offline = $e instanceof \RuntimeException && $e->getCode() === self::OFFLINE;
                // sem ligação: fica pendente e passa a contingência (offline); erro de dados: rejeitado localmente
                self::setStatus($db, $id, $offline ? 'pendente' : 'erro', $e->getMessage(), $offline ? 2 : null);
                $results[] = ['id' => $id, 'doc_no' => $d['doc_no'], 'ok' => false, 'status' => $offline ? 'pendente' : 'erro', 'message' => $e->getMessage()];
            }
        }
        $db->audit('efatura.envio', ['documentos' => count($ids), 'autorizados' => count(array_filter($results, static fn($r) => $r['ok']))]);
        return $results;
    }

    /** Interpreta a resposta do serviço de emissão (JSON com "succeeded" e "messages", ou outro conteúdo). */
    public static function parseResponse(int $code, string $body): array
    {
        $j = json_decode($body, true);
        $messages = [];
        if (is_array($j)) {
            foreach ((array) ($j['messages'] ?? $j['Messages'] ?? []) as $m) {
                $messages[] = is_array($m) ? trim(($m['code'] ?? $m['Code'] ?? '') . ' ' . ($m['description'] ?? $m['Description'] ?? $m['message'] ?? json_encode($m, JSON_UNESCAPED_UNICODE))) : (string) $m;
            }
            $ok = $code >= 200 && $code < 300 && ($j['succeeded'] ?? $j['Succeeded'] ?? true) !== false;
        } else {
            $ok = $code >= 200 && $code < 300;
            if (trim($body) !== '') {
                $messages[] = mb_substr(trim(strip_tags($body)), 0, 300);
            }
        }
        $msg = $messages ? implode(' | ', $messages) : ($ok ? 'Autorizado (HTTP ' . $code . ').' : 'Resposta HTTP ' . $code . '.');
        return [$ok, mb_substr($msg, 0, 1000)];
    }

    /** POST multipart/form-data com o ZIP no campo "file". Lança RuntimeException se não houver ligação. */
    public static function httpPost(string $url, string $zip, array $headers, bool $verify): array
    {
        if (!function_exists('curl_init')) {
            throw new UserError('A extensão curl do PHP não está ativa (necessária para comunicar com o Middleware).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dfe');
        file_put_contents($tmp, $zip);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new \CURLFile($tmp, 'application/octet-stream', 'dfe.zip')],
            CURLOPT_HTTPHEADER => array_merge($headers, ['Accept: application/json']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        @unlink($tmp);
        if ($body === false) {
            throw new \RuntimeException('Sem ligação ao Middleware da e-Fatura (' . $err . '). O documento fica pendente e será enviado em modo de contingência.', self::OFFLINE);
        }
        return [$code, (string) $body];
    }

    /** Testa a ligação ao Middleware (pedido simples com a chave de transmissor). */
    public static function test(Db $db): array
    {
        $url = rtrim((string) $db->meta('efatura_mw_url'), '/');
        if ($url === '') {
            throw new UserError('Indique o endereço do Middleware.');
        }
        if (!function_exists('curl_init')) {
            throw new UserError('A extensão curl do PHP não está ativa.');
        }
        $verify = $db->meta('efatura_mw_verify') === '1';
        $ch = curl_init($url . '/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['cv-ef-mw-core-transmitter-key: ' . (string) $db->meta('efatura_mw_key')],
            CURLOPT_SSL_VERIFYPEER => $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['ok' => false, 'message' => "Não foi possível ligar a {$url}: {$err}. Confirme que o Middleware está instalado e a correr."];
        }
        return ['ok' => $code > 0 && $code < 500, 'message' => "O Middleware respondeu (HTTP {$code})." . ($code === 401 || $code === 403 ? ' Verifique a chave de transmissor.' : '')];
    }
}
