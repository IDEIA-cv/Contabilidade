<?php
declare(strict_types=1);

namespace Ideia;

/**
 * Exportação de um conjunto de documentos (vendas ou compras) para um ZIP: os documentos escolhidos à mão (ids)
 * ou todos os que cumprem os filtros da lista.
 *
 * Vendas: cada documento em HTML pronto a imprimir (o mesmo da impressão), o XML da e-Fatura quando tem IUD,
 * e as listas documentos.csv e linhas.csv. Compras: os anexos do lançamento de cada documento e as mesmas listas.
 */
final class DocExport
{
    public const MAX_DOCS = 1000;

    /** @return array{0:string,1:string,2:int} conteúdo do ZIP, nome do ficheiro e número de documentos */
    public static function sales(Db $db, array $f): array
    {
        $ids = self::ids($db, 'sales_docs', $f, static fn() => Sales::list($db, ['limit' => self::MAX_DOCS + 1] + $f)['rows']);
        $files = [];
        $docs = [['Tipo', 'Documento', 'Data', 'Vencimento', 'Cliente', 'NIF', 'Estado', 'Líquido', 'IVA', 'Total', 'Estado e-Fatura', 'IUD', 'Ficheiro']];
        $lines = [['Documento', 'Linha', 'Descrição', 'Quantidade', 'Preço', 'Desconto %', 'Taxa IVA %', 'Líquido', 'IVA']];
        foreach ($ids as $id) {
            $d = Sales::get($db, $id);
            $name = self::name($d['doc_no'] ?: 'rascunho-' . $d['id']);
            $dir = self::name(Sales::TYPES[$d['type']]['plural']);
            $html = preg_replace('#<div class="bar">.*?</div>#s', '', SalesPrint::html($db, $id), 1);
            $files["$dir/$name.html"] = $html;
            if (!empty($d['iud']) && $d['status'] !== 'rascunho') {
                try {
                    $files["$dir/$name.xml"] = Efatura::buildXml($db, $id);
                } catch (\Throwable) {
                    // dados em falta: o documento segue sem o XML (o estado aparece na lista)
                }
            }
            $docs[] = [$d['type_name'], $d['doc_no'] ?: '(rascunho)', $d['date'], $d['due_date'] ?? '', $d['customer_name'] ?? '', $d['customer_nif'] ?? '',
                $d['status'], self::m($d['net_cents']), self::m($d['vat_cents']), self::m($d['total_cents'], $d['type'] === 'NC'),
                $d['efatura_status'] ?? '', $d['iud'] ?? '', "$dir/$name.html"];
            foreach ($d['lines'] as $i => $l) {
                $lines[] = [$d['doc_no'] ?: '(rascunho)', $i + 1, $l['description'], self::qty($l['qty_milli']), self::m($l['price_cents']),
                    self::pct($l['discount_bp'] ?? 0), self::pct($l['vat_rate_bp'] ?? 0), self::m($l['net_cents']), self::m($l['vat_cents'])];
            }
        }
        $files['documentos.csv'] = self::csv($docs);
        $files['linhas.csv'] = self::csv($lines);
        $files['LEIA-ME.txt'] = self::readme($db, count($ids), 'vendas',
            "Cada pasta tem os documentos em HTML: abra no navegador e use Imprimir / Guardar como PDF.\r\nOs documentos com IUD têm também o XML da e-Fatura (sem assinatura).");
        $db->audit('documentos.exportados', ['tipo' => 'vendas', 'n' => count($ids)]);
        return [self::zip($files), self::zipName($db, 'vendas'), count($ids)];
    }

