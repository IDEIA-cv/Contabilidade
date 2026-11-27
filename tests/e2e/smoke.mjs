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

await browser.close();
if (errors.length) {
  console.error('\nERROS:\n' + errors.join('\n'));
  process.exit(1);
}
console.log('\nSem erros na consola.');
