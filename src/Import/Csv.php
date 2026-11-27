<?php
declare(strict_types=1);

namespace Ideia\Import;

use Ideia\Db;
use Ideia\Ledger;
use Ideia\Money;
use Ideia\UserError;

/**
 * Importação de folhas de cálculo (CSV exportado do Excel) com modelos fixos:
 *  - contas:      codigo; nome
 *  - terceiros:   nif; nome; tipo (C/F/A/O); morada; cidade; pais; email; telefone; conta
 *  - saldos:      conta; debito; credito                       (gera o lançamento de abertura)
 *  - lancamentos: data; diario; documento; descricao; conta; debito; credito; nif; descricao_linha; codigo_iva; base_iva; lancamento
 *
 * Os lançamentos são agrupados pela coluna "lancamento" (se existir) ou por data + diário + documento.
 */
final class Csv
{
    public const TEMPLATES = [
        'contas' => ['codigo', 'nome'],
        'terceiros' => ['nif', 'nome', 'tipo', 'morada', 'cidade', 'pais', 'email', 'telefone', 'conta'],
        'saldos' => ['conta', 'debito', 'credito'],
        'lancamentos' => ['lancamento', 'data', 'diario', 'documento', 'descricao', 'conta', 'debito', 'credito', 'nif', 'descricao_linha', 'codigo_iva', 'base_iva'],
    ];

    private const ALIASES = [
        'codigo' => ['codigo', 'conta', 'code', 'account', 'accountid', 'cod'],
        'nome' => ['nome', 'descricao', 'designacao', 'name', 'description'],
        'nif' => ['nif', 'contribuinte', 'taxid', 'vat'],
        'tipo' => ['tipo', 'type', 'kind'],
        'morada' => ['morada', 'endereco', 'address'],
        'cidade' => ['cidade', 'localidade', 'city'],
        'pais' => ['pais', 'country'],
        'email' => ['email', 'e-mail'],
        'telefone' => ['telefone', 'telemovel', 'phone'],
        'conta' => ['conta', 'codigo', 'account', 'accountid'],
        'debito' => ['debito', 'debit', 'deb', 'd'],
        'credito' => ['credito', 'credit', 'cred', 'c'],
        'data' => ['data', 'date'],
        'diario' => ['diario', 'journal'],
        'documento' => ['documento', 'doc', 'document', 'numero_documento'],
        'descricao' => ['descricao', 'description', 'historico'],
        'descricao_linha' => ['descricao_linha', 'linha', 'line_description'],
        'codigo_iva' => ['codigo_iva', 'iva', 'vat_code'],
        'base_iva' => ['base_iva', 'base', 'vat_base'],
        'lancamento' => ['lancamento', 'entry', 'n_lancamento', 'id'],
    ];

