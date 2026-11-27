<?php
declare(strict_types=1);

namespace Ideia;

/** Página A4 de um documento de venda, para imprimir ou guardar em PDF a partir do browser. */
final class SalesPrint
{
    public static function html(Db $db, int $id): string
    {
        $d = Sales::get($db, $id);
        $e = static fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $m = static fn($c) => Money::format((int) $c);
        $dmy = static fn($iso) => $iso ? substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4) : '';
        $info = Sales::TYPES[$d['type']];
        $company = [
            'name' => $db->meta('company_name'), 'nif' => $db->meta('company_nif'), 'address' => $db->meta('company_address'),
            'city' => $db->meta('company_city'), 'phone' => $db->meta('company_phone'), 'email' => $db->meta('company_email'),
            'bank' => $db->meta('company_bank'), 'footer' => $db->meta('sales_footer'),
        ];
        $logo = $db->meta('company_logo');
        $title = $d['type_name'] . ' ' . ($d['doc_no'] ?: '(rascunho)');
        $customer = $d['status'] === 'rascunho'
            ? ['name' => $d['party_name'] ?? $d['customer_name'], 'nif' => $d['party_nif'] ?? $d['customer_nif'], 'address' => trim(implode(', ', array_filter([$d['party_address'] ?? null, $d['party_city'] ?? null])))]
            : ['name' => $d['customer_name'], 'nif' => $d['customer_nif'], 'address' => $d['customer_address']];

        $rows = '';
        if ($d['type'] === 'RC') {
            foreach ($d['settlements'] as $s) {
                $rows .= "<tr><td>{$e($s['doc_no'])}</td><td>{$dmy($s['date'])}</td><td class=num>{$m($s['total_cents'])}</td><td class=num>{$m($s['amount_cents'])}</td></tr>";
            }
            $table = "<table class=lines><thead><tr><th>Documento</th><th>Data</th><th class=num>Valor do documento</th><th class=num>Valor recebido</th></tr></thead><tbody>$rows</tbody></table>";
        } else {
            $guide = in_array($d['type'], ['GE', 'GT'], true);
            foreach ($d['lines'] as $l) {
                $qty = rtrim(rtrim(number_format($l['qty_milli'] / 1000, 3, ',', ' '), '0'), ',');
                $disc = (int) $l['discount_bp'] ? rtrim(rtrim(number_format($l['discount_bp'] / 100, 2, ',', ''), '0'), ',') . '%' : '';
                $rate = rtrim(rtrim(number_format($l['vat_rate_bp'] / 100, 2, ',', ''), '0'), ',') . '%';
                $rows .= '<tr><td class=mono>' . $e($l['code']) . '</td><td>' . $e($l['description']) . '</td>'
                    . "<td class=num>{$qty}</td><td>{$e(Efatura::unitShort($l['unit']))}</td>"
                    . ($guide ? '' : "<td class=num>{$m($l['price_cents'])}</td><td class=num>{$disc}</td><td class=num>{$rate}</td><td class=num>{$m($l['net_cents'])}</td>")
                    . '</tr>';
            }
            $head = '<th>Código</th><th>Descrição</th><th class=num>Qtd.</th><th>Un.</th>' . ($guide ? '' : '<th class=num>Preço unit.</th><th class=num>Desc.</th><th class=num>IVA</th><th class=num>Valor</th>');
            $table = "<table class=lines><thead><tr>$head</tr></thead><tbody>$rows</tbody></table>";
        }

