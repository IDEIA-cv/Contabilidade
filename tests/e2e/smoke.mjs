// Teste de fumo no browser (Playwright): percorre todos os ecrãs, cria uma empresa e um lançamento, e falha se houver erros na consola.
// Uso: BASE=http://127.0.0.1:8765 COMPANY=empresa-demonstracao-lda node tests/e2e/smoke.mjs
import { createRequire } from 'node:module';
const { chromium } = createRequire(import.meta.url)('playwright');
import { mkdirSync, readFileSync } from 'node:fs';

const BASE = process.env.BASE || 'http://127.0.0.1:8765';
const COMPANY = process.env.COMPANY || 'empresa-demonstracao-lda';
const OUT = new URL('./output/', import.meta.url).pathname;
mkdirSync(OUT, { recursive: true });

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1360, height: 900 } });
const errors = [];
page.on('console', (m) => { if (m.type() === 'error') errors.push(`[console] ${m.text()}`); });
page.on('pageerror', (e) => errors.push(`[pageerror] ${e.message}`));
page.on('response', (r) => { if (r.url().includes('api.php') && r.status() >= 500) errors.push(`[http ${r.status()}] ${r.url()}`); });

async function visit(hash, name, waitFor = '.content h1') {
  await page.goto(`${BASE}/?r=${Date.now()}#/${hash}`);
  await page.waitForSelector(waitFor, { timeout: 10000 });
  await page.waitForLoadState('networkidle');
  const alert = await page.$('.content .alert.bad');
  if (alert) errors.push(`[${name}] ${await alert.innerText()}`);
  await page.screenshot({ path: `${OUT}${name}.png`, fullPage: true });
  console.log('ok', name);
}

const c = `c/${COMPANY}`;
await visit('', '01-empresas');
await visit(`${c}/painel`, '02-painel', '.kpi');
await visit(`${c}/lancamentos`, '03-lancamentos', 'tbody#rows tr');
await visit(`${c}/contas`, '04-contas');
await visit(`${c}/terceiros`, '05-terceiros');
for (const r of ['balancete', 'razao?account=121', 'diario', 'balanco', 'dr']) await visit(`${c}/relatorios/${r}`, `06-relatorio-${r.split('?')[0]}`);
await visit(`${c}/iva?from=1&to=3`, '07-iva');
for (const t of ['racios', 'gastos', 'terceiros', 'orcamento', 'previsao', 'indicadores', 'relatorio']) await visit(`${c}/estatisticas/${t}`, `08-stats-${t}`);
for (const t of ['pendentes', 'conciliados', 'mapa', 'extratos']) await visit(`${c}/reconciliacao?account=121&tab=${t}`, `09-reconciliacao-${t}`, '#tab .card, #tab .alert, #tab .kpis');
await visit(`${c}/importar`, '09-importar');
await visit(`${c}/exercicios`, '10-exercicios');
await visit(`${c}/empresa`, '11-empresa');

// fluxo: criar empresa nova e um lançamento validado
await page.goto(`${BASE}/#/`);
await page.click('#create');
const name = 'Teste E2E ' + Date.now();
await page.fill('.modal [name=name]', name);
await page.fill('.modal [name=nif]', '123456789');
await page.click('.modal button.primary');
await page.waitForSelector('.kpi');
await page.goto(page.url().replace('/painel', '/lancamento/novo'));
await page.waitForSelector('#lines tr');
await page.fill('#head [name=description]', 'Realização de capital (teste)');
const rows = await page.$$('#lines tr');
await rows[0].$eval('[name=account]', (i, v) => { i.value = v; i.dispatchEvent(new Event('input')); }, '121');
await (await rows[0].$('[name=debit]')).fill('50.000,00');
await rows[1].$eval('[name=account]', (i, v) => { i.value = v; i.dispatchEvent(new Event('input')); }, '51');
await (await rows[1].$('[name=credit]')).fill('50.000,00');
await page.screenshot({ path: `${OUT}12-editor.png`, fullPage: true });
await page.click('#validate');
const t = page.locator('.toast.good', { hasText: 'Lançamento validado' });
await t.waitFor({ timeout: 10000 });
console.log('ok', '12-lancamento-validado:', await t.innerText());
const cNew = page.url().split('#/')[1].split('/lancamento')[0];