    /** @return array{0:string,1:string,2:int} */
    public static function purchases(Db $db, array $f): array
    {
        $ids = self::ids($db, 'purchase_docs', $f, static fn() => Purchases::list($db, ['limit' => self::MAX_DOCS + 1] + $f)['rows']);
        $files = [];
        $docs = [['Tipo', 'Registo', 'Documento do fornecedor', 'Data', 'Vencimento', 'Fornecedor', 'NIF', 'Estado', 'Líquido', 'IVA', 'Total', 'IUD', 'Anexos']];
        $lines = [['Registo', 'Documento do fornecedor', 'Linha', 'Descrição', 'Conta', 'Código IVA', 'Quantidade', 'Preço', 'Líquido', 'IVA']];
        foreach ($ids as $id) {
            $d = Purchases::get($db, $id);
            $label = $d['doc_no'] ?: 'rascunho-' . $d['id'];
            $dir = self::name(Purchases::TYPES[$d['type']]['plural']);
            $att = [];
            if ($d['entry_id']) {
                foreach ($db->all('SELECT filename, content FROM entry_attachments WHERE entry_id = ? ORDER BY id', [$d['entry_id']]) as $a) {
                    $path = "$dir/" . self::name($label . ' - ' . $a['filename']);
                    $files[$path] = (string) $a['content'];
                    $att[] = $path;
                }
            }
            $docs[] = [$d['type_name'], $d['doc_no'] ?: '(rascunho)', $d['supplier_doc'] ?? '', $d['date'], $d['due_date'] ?? '', $d['supplier'] ?? '', $d['supplier_nif'] ?? '',
                $d['status'], self::m($d['net_cents'] ?? 0), self::m($d['vat_cents'] ?? 0), self::m($d['total_cents'], $d['type'] === 'NF'), $d['iud'] ?? '', implode(' | ', $att)];
            foreach ($d['lines'] as $i => $l) {
                $lines[] = [$d['doc_no'] ?: '(rascunho)', $d['supplier_doc'] ?? '', $i + 1, $l['description'], $l['account'], $l['vat_code'],
                    self::qty($l['qty_milli']), self::m($l['price_cents']), self::m($l['net_cents']), self::m($l['vat_cents'])];
            }
        }
        $files['documentos.csv'] = self::csv($docs);
        $files['linhas.csv'] = self::csv($lines);
        $files['LEIA-ME.txt'] = self::readme($db, count($ids), 'compras', 'As pastas têm os anexos (PDF, imagens) guardados nos lançamentos de cada documento.');
        $db->audit('documentos.exportados', ['tipo' => 'compras', 'n' => count($ids)]);
        return [self::zip($files), self::zipName($db, 'compras'), count($ids)];
    }

    /** Ids escolhidos (ids=1,2,3) ou todos os que cumprem os filtros. */
    private static function ids(Db $db, string $table, array $f, callable $filtered): array
    {
        $raw = $f['ids'] ?? '';
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw)))));
        if ($ids) {
            if (count($ids) > self::MAX_DOCS) {
                throw new UserError('Selecione no máximo ' . self::MAX_DOCS . ' documentos de cada vez.');
            }
            $in = implode(',', $ids);
            $ids = array_column($db->all("SELECT id FROM $table WHERE id IN ($in) ORDER BY date, id"), 'id');
        } else {
            $rows = $filtered();
            if (count($rows) > self::MAX_DOCS) {
                throw new UserError('Os filtros dão mais de ' . self::MAX_DOCS . ' documentos. Restrinja as datas ou o tipo.');
            }
            usort($rows, static fn($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        }
        if (!$ids) {
            throw new UserError('Não há documentos para exportar.');
        }
        return array_map('intval', $ids);
    }

    private static function name(string $s): string
    {
        $s = trim(preg_replace('/[\/\\\\:*?"<>|\x00-\x1f]+/u', '-', $s) ?? '');
        return mb_substr($s !== '' ? $s : 'documento', 0, 120);
    }

    private static function m(mixed $cents, bool $negative = false): string
    {
        $v = (int) $cents * ($negative ? -1 : 1);
        return number_format($v / 100, 2, ',', '');
    }

    private static function qty(mixed $milli): string
    {
        return rtrim(rtrim(number_format((int) $milli / 1000, 3, ',', ''), '0'), ',');
    }

    private static function pct(mixed $bp): string
    {
        return rtrim(rtrim(number_format((int) $bp / 100, 2, ',', ''), '0'), ',');
    }

    /** CSV para o Excel em português: separador ponto e vírgula, UTF-8 com BOM. */
    private static function csv(array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        foreach ($rows as $r) {
            fputcsv($h, array_map(static fn($v) => (string) $v, $r), ';', '"', '');
        }
        rewind($h);
        $out = "\xEF\xBB\xBF" . stream_get_contents($h);
        fclose($h);
        return str_replace("\n", "\r\n", $out);
    }

    private static function readme(Db $db, int $n, string $kind, string $extra): string
    {
        return 'IDEIA Contabilidade ' . IDEIA_VERSION . "\r\n" . (string) $db->meta('company_name') . ' (NIF ' . (string) $db->meta('company_nif') . ")\r\n"
            . "Exportação de $n documento(s) de $kind em " . date('d/m/Y H:i') . "\r\n\r\n"
            . "documentos.csv: um documento por linha. linhas.csv: as linhas de cada documento. Abrem no Excel.\r\n" . $extra . "\r\n";
    }

    private static function zipName(Db $db, string $kind): string
    {
        return "documentos_{$kind}_" . preg_replace('/\D/', '', (string) $db->meta('company_nif')) . '_' . date('Ymd_His') . '.zip';
    }

    private static function zip(array $files): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new UserError('A extensão zip do PHP não está ativa (php.ini: extension=zip).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'exp');
        $z = new \ZipArchive();
        $z->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $z->addFromString($name, $content);
        }
        $z->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }
}