    public static function template(string $type): string
    {
        if (!isset(self::TEMPLATES[$type])) {
            throw new UserError('Modelo desconhecido.');
        }
        $examples = [
            'contas' => [['2111', 'Clientes nacionais'], ['6221', 'Trabalhos especializados — contabilidade']],
            'terceiros' => [['123456789', 'Cliente Exemplo Lda', 'C', 'Achada Santo António', 'Praia', 'CV', 'geral@exemplo.cv', '2600000', '211']],
            'saldos' => [['121', '500000,00', ''], ['51', '', '500000,00']],
            'lancamentos' => [
                ['1', '2025-01-15', 'VEN', 'FTE 2025/1', 'Venda serviços', '211', '11500,00', '', '123456789', '', '', ''],
                ['1', '2025-01-15', 'VEN', 'FTE 2025/1', 'Venda serviços', '721', '', '10000,00', '', '', '', ''],
                ['1', '2025-01-15', 'VEN', 'FTE 2025/1', 'Venda serviços', '2433', '', '1500,00', '', 'IVA', 'NOR', '10000,00'],
            ],
        ];
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::TEMPLATES[$type], ';');
        foreach ($examples[$type] as $row) {
            fputcsv($out, $row, ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** Lê o CSV: deteta codificação e separador, normaliza cabeçalhos. Devolve [colunas, linhas associativas]. */
    public static function parse(string $content, string $type): array
    {
        if (!isset(self::TEMPLATES[$type])) {
            throw new UserError('Tipo de importação desconhecido.');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $first = strtok($content, "\n") ?: '';
        $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $sep = (string) array_key_first($counts);
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, $content);
        rewind($fh);
        $header = fgetcsv($fh, 0, $sep, '"', '');
        if (!$header) {
            throw new UserError('O ficheiro está vazio.');
        }
        $cols = [];
        foreach ($header as $i => $h) {
            $key = self::normalizeHeader((string) $h);
            foreach (self::TEMPLATES[$type] as $wanted) {
                if (in_array($key, self::ALIASES[$wanted] ?? [$wanted], true) && !in_array($wanted, $cols, true)) {
                    $cols[$i] = $wanted;
                    break;
                }
            }
        }
        $required = match ($type) {
            'contas' => ['codigo', 'nome'],
            'terceiros' => ['nome'],
            'saldos' => ['conta', 'debito', 'credito'],
            'lancamentos' => ['data', 'conta', 'debito', 'credito'],
        };
        $missing = array_diff($required, $cols);
        if ($missing) {
            throw new UserError('Faltam colunas obrigatórias: ' . implode(', ', $missing) . '. Use o modelo disponível na aplicação.');
        }
        $rows = [];
        $line = 1;
        while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $line++;
            if ($r === [null] || implode('', $r) === '') {
                continue;
            }
            $row = ['_line' => $line];
            foreach ($cols as $i => $name) {
                $row[$name] = trim((string) ($r[$i] ?? ''));
            }
            $rows[] = $row;
        }
        fclose($fh);
        return [array_values($cols), $rows];
    }

    private static function normalizeHeader(string $h): string
    {
        $h = mb_strtolower(trim($h));
        $h = strtr($h, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c','º'=>'','ª'=>'','.'=>'']);
        return preg_replace('/[\s\/]+/', '_', $h) ?? $h;
    }

    public static function date(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Valida e (se $commit) grava. Devolve resumo com erros por linha.
     * Opções: year (para saldos), validate (lançamentos validados ou em rascunho).
     */
    public static function import(Db $db, string $type, string $content, bool $commit, array $options = [], string $filename = 'import.csv'): array
    {
        [$cols, $rows] = self::parse($content, $type);
        $errors = [];
        $result = ['type' => $type, 'columns' => $cols, 'rows' => count($rows), 'errors' => &$errors, 'sample' => array_slice($rows, 0, 10)];

        $run = static function () use ($db, $type, $rows, &$errors, $commit, $options, &$result) {
            switch ($type) {
                case 'contas':
                    $n = 0;
                    usort($rows, static fn($a, $b) => strlen($a['codigo']) <=> strlen($b['codigo']));
                    foreach ($rows as $r) {
                        try {
                            if ($commit) {
                                Ledger::saveAccount($db, ['code' => $r['codigo'], 'name' => $r['nome']]);
                            } elseif (!preg_match('/^[1-8]\d*$/', preg_replace('/\D/', '', $r['codigo']) ?? '') || $r['nome'] === '') {
                                throw new UserError('Código ou nome inválido.');
                            }
                            $n++;
                        } catch (\Throwable $e) {
                            $errors[] = ['line' => $r['_line'], 'message' => $e->getMessage()];
                        }
                    }
                    $result['imported'] = $n;
                    break;

                case 'terceiros':
                    $n = 0;
                    foreach ($rows as $r) {
                        try {
                            $data = ['nif' => $r['nif'] ?? '', 'name' => $r['nome'], 'kind' => strtoupper(substr($r['tipo'] ?? 'C', 0, 1)) ?: 'C',
                                'address' => $r['morada'] ?? null, 'city' => $r['cidade'] ?? null, 'country' => $r['pais'] ?? 'CV',
                                'email' => $r['email'] ?? null, 'phone' => $r['telefone'] ?? null, 'account' => $r['conta'] ?? ''];
                            if ($commit) {
                                Ledger::saveThirdParty($db, $data);
                            } elseif ($data['name'] === '') {
                                throw new UserError('Falta o nome.');
                            }
                            $n++;
                        } catch (\Throwable $e) {
                            $errors[] = ['line' => $r['_line'], 'message' => $e->getMessage()];
                        }
                    }
                    $result['imported'] = $n;
                    break;

                case 'saldos':
                    $year = (int) ($options['year'] ?? 0);
                    if (!$db->value('SELECT 1 FROM fiscal_years WHERE year = ?', [$year])) {
                        throw new UserError('Escolha um exercício existente para os saldos de abertura.');
                    }
                    $lines = [];
                    $d = 0;
                    $c = 0;
                    foreach ($rows as $r) {
                        try {
                            $acc = preg_replace('/\D/', '', $r['conta']) ?? '';
                            if (!Ledger::isMovementAccount($db, $acc)) {
                                throw new UserError("A conta {$acc} não existe ou não é de movimento.");
                            }
                            $dv = Money::toCents($r['debito']);
                            $cv = Money::toCents($r['credito']);
                            $net = $dv - $cv;
                            if ($net !== 0) {
                                $lines[] = ['account' => $acc, 'debit_cents' => max($net, 0), 'credit_cents' => max(-$net, 0)];
                                $d += max($net, 0);
                                $c += max(-$net, 0);
                            }
                        } catch (\Throwable $e) {
                            $errors[] = ['line' => $r['_line'], 'message' => $e->getMessage()];
                        }
                    }
                    if ($d !== $c) {
                        $errors[] = ['line' => 0, 'message' => 'Os saldos não estão equilibrados: débito ' . Money::format($d) . ', crédito ' . Money::format($c) . '.'];
                    }
                    $result['total_debit'] = $d;
                    $result['total_credit'] = $c;
                    if ($commit && !$errors) {
                        Ledger::saveEntry($db, ['date' => "{$year}-01-01", 'journal' => 'ABE', 'description' => "Saldos de abertura {$year} (importados)",
                            'source' => 'csv', 'lines' => $lines, 'validate' => true]);
                        $result['imported'] = 1;
                    }
                    break;

                case 'lancamentos':
                    $groups = [];
                    foreach ($rows as $r) {
                        $date = self::date($r['data']);
                        if (!$date) {
                            $errors[] = ['line' => $r['_line'], 'message' => "Data inválida: {$r['data']}"];
                            continue;
                        }
                        $key = ($r['lancamento'] ?? '') !== '' ? 'L' . $r['lancamento'] : $date . '|' . ($r['diario'] ?? '') . '|' . ($r['documento'] ?? '');
                        $groups[$key] ??= ['date' => $date, 'journal' => strtoupper(($r['diario'] ?? '') ?: 'DIV'), 'document' => $r['documento'] ?? '',
                            'description' => ($r['descricao'] ?? '') ?: (($r['documento'] ?? '') ?: 'Lançamento importado'), 'lines' => [], 'first_line' => $r['_line']];
                        try {
                            $tp = null;
                            $nif = preg_replace('/\D/', '', $r['nif'] ?? '') ?? '';
                            if ($nif !== '') {
                                $tp = $db->value('SELECT id FROM third_parties WHERE nif = ? ORDER BY id LIMIT 1', [$nif]);
                                if (!$tp) {
                                    throw new UserError("Não existe terceiro com o NIF {$nif}. Importe primeiro os terceiros.");
                                }
                            }
                            $groups[$key]['lines'][] = [
                                'account' => preg_replace('/\D/', '', $r['conta']) ?? '',
                                'debit_cents' => Money::toCents($r['debito']),
                                'credit_cents' => Money::toCents($r['credito']),
                                'third_party' => $tp ? (int) $tp : null,
                                'description' => $r['descricao_linha'] ?? null,
                                'vat_code' => strtoupper($r['codigo_iva'] ?? '') ?: null,
                                'vat_base_cents' => ($r['base_iva'] ?? '') !== '' ? Money::toCents($r['base_iva']) : null,
                            ];
                        } catch (\Throwable $e) {
                            $errors[] = ['line' => $r['_line'], 'message' => $e->getMessage()];
                        }
                    }
                    $n = 0;
                    $validate = !array_key_exists('validate', $options) || !empty($options['validate']);
                    foreach ($groups as $g) {
                        $d = array_sum(array_column($g['lines'], 'debit_cents'));
                        $c = array_sum(array_column($g['lines'], 'credit_cents'));
                        if ($d !== $c) {
                            $errors[] = ['line' => $g['first_line'], 'message' => "Lançamento \"{$g['description']}\" de {$g['date']} desequilibrado (débito " . Money::format($d) . ', crédito ' . Money::format($c) . ').'];
                            continue;
                        }
                        if (!$db->value('SELECT 1 FROM journals WHERE code = ?', [$g['journal']])) {
                            if ($commit) {
                                $db->insert('journals', ['code' => $g['journal'], 'name' => $g['journal'], 'kind' => 'normal']);
                            }
                        }
                        try {
                            foreach ($g['lines'] as $l) {
                                if (!Ledger::isMovementAccount($db, $l['account'])) {
                                    throw new UserError("A conta {$l['account']} não existe ou não é de movimento.");
                                }
                            }
                            if (!$db->value('SELECT 1 FROM fiscal_years WHERE year = ?', [(int) substr($g['date'], 0, 4)])) {
                                throw new UserError('Não existe o exercício ' . substr($g['date'], 0, 4) . '.');
                            }
                            if ($commit) {
                                Ledger::saveEntry($db, ['date' => $g['date'], 'journal' => $g['journal'], 'document' => $g['document'] ?: null,
                                    'description' => $g['description'], 'source' => 'csv', 'lines' => $g['lines'], 'validate' => $validate]);
                            }
                            $n++;
                        } catch (\Throwable $e) {
                            $errors[] = ['line' => $g['first_line'], 'message' => $e->getMessage()];
                        }
                    }
                    $result['entries'] = count($groups);
                    $result['imported'] = $n;
                    break;
            }
            if ($commit && $errors) {
                throw new ImportFailed($errors);
            }
        };

        if ($commit) {
            try {
                $db->transaction($run);
                $db->insert('import_log', ['source' => 'csv:' . $type, 'filename' => $filename,
                    'summary' => json_encode(['rows' => count($rows), 'imported' => $result['imported'] ?? 0], JSON_UNESCAPED_UNICODE), 'content' => gzencode($content, 6)]);
                $db->audit('importacao.csv', ['type' => $type, 'rows' => count($rows)]);
            } catch (ImportFailed $e) {
                $result['imported'] = 0;
                $result['committed'] = false;
                return $result;
            }
            $result['committed'] = true;
        } else {
            $run();
        }
        return $result;
    }
}