// anexar um documento ao lançamento validado
await visit(`${cNew}/lancamentos`, '13-lancamentos-nova', 'tbody#rows tr[data-id]');
await page.click('tbody#rows tr[data-id]');
await page.waitForSelector('#att-file', { state: 'attached' });
await page.setInputFiles('#att-file', { name: 'fatura-teste.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4 teste e2e') });
await page.waitForSelector('#att-list a[target=_blank]');
console.log('ok', '14-anexo:', await page.innerText('#att-list a[target=_blank]'));
await page.screenshot({ path: `${OUT}14-anexo.png`, fullPage: true });

// reconciliação: importar extrato, conciliação automática do depósito e lançamento da comissão
await visit(`${cNew}/reconciliacao?account=121`, '17-reconciliacao-vazia', '#tab .alert');
await page.click('#import');
const today = new Date();
const d = (n) => { const x = new Date(today); x.setDate(x.getDate() + n); return x.toLocaleDateString('pt-PT'); };
const extrato = `Data;Descritivo;Débito;Crédito;Saldo\n${d(0)};Depósito capital;;50.000,00;50.000,00\n${d(0)};Comissão;150,00;;49.850,00\n`;
await page.setInputFiles('#bank-file', { name: 'extrato.csv', mimeType: 'text/csv', buffer: Buffer.from(extrato) });
await page.waitForSelector('#bank-prev .alert.good');
await page.click('.modal button.primary');
await page.waitForSelector('#tb-bank input[data-bank]');
await page.check('#tb-bank input[data-bank]');
await page.click('#create');
await page.fill('.modal [name=counterpart]', '698');
await page.click('.modal button.primary');
await page.waitForSelector('.toast.good >> text=conciliado');
await page.waitForSelector('#tb-bank .empty');
const diff = await page.innerText('.kpi:nth-child(4) .value');
if (diff.trim() !== '0,00') errors.push(`[reconciliação] diferença por explicar ${diff}`);
await page.screenshot({ path: `${OUT}17-reconciliacao.png`, fullPage: true });
console.log('ok', '17-reconciliacao: diferença', diff);

// exportar o balancete para Excel
await visit(`${cNew}/relatorios/balancete`, '15-balancete-nova', '#out table');
const [dl] = await Promise.all([page.waitForEvent('download'), page.click('#xlsx')]);
const xlsxPath = `${OUT}balancete.xlsx`;
await dl.saveAs(xlsxPath);
const head = readFileSync(xlsxPath).subarray(0, 2).toString();
if (head !== 'PK') errors.push('[xlsx] ficheiro exportado não é um ZIP/XLSX');
console.log('ok', '16-excel:', dl.suggestedFilename());

// vendas: ecrãs da empresa de demonstração
await visit(`${c}/vendas`, '18-vendas', 'table#list tbody tr[data-id]');
await visit(`${c}/vendas?pay=aberto`, '18-vendas-por-receber', 'table#list');
await visit(`${c}/artigos`, '19-artigos', '#rows tr[data-id]');
await visit(`${c}/dividas`, '19-dividas', 'table#debtors tbody tr');
await visit(`${c}/cliente/1`, '19-cliente', '#t-open');
await visit(`${c}/terceiro/6?tab=extrato`, '19-fornecedor-extrato', '#t-st tbody tr');