        $vat = '';
        foreach ($d['vat_summary'] as $v) {
            $rate = rtrim(rtrim(number_format($v['vat_rate_bp'] / 100, 2, ',', ''), '0'), ',') . '%';
            $vat .= "<tr><td>{$rate}</td><td class=num>{$m($v['base'])}</td><td class=num>{$m($v['vat'])}</td><td>{$e(self::exemption($v['exemption']))}</td></tr>";
        }
        $showTotals = !in_array($d['type'], ['GE', 'GT'], true);
        $totals = $showTotals ? ($d['type'] === 'RC'
            ? "<table class=totals><tr class=grand><td>Total recebido</td><td class=num>{$m($d['total_cents'])} ECV</td></tr></table>"
            : '<table class=totals>'
                . '<tr><td>N.º de itens</td><td class=num>' . count($d['lines']) . '</td></tr>'
                . ($d['discount_cents'] ? "<tr><td>Descontos</td><td class=num>{$m($d['discount_cents'])}</td></tr>" : '')
                . "<tr><td>Total sem IVA</td><td class=num>{$m($d['net_cents'])}</td></tr>"
                . "<tr><td>IVA</td><td class=num>{$m($d['vat_cents'])}</td></tr>"
                . "<tr class=grand><td>Total</td><td class=num>{$m($d['total_cents'])} ECV</td></tr></table>") : '';
        $vatTable = $showTotals && $vat !== '' && $d['type'] !== 'RC'
            ? "<table class=vat><thead><tr><th>Taxa IVA</th><th class=num>Base</th><th class=num>IVA</th><th>Motivo de isenção</th></tr></thead><tbody>$vat</tbody></table>" : '';

        $meta = [
            'Data' => $dmy($d['date']),
            $info['due_label'] ?: '' => $info['due_label'] ? $dmy($d['due_date']) : '',
            'Referência do cliente' => $d['reference'],
            'Documento de origem' => $d['origin']['doc_no'] ?? '',
            'Meio de pagamento' => $d['payment_method'] ? (Sales::PAYMENT_METHODS[$d['payment_method']] ?? '') : '',
            'Local de entrega' => $d['delivery_place'],
        ];
        $metaHtml = '';
        foreach ($meta as $k => $v) {
            if ($k !== '' && (string) $v !== '') {
                $metaHtml .= "<div><span>{$e($k)}</span><b>{$e($v)}</b></div>";
            }
        }
        $stamp = match (true) {
            $d['status'] === 'rascunho' => '<div class=stamp>RASCUNHO — sem valor</div>',
            $d['status'] === 'anulado' => '<div class=stamp>ANULADO</div>',
            default => '',
        };
        // e-Fatura: IUD e código QR (DFA); enquanto não autorizado, aviso de contingência (manual técnico, 6.13)
        $efatura = '';
        $contingency = '';
        $notice = '';
        if ($info['efatura'] && $d['status'] === 'emitido' && $d['iud']) {
            $qr = Qr::svg(Efatura::qrUrl($d['iud']), 3, 'Código QR do documento na e-Fatura');
            $efatura = "<div class=efatura>{$qr}<div><b>Consulta DFE em efatura.cv</b><br><span class=mono>{$e($d['iud'])}</span></div></div>";
            if ($d['efatura_status'] !== 'autorizado' && $d['efatura_status'] !== 'excluido') {
                $offline = (int) ($d['efatura_mode'] ?? 0) === 2 || Efatura::issueModeFor($d) === 2;
                $contingency = '<div class=contingency>' . ($offline ? 'EMITIDO EM CONTINGÊNCIA OFFLINE<br>' : '') . 'Pendente de Autorização</div>';
                $notice = '<p class=notice>Este documento não confere direito a dedução de imposto, enquanto o documento associado não for transmitido e autorizado pela Administração Fiscal.</p>';
            }
        } elseif ($info['efatura'] && $d['status'] === 'emitido' && $d['efatura_status'] === 'pendente') {
            $notice = '<p class=notice>Documento ainda não comunicado à DNRE (e-Fatura).</p>';
        }
        if (in_array($d['type'], ['OR', 'PF'], true)) {
            $notice .= '<p class=notice>Este documento não serve de fatura.</p>';
        }
        $logoHtml = $logo ? '<img class=logo src="' . $e($logo) . '" alt="">' : '';
        $hash = $d['hash'] ? substr($d['hash'], 0, 4) . substr($d['hash'], 10, 1) . substr($d['hash'], 20, 1) . substr($d['hash'], 30, 1) : '';