// compras: lista, dívidas a fornecedores, mapa de pagamentos, ficha do fornecedor, registo e pagamento
await visit(`${c}/compras`, '19-compras', 'table#list tbody tr[data-id]');
await visit(`${c}/dividas-fornecedores`, '19-dividas-fornecedores', 'table#creditors tbody tr');
await visit(`${c}/pagamentos`, '19-mapa-pagamentos', 'table#sched');
await visit(`${c}/terceiro/6`, '19-fornecedor-faturas', '#t-open');
await visit(`${c}/compras/novo?type=FC`, '19-compra-nova', '#lines tr');
await page.fill('[name=supplier]', await page.$eval('#dl-sups option', (o) => o.value));
await page.dispatchEvent('[name=supplier]', 'change');
await page.fill('[name=supplier_doc]', 'E2E-' + Date.now());
await page.fill('[name=date]', '2025-12-01');
await page.fill('#lines tr [name=description]', 'Compra de teste');
await page.fill('#lines tr [name=account]', '6268 — Outros serviços');
await page.fill('#lines tr [name=price]', '1.000,00');
await page.click('#register');
await page.click('.modal button.primary');
await page.waitForSelector('#pay');
await page.click('#pay');
await page.fill('.modal [name=date]', '2025-12-02');
await page.click('.modal button.primary');
await page.waitForSelector('.badge.good >> text=Pago');
await page.screenshot({ path: `${OUT}19-compra-paga.png`, fullPage: true });
console.log('ok', '19-compra-registada-e-paga');
// e-Fatura: fila (verificação com o XSD), configuração e documentação oficial
await visit(`${c}/efatura?status=`, '20-efatura', '#ef-docs tbody tr[data-id]');
await page.click('[data-check]');
await page.waitForSelector('#out .alert');
if (!(await page.innerText('#out')).includes('cumpre o XSD')) errors.push('[efatura] XML não cumpre o XSD: ' + (await page.innerText('#out')));
await visit(`${c}/efatura/configurar`, '20-efatura-config', '#cfg [name=led]');
await visit(`${c}/efatura/documentacao`, '20-efatura-docs', '.steps');
{
  const href = await page.$eval('.doc-list a[href*="efatura.doc"]', (a) => a.href);
  const r = await page.request.get(href);
  if (r.status() !== 200 || !(r.headers()['content-type'] || '').includes('pdf')) errors.push('[efatura] manual não descarrega');
}
await visit(`${c}/cliente/1?tab=extrato&from=2025-01-01&to=2025-12-31`, '19-cliente-extrato', '#t-st');
{
  const [pop] = await Promise.all([page.waitForEvent('popup'), page.click('#print-st')]);
  await pop.waitForLoadState();
  if (!(await pop.innerText('body')).includes('Extrato de conta corrente')) errors.push('[extrato] impressão sem título');
  await pop.screenshot({ path: `${OUT}19-extrato-impressao.png`, fullPage: true });
  await pop.close();
}

// vendas na empresa nova: artigo, cliente, fatura emitida, pagamento e impressão
await visit(`${cNew}/artigos`, '20-artigos-nova', '#rows');
await page.click('#fams');
await page.fill('.modal [name=name]', 'Serviços de teste');
await page.selectOption('.modal #fam-form [name=kind]', 'S');
await page.click('#fam-save');
await page.waitForSelector('#fam-rows >> text=Serviços de teste');
await page.click('.modal footer button');
await page.waitForSelector('#family >> text=Serviços de teste', { state: 'attached' });
await page.click('#new');
await page.selectOption('.modal [name=family_id]', { label: 'Serviços de teste' });
await page.fill('.modal [name=name]', 'Serviço de teste');
await page.selectOption('.modal [name=kind]', 'S');
await page.fill('.modal [name=price_vat]', '1.150,00');
await page.click('.modal button.primary');
await page.waitForSelector('#rows tr[data-id]');
await visit(`${cNew}/vendas/novo?type=FT`, '21-fatura-nova', '#lines tr');
await page.click('#new-cust');
await page.fill('.modal [name=name]', 'Cliente Teste E2E');
await page.fill('.modal [name=nif]', '299999999');
await page.click('.modal button.primary');
await page.waitForSelector('.toast.good >> text=Cliente guardado');
await page.fill('#lines tr [name=item]', 'S0001 — Serviço de teste');
await page.dispatchEvent('#lines tr [name=item]', 'change');
await page.fill('#lines tr [name=qty]', '2');
await page.dispatchEvent('#lines tr [name=qty]', 'input');
const total = (await page.innerText('#totals .grand td.num')).trim();
if (total !== '2 300,00 ECV') errors.push(`[fatura] total calculado ${total}`);
await page.click('#issue');
await page.click('.modal button.primary');
await page.waitForSelector('.page-head h1 >> text=FT ');
await page.screenshot({ path: `${OUT}22-fatura-emitida.png`, fullPage: true });
console.log('ok', '22-fatura-emitida', await page.innerText('.page-head h1'));
await page.click('#pay');
await page.click('.modal button.primary');
await page.waitForSelector('.badge.good >> text=Pago');
const [popup] = await Promise.all([page.waitForEvent('popup'), page.click('#print')]);
await popup.waitForLoadState();
const printed = await popup.innerText('body');
if (!printed.includes('Cliente Teste E2E') || !printed.includes('2 300,00')) errors.push('[impressão] documento sem cliente ou total');
await popup.screenshot({ path: `${OUT}23-fatura-impressao.png`, fullPage: true });
await popup.close();
console.log('ok', '23-impressao');

await browser.close();
if (errors.length) {
  console.error('\nERROS:\n' + errors.join('\n'));
  process.exit(1);
}
console.log('\nSem erros na consola.');