        return <<<HTML
<!doctype html>
<html lang="pt"><head><meta charset="utf-8"><title>{$e($title)}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @page { size: A4; margin: 14mm; }
  * { box-sizing: border-box; }
  body { font: 12px/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1c2430; margin: 0; background: #eef1f5; }
  .page { background: #fff; max-width: 210mm; min-height: 270mm; margin: 16px auto; padding: 14mm; box-shadow: 0 4px 24px rgba(0,0,0,.08); position: relative; }
  .bar { max-width: 210mm; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
  .bar button { font: inherit; font-size: 14px; padding: 8px 16px; border-radius: 6px; border: 1px solid #c8d0da; background: #fff; cursor: pointer; }
  .bar button.primary { background: #0f5ea8; color: #fff; border-color: #0f5ea8; }
  header { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; }
  .logo { max-height: 60px; max-width: 200px; display: block; margin-bottom: 8px; }
  .company b { font-size: 16px; }
  .doc { text-align: right; }
  .doc h1 { font-size: 22px; margin: 0; }
  .doc .no { font-size: 16px; font-weight: 600; }
  .parties { display: flex; justify-content: space-between; gap: 24px; margin: 24px 0 16px; }
  .box { border: 1px solid #d6dce4; border-radius: 6px; padding: 10px 12px; min-width: 45%; }
  .box small { color: #5b6675; text-transform: uppercase; letter-spacing: .04em; font-size: 10px; }
  .meta { display: flex; flex-wrap: wrap; gap: 6px 24px; margin-bottom: 14px; }
  .meta span { color: #5b6675; margin-right: 6px; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; color: #5b6675; border-bottom: 1.5px solid #1c2430; padding: 6px 4px; }
  td { padding: 6px 4px; border-bottom: 1px solid #e3e7ec; vertical-align: top; }
  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; }
  .bottom { display: flex; justify-content: space-between; gap: 24px; margin-top: 16px; align-items: flex-start; }
  .vat { width: 55%; } .totals { width: 40%; }
  .totals td { border: 0; padding: 3px 4px; } .totals .grand td { font-size: 16px; font-weight: 700; border-top: 1.5px solid #1c2430; padding-top: 8px; }
  .notes { margin-top: 18px; white-space: pre-wrap; }
  .notice { color: #5b6675; font-size: 11px; margin: 4px 0; }
  .efatura { display: flex; gap: 12px; align-items: center; margin-top: 16px; font-size: 11px; }
  .efatura svg { width: 96px; height: 96px; flex: none; }
  .efatura .mono { font-size: 10px; word-break: break-all; }
  .contingency { text-align: center; font-weight: 700; letter-spacing: .03em; margin: 0 0 12px; padding: 6px; border: 1.5px dashed #b54708; color: #b54708; border-radius: 6px; }
  footer { margin-top: 28px; padding-top: 8px; border-top: 1px solid #e3e7ec; color: #5b6675; font-size: 10px; display: flex; justify-content: space-between; gap: 16px; }
  .stamp { position: absolute; top: 45%; left: 50%; transform: translate(-50%,-50%) rotate(-18deg); font-size: 46px; font-weight: 800; color: rgba(200,40,40,.18); border: 6px solid rgba(200,40,40,.18); padding: 8px 24px; border-radius: 12px; white-space: nowrap; pointer-events: none; }
  @media print { body { background: #fff; } .page { box-shadow: none; margin: 0; padding: 0; max-width: none; min-height: 0; } .bar { display: none; } }
  @media (max-width: 700px) { .page { padding: 16px; margin: 8px; } header, .parties, .bottom { flex-direction: column; } .vat, .totals, .box { width: 100%; } .doc { text-align: left; } .lines { font-size: 11px; } }
</style></head>
<body>
<div class="bar"><button onclick="window.close()">Fechar</button><button class="primary" onclick="window.print()">Imprimir / guardar PDF</button></div>
<div class="page">
  $stamp
  <header>
    <div class="company">$logoHtml<b>{$e($company['name'])}</b><br>NIF {$e($company['nif'])}<br>{$e($company['address'])}<br>{$e($company['city'])}
      <br>{$e(implode(' · ', array_filter([$company['phone'], $company['email']])))}</div>
    <div class="doc"><h1>{$e($d['type_name'])}</h1><div class="no">{$e($d['doc_no'] ?: 'Rascunho')}</div><div>Original</div></div>
  </header>
  $contingency
  <div class="parties">
    <div class="box"><small>Cliente</small><br><b>{$e($customer['name'] ?: 'Consumidor final')}</b><br>NIF {$e($customer['nif'] ?: '—')}<br>{$e($customer['address'])}</div>
    <div class="meta" style="flex-direction:column;align-items:flex-end">$metaHtml</div>
  </div>
  $table
  <div class="bottom">$vatTable $totals</div>
  <div class="notes">{$e($d['notes'])}</div>
  $efatura
  $notice
  <footer><div>{$e($company['footer'])}{$e($company['bank'] ? ' · IBAN/NIB ' . $company['bank'] : '')}</div><div>{$e($hash ? $hash . ' · ' : '')}Processado por IDEIA Contabilidade</div></footer>
</div>
</body></html>
HTML;
    }

    /** Motivo de isenção para impressão: código oficial e menção obrigatória. */
    private static function exemption(?string $value): string
    {
        $code = Efatura::exemptionCode($value);
        return $code ? $code . ' — ' . Efatura::codes()['exemptions'][$code][1] : (string) $value;
    }

    /** Extrato de conta corrente e faturas por pagar de um cliente, para imprimir ou enviar em PDF. */
    public static function statement(Db $db, int $party, int $year, string $from, string $to, string $account = ''): string
    {
        $st = Reports::partyStatement($db, $party, $year, $from, $to, $account);
        $cu = $st['party'];
        $isSupplier = $cu['kind'] === 'F';
        $c = $isSupplier ? ['open_docs' => [], 'open' => 0, 'overdue' => 0] : Sales::customer($db, $party, $year);
        $e = static fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $m = static fn($v) => Money::format((int) $v);
        $dmy = static fn($iso) => $iso ? substr($iso, 8, 2) . '/' . substr($iso, 5, 2) . '/' . substr($iso, 0, 4) : '';
        $logo = $db->meta('company_logo');
        $rows = "<tr><td>{$dmy($from)}</td><td colspan=3>Saldo anterior</td><td></td><td></td><td class=num>{$m($st['opening'])}</td></tr>";
        foreach ($st['rows'] as $r) {
            $rows .= "<tr><td>{$dmy($r['date'])}</td><td>{$e($r['document'] ?: $r['journal'] . '/' . $r['number'])}</td><td>{$e($r['description'] ?: $r['entry_description'])}</td><td>{$e($r['account'])}</td>"
                . '<td class=num>' . ((int) $r['debit_cents'] ? $m($r['debit_cents']) : '') . '</td><td class=num>' . ((int) $r['credit_cents'] ? $m($r['credit_cents']) : '') . "</td><td class=num>{$m($r['balance'])}</td></tr>";
        }
        $open = '';
        foreach ($c['open_docs'] as $d) {
            $late = $d['days_overdue'] > 0 ? $d['days_overdue'] . ' dias' : 'a vencer';
            $open .= "<tr><td>{$e($d['doc_no'])}</td><td>{$dmy($d['date'])}</td><td>{$dmy($d['due_date'])}</td><td class=num>{$m($d['total_cents'])}</td><td class=num>{$m($d['open_cents'])}</td><td>{$late}</td></tr>";
        }
        $openTable = $open !== ''
            ? "<h2>Faturas por pagar</h2><table><thead><tr><th>Documento</th><th>Data</th><th>Vencimento</th><th class=num>Total</th><th class=num>Em dívida</th><th>Atraso</th></tr></thead><tbody>$open</tbody>"
              . "<tfoot><tr><td colspan=4><b>Total em dívida</b></td><td class=num><b>{$m($c['open'])}</b></td><td></td></tr></tfoot></table>"
            : ($isSupplier ? '' : '<p>Não há faturas por pagar.</p>');
        $logoHtml = $logo ? '<img class=logo src="' . $e($logo) . '" alt="">' : '';
        $company = $e($db->meta('company_name')) . '<br>NIF ' . $e($db->meta('company_nif')) . '<br>' . $e($db->meta('company_address')) . ' ' . $e($db->meta('company_city'))
            . '<br>' . $e(implode(' · ', array_filter([$db->meta('company_phone'), $db->meta('company_email')])));
        $bank = !$isSupplier && $db->meta('company_bank') ? '<p>Pagamento por transferência: IBAN/NIB ' . $e($db->meta('company_bank')) . '</p>' : '';
        $today = $dmy(date('Y-m-d'));
        return <<<HTML
<!doctype html>
<html lang="pt"><head><meta charset="utf-8"><title>Extrato {$e($cu['name'])}</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  @page { size: A4; margin: 14mm; }
  body { font: 12px/1.45 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #1c2430; margin: 0; background: #eef1f5; }
  .page { background: #fff; max-width: 210mm; margin: 16px auto; padding: 14mm; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
  .bar { max-width: 210mm; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
  .bar button { font: inherit; font-size: 14px; padding: 8px 16px; border-radius: 6px; border: 1px solid #c8d0da; background: #fff; cursor: pointer; }
  .bar button.primary { background: #0f5ea8; color: #fff; border-color: #0f5ea8; }
  header { display: flex; justify-content: space-between; gap: 24px; }
  .logo { max-height: 60px; max-width: 200px; display: block; margin-bottom: 8px; }
  h1 { font-size: 20px; margin: 0; text-align: right; } h2 { font-size: 14px; margin: 24px 0 8px; }
  .box { border: 1px solid #d6dce4; border-radius: 6px; padding: 10px 12px; margin: 20px 0; display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
  table { width: 100%; border-collapse: collapse; }
  th { text-align: left; font-size: 10px; text-transform: uppercase; color: #5b6675; border-bottom: 1.5px solid #1c2430; padding: 6px 4px; }
  td { padding: 5px 4px; border-bottom: 1px solid #e3e7ec; }
  tfoot td { border-top: 1.5px solid #1c2430; border-bottom: 0; }
  .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
  .big { font-size: 18px; font-weight: 700; }
  footer { margin-top: 24px; color: #5b6675; font-size: 10px; }
  @media print { body { background: #fff; } .page { box-shadow: none; margin: 0; padding: 0; max-width: none; } .bar { display: none; } }
</style></head><body>
<div class="bar"><button onclick="window.close()">Fechar</button><button class="primary" onclick="window.print()">Imprimir / guardar PDF</button></div>
<div class="page">
  <header><div>$logoHtml<b>$company</b></div><div><h1>Extrato de conta corrente</h1><div style="text-align:right">{$dmy($from)} a {$dmy($to)}</div></div></header>
  <div class="box"><div><small>{$e($isSupplier ? 'FORNECEDOR' : 'CLIENTE')}</small><br><b>{$e($cu['name'])}</b><br>NIF {$e($cu['nif'] ?: '—')}<br>{$e(trim(($cu['address'] ?? '') . ' ' . ($cu['city'] ?? '')))}</div>
    <div style="text-align:right">Saldo em {$dmy($to)}<br><span class="big">{$m($st['closing'])} ECV</span><br>{$e($isSupplier ? ($st['closing'] >= 0 ? 'a pagar ao fornecedor' : 'a favor da empresa') : ($c['overdue'] ? 'dos quais vencido: ' . $m($c['overdue']) : ($st['closing'] >= 0 ? 'a receber do cliente' : 'a favor do cliente')))}</div></div>
  <h2>Movimentos</h2>
  <table><thead><tr><th>Data</th><th>Documento</th><th>Descrição</th><th>Conta</th><th class=num>Débito</th><th class=num>Crédito</th><th class=num>Saldo</th></tr></thead>
    <tbody>$rows</tbody><tfoot><tr><td colspan=4><b>Totais do período</b></td><td class=num>{$m($st['debit'])}</td><td class=num>{$m($st['credit'])}</td><td class=num><b>{$m($st['closing'])}</b></td></tr></tfoot></table>
  $openTable
  $bank
  <footer>Extrato emitido em $today a partir da contabilidade (movimentos validados).{$e($isSupplier ? '' : ' Se já efetuou o pagamento, por favor ignore os valores em dívida.')} Processado por IDEIA Contabilidade.</footer>
</div></body></html>
HTML;
    }
}
