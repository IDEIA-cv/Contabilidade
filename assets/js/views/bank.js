// Reconciliação bancária: importar extratos, conciliar (automático e manual), mapa de reconciliação.
import { api } from '../api.js';
import { state, go, cpath, setCrumbs, refreshInfo } from '../main.js';
import { esc, money, fmtDate, toast, modal, confirmDialog, signClass } from '../util.js';
import { tableToXlsx } from '../xlsx.js';

const TABS = [['pendentes', 'Por conciliar'], ['conciliados', 'Conciliados'], ['mapa', 'Mapa de reconciliação'], ['extratos', 'Extratos importados']];

export async function render({ view, query }) {
  setCrumbs('Reconciliação bancária');
  const accounts = await api.get('bank.accounts');
  if (!accounts.length) {
    view.innerHTML = '<div class="page-head"><div><h1>Reconciliação bancária</h1></div></div><div class="alert info">Não há contas de depósitos de movimento (12x). Crie uma em Plano de contas, por exemplo 121 — Banco A.</div>';
    return;
  }
  const account = accounts.some((a) => a.code === query.account) ? query.account : (accounts.find((a) => a.bank_lines > 0) || accounts[0]).code;
  const tab = TABS.some(([k]) => k === query.tab) ? query.tab : 'pendentes';
  const link = (p) => cpath(`reconciliacao?${new URLSearchParams({ account, tab, ...p })}`);

  view.innerHTML = `
    <div class="page-head"><div><h1>Reconciliação bancária</h1><p class="muted">Compare o extrato do banco com os movimentos da contabilidade e encontre o que falta de cada lado.</p></div>
      <div class="toolbar"><label>Conta<select id="acc">${accounts.map((a) => `<option value="${esc(a.code)}" ${a.code === account ? 'selected' : ''}>${esc(a.code)} · ${esc(a.name)}${a.pending ? ` (${a.pending} por conciliar)` : ''}</option>`).join('')}</select></label>
        <button class="primary" id="import">Importar extrato</button><button id="auto">Conciliação automática</button></div></div>
    <div class="tabs no-print">${TABS.map(([k, l]) => `<a href="#/${link({ tab: k })}" class="${k === tab ? 'active' : ''}">${l}</a>`).join('')}</div>
    <div id="tab"><div class="loading">A carregar…</div></div>`;
  view.querySelector('#acc').onchange = (e) => go(cpath(`reconciliacao?account=${e.target.value}&tab=${tab}`));
  view.querySelector('#import').onclick = () => importDialog(account, () => go(link({ tab: 'pendentes', r: Date.now() })));
  view.querySelector('#auto').onclick = async () => {
    try {
      const r = await api.post('bank.auto', { account });
      toast(r.matched ? `${r.matched} movimento(s) conciliado(s) automaticamente.` : 'Não foram encontradas correspondências claras. Concilie manualmente.', r.matched ? 'good' : '');
      go(link({ r: Date.now() }));
    } catch (err) { toast(err.message, 'bad'); }
  };
  const el = view.querySelector('#tab');
  const fn = { pendentes, conciliados, mapa, extratos }[tab];
  await fn(el, account, query, link);
}

// ------------------------------------------------------------------ importar
function importDialog(account, done) {
  let file = null;
  const m = modal({
    title: `Importar extrato bancário · conta ${account}`, wide: true,
    body: `<p class="muted">Exporte o extrato do homebanking em Excel ou CSV e, se for Excel, guarde-o como CSV. São reconhecidas as colunas Data, Descritivo, Referência, Débito/Crédito (ou Montante) e Saldo; as linhas de cabeçalho do banco são ignoradas.</p>
      <div class="form"><label class="wide">Ficheiro<input type="file" id="bank-file" accept=".csv,.txt"></label>
        <label>Saldo inicial do extrato (opcional)<input name="opening" placeholder="Deduzido da coluna Saldo"></label>
        <label>Modelo<a class="btn" href="${api.href('bank.template')}">Descarregar modelo</a></label></div>
      <div id="bank-prev" style="margin-top:12px"></div>`,
    actions: [{ label: 'Cancelar' }, {
      label: 'Importar', primary: true,
      onClick: async (el) => {
        if (!file) throw new Error('Escolha o ficheiro do extrato.');
        const r = await api.upload('bank.import', file, { account, opening: el.querySelector('[name=opening]').value });
        toast(`Importados ${r.new_lines} movimento(s)${r.auto_matched ? `, ${r.auto_matched} conciliado(s) automaticamente` : ''}.`, 'good');
        done();
      },
    }],
  });
  const root = document.getElementById('modal-root');
  const prev = root.querySelector('#bank-prev');
  const preview = async () => {
    if (!file) return;
    prev.innerHTML = '<div class="loading">A analisar…</div>';
    try {
      const p = await api.upload('bank.preview', file, { account, opening: root.querySelector('[name=opening]').value });
      prev.innerHTML = `<div class="alert ${p.errors.length ? 'warn' : 'good'}">${p.total_lines} movimento(s) lidos: <b>${p.new_lines} novos</b>${p.duplicates ? `, ${p.duplicates} já importados (ignorados)` : ''}.
          ${p.new_lines ? ` De ${fmtDate(p.date_from)} a ${fmtDate(p.date_to)} · entradas ${money(p.in_cents)} · saídas ${money(-p.out_cents)}.` : ''}
          ${p.opening_cents !== null ? ` Saldo inicial ${money(p.opening_cents)}.` : ''}</div>
        ${p.errors.length ? `<div class="table-wrap"><table><thead><tr><th>Linha</th><th>Erro</th></tr></thead><tbody>${p.errors.slice(0, 50).map((e) => `<tr><td>${e.line}</td><td>${esc(e.message)}</td></tr>`).join('')}</tbody></table></div>` : ''}
        ${p.sample.length ? `<div class="table-wrap"><table><thead><tr><th>Data</th><th>Descritivo</th><th>Referência</th><th class="num">Montante</th><th class="num">Saldo</th></tr></thead><tbody>
          ${p.sample.map((l) => `<tr><td>${fmtDate(l.date)}</td><td>${esc(l.description)}</td><td>${esc(l.reference || '')}</td><td class="num ${signClass(l.amount_cents)}">${money(l.amount_cents)}</td><td class="num">${l.balance_cents !== null ? money(l.balance_cents) : ''}</td></tr>`).join('')}</tbody></table></div>` : ''}`;
    } catch (err) { prev.innerHTML = `<div class="alert bad">${esc(err.message)}</div>`; }
  };
  root.querySelector('#bank-file').onchange = (e) => { file = e.target.files[0] || null; preview(); };
  root.querySelector('[name=opening]').onchange = preview;
  return m;
}

// ------------------------------------------------------------------ resumo
function summaryCards(s) {
  const diff = s.difference;
  return `<div class="kpis">
    <div class="kpi"><div class="label">Saldo do extrato em ${fmtDate(s.date)}</div><div class="value">${s.bank_balance === null ? '—' : money(s.bank_balance)}</div><div class="delta muted">${esc(s.bank_balance_source || 'indique o saldo inicial ao importar')}</div></div>
    <div class="kpi"><div class="label">Saldo contabilístico</div><div class="value">${money(s.book_balance)}</div><div class="delta muted">conta ${esc(s.account)}</div></div>
    <div class="kpi"><div class="label">Por conciliar</div><div class="value">${s.pending_bank.length} · ${s.pending_ledger.length}</div><div class="delta muted">extrato · contabilidade</div></div>
    <div class="kpi"><div class="label">Diferença por explicar</div><div class="value ${diff ? 'neg' : ''}">${diff === null ? '—' : money(diff)}</div><div class="delta ${diff ? 'bad' : 'good'}">${diff === null ? '' : diff ? 'reveja o saldo inicial ou movimentos em falta' : 'reconciliação coerente'}</div></div>
  </div>`;
}

// ------------------------------------------------------------------ por conciliar
async function pendentes(el, account, query, link) {
  const p = await api.get('bank.pending', { account });
  if (!p.start) {
    el.innerHTML = `<div class="alert info">Ainda não importou nenhum extrato desta conta. Clique em <b>Importar extrato</b> para começar.</div>`;
    return;
  }
  el.innerHTML = `${summaryCards(p.summary)}
    <div class="toolbar" style="margin-bottom:12px"><label>Reconciliar desde<input type="date" id="start" value="${esc(p.start)}"></label>
      <span class="muted small">Os movimentos contabilísticos anteriores consideram-se incluídos no saldo inicial do extrato.</span></div>
    <div class="grid cols-2">
      <div class="card"><h2>Extrato do banco <span class="muted small">${p.bank.length} por conciliar</span></h2><div class="table-wrap"><table id="tb-bank">
        <thead><tr><th></th><th>Data</th><th>Descritivo</th><th class="num">Montante</th></tr></thead><tbody>
        ${p.bank.map((b) => `<tr><td><input type="checkbox" data-bank="${b.id}" data-amt="${b.amount_cents}"></td><td>${fmtDate(b.date)}</td>
          <td>${esc(b.description)}${b.reference ? ` <span class="muted small">${esc(b.reference)}</span>` : ''}</td><td class="num ${signClass(b.amount_cents)}">${money(b.amount_cents)}</td></tr>`).join('')
          || '<tr><td colspan="4" class="empty">Tudo conciliado.</td></tr>'}</tbody></table></div></div>
      <div class="card"><h2>Contabilidade <span class="muted small">${p.ledger.length} por conciliar</span></h2><div class="table-wrap"><table id="tb-ledger">
        <thead><tr><th></th><th>Data</th><th>Lançamento</th><th class="num">Montante</th></tr></thead><tbody>
        ${p.ledger.map((l) => `<tr><td><input type="checkbox" data-ledger="${l.id}" data-amt="${l.amount_cents}"></td><td>${fmtDate(l.date)}</td>
          <td><a href="#/${cpath('lancamento/' + l.entry_id)}">${esc(l.journal)} ${l.number}</a> ${esc(l.description)}${l.document ? ` <span class="muted small">${esc(l.document)}</span>` : ''}</td>
          <td class="num ${signClass(l.amount_cents)}">${money(l.amount_cents)}</td></tr>`).join('')
          || '<tr><td colspan="4" class="empty">Tudo conciliado.</td></tr>'}</tbody></table></div></div>
    </div>
    <div class="card sticky-bar no-print" id="selbar"></div>`;
  el.querySelector('#start').onchange = async (e) => {
    try { await api.post('bank.set_start', { account, date: e.target.value }); go(link({ r: Date.now() })); } catch (err) { toast(err.message, 'bad'); }
  };
  const bar = el.querySelector('#selbar');
  const sel = () => {
    const b = [...el.querySelectorAll('[data-bank]:checked')];
    const l = [...el.querySelectorAll('[data-ledger]:checked')];
    const sb = b.reduce((s, x) => s + Number(x.dataset.amt), 0);
    const sl = l.reduce((s, x) => s + Number(x.dataset.amt), 0);
    return { b, l, sb, sl };
  };
  const refresh = () => {
    const { b, l, sb, sl } = sel();
    const ok = b.length && l.length && sb === sl;
    bar.innerHTML = `<div class="toolbar" style="justify-content:space-between;width:100%">
      <span>Extrato <b>${money(sb)}</b> (${b.length}) · Contabilidade <b>${money(sl)}</b> (${l.length}) · Diferença <b class="${sb - sl ? 'neg' : ''}">${money(sb - sl)}</b></span>
      <span class="toolbar"><button id="create" ${b.length === 1 && !l.length ? '' : 'disabled'} title="Para comissões, juros e outros movimentos que faltam na contabilidade">Criar lançamento</button>
        <button class="primary" id="match" ${ok ? '' : 'disabled'}>Conciliar seleção</button></span></div>`;
    bar.querySelector('#match').onclick = async () => {
      try {
        await api.post('bank.match', { account, bank_ids: b.map((x) => Number(x.dataset.bank)), ledger_ids: l.map((x) => Number(x.dataset.ledger)) });
        toast('Conciliado.', 'good');
        go(link({ r: Date.now() }));
      } catch (err) { toast(err.message, 'bad'); }
    };
    bar.querySelector('#create').onclick = () => createEntryDialog(account, p.bank.find((x) => x.id === Number(b[0].dataset.bank)), () => go(link({ r: Date.now() })));
  };
  el.querySelectorAll('input[type=checkbox]').forEach((c) => { c.onchange = refresh; });
  refresh();
}

async function createEntryDialog(account, line, done) {
  const accs = (await api.get('accounts.list')).filter((a) => Number(a.movement) && Number(a.active) && a.code !== account);
  const suggest = line.amount_cents < 0 ? '698' : '791';
  modal({
    title: 'Criar lançamento a partir do extrato',
    body: `<p>${fmtDate(line.date)} · ${esc(line.description)} · <b class="${signClass(line.amount_cents)}">${money(line.amount_cents)}</b></p>
      <p class="muted small">É criado e validado um lançamento no diário BCO (conta ${esc(account)} ${line.amount_cents < 0 ? 'a crédito' : 'a débito'}) e o movimento fica conciliado.</p>
      <datalist id="dl-acc">${accs.map((a) => `<option value="${esc(a.code)}">${esc(a.name)}</option>`).join('')}</datalist>
      <div class="form"><label>Contrapartida<input name="counterpart" list="dl-acc" class="mono" value="${suggest}"></label>
        <label class="wide">Descrição<input name="description" value="${esc(line.description)}"></label></div>
      <p class="muted small">Sugestões: 698 comissões e outros gastos bancários · 691 juros suportados · 791 juros obtidos · 2xx transferências e pagamentos ao Estado.</p>`,
    actions: [{ label: 'Cancelar' }, {
      label: 'Criar e conciliar', primary: true,
      onClick: async (el) => {
        const r = await api.post('bank.create_entry', { bank_line_id: line.id, counterpart: el.querySelector('[name=counterpart]').value.trim(), description: el.querySelector('[name=description]').value });
        toast(`Lançamento ${r.journal} / ${r.number} criado e conciliado.`, 'good');
        await refreshInfo();
        done();
      },
    }],
  });
}

// ------------------------------------------------------------------ conciliados
async function conciliados(el, account, query, link) {
  const ms = await api.get('bank.matches', { account });
  el.innerHTML = `<div class="card"><h2>Conciliações <span class="muted small">últimas 100</span></h2><div class="table-wrap"><table>
    <thead><tr><th>Extrato</th><th>Contabilidade</th><th class="num">Valor</th><th>Tipo</th><th></th></tr></thead><tbody>
    ${ms.map((m) => `<tr><td>${m.bank.map((b) => `${fmtDate(b.date)} ${esc(b.description)}`).join('<br>')}</td>
      <td>${m.ledger.map((l) => `${fmtDate(l.date)} <a href="#/${cpath('lancamento/' + l.entry_id)}">${esc(l.journal)} ${l.number}</a> ${esc(l.description)}`).join('<br>')}</td>
      <td class="num">${money(m.bank.reduce((s, b) => s + Number(b.amount_cents), 0))}</td>
      <td>${Number(m.auto) ? '<span class="badge">automática</span>' : '<span class="badge good">manual</span>'}</td>
      <td class="num"><button class="icon-btn small" data-undo="${m.id}">desfazer</button></td></tr>`).join('') || '<tr><td colspan="5" class="empty">Ainda não há conciliações.</td></tr>'}
    </tbody></table></div></div>`;
  el.querySelectorAll('[data-undo]').forEach((b) => { b.onclick = async () => {
    try { await api.post('bank.unmatch', { match_id: Number(b.dataset.undo) }); toast('Conciliação desfeita.'); go(link({ r: Date.now() })); } catch (err) { toast(err.message, 'bad'); }
  }; });
}

// ------------------------------------------------------------------ mapa
async function mapa(el, account, query, link) {
  const r = await api.get('bank.report', { account, date: query.date || '' });
  const date = r.date;
  const rowsOf = (list, label) => list.map((x) => `<tr><td class="indent-1">${fmtDate(x.date)} · ${esc(x.description)}${x.journal ? ` (${esc(x.journal)} ${x.number})` : ''}</td><td class="num">${money(x.amount_cents)}</td></tr>`).join('') || `<tr><td class="indent-1 muted">${label}</td><td></td></tr>`;
  el.innerHTML = `<div class="toolbar no-print" style="margin-bottom:12px"><label>Data<input type="date" id="d" value="${esc(date)}"></label>
      <button id="xlsx">Exportar Excel</button><button onclick="window.print()">Imprimir / PDF</button></div>
    <div class="print-head"><b>${esc(state.info.name)}</b> · NIF ${esc(state.info.nif || '')} · Reconciliação bancária · ${esc(r.account)} ${esc(r.account_name)} · ${fmtDate(date)}</div>
    <div class="card"><h2>Mapa de reconciliação · ${esc(r.account)} ${esc(r.account_name)} em ${fmtDate(date)}</h2><table id="map"><tbody>
      <tr class="section"><td>Saldo do extrato bancário</td><td class="num">${r.bank_balance === null ? '—' : money(r.bank_balance)}</td></tr>
      <tr><td>(+) Entradas registadas na contabilidade e ainda não no extrato</td><td class="num">${money(r.ledger_in)}</td></tr>
      ${rowsOf(r.pending_ledger.filter((x) => x.amount_cents > 0), 'nenhuma')}
      <tr><td>(−) Saídas registadas na contabilidade e ainda não no extrato (ex.: cheques em circulação)</td><td class="num">${money(r.ledger_out)}</td></tr>
      ${rowsOf(r.pending_ledger.filter((x) => x.amount_cents < 0), 'nenhuma')}
      <tr><td>(−) Entradas no extrato ainda não registadas na contabilidade</td><td class="num">${money(-r.bank_in)}</td></tr>
      ${rowsOf(r.pending_bank.filter((x) => x.amount_cents > 0), 'nenhuma')}
      <tr><td>(+) Saídas no extrato ainda não registadas na contabilidade (ex.: comissões)</td><td class="num">${money(-r.bank_out)}</td></tr>
      ${rowsOf(r.pending_bank.filter((x) => x.amount_cents < 0), 'nenhuma')}
      <tr class="total"><td>Saldo contabilístico calculado</td><td class="num">${r.computed_book === null ? '—' : money(r.computed_book)}</td></tr>
      <tr class="total"><td>Saldo contabilístico da conta ${esc(r.account)}</td><td class="num">${money(r.book_balance)}</td></tr>
      <tr class="total"><td>Diferença por explicar</td><td class="num ${r.difference ? 'neg' : ''}">${r.difference === null ? '—' : money(r.difference)}</td></tr>
    </tbody></table>
    ${r.bank_balance === null ? '<div class="alert warn" style="margin-top:12px">O extrato não tem coluna de saldo nem saldo inicial indicado: importe-o de novo com o saldo inicial para obter o mapa completo.</div>' : ''}</div>`;
  el.querySelector('#d').onchange = (e) => go(link({ date: e.target.value }));
  el.querySelector('#xlsx').onclick = () => tableToXlsx(el.querySelector('#map'), `reconciliacao_${account}_${date}.xlsx`, { sheet: 'Reconciliação', title: `${state.info.name} · Reconciliação bancária ${account} · ${date}` });
}

// ------------------------------------------------------------------ extratos
async function extratos(el, account, query, link) {
  const st = await api.get('bank.statements', { account });
  el.innerHTML = `<div class="card"><h2>Extratos importados</h2><div class="table-wrap"><table>
    <thead><tr><th>Importado em</th><th>Ficheiro</th><th>Período</th><th class="num">Movimentos</th><th class="num">Conciliados</th><th class="num">Saldo inicial</th><th></th></tr></thead><tbody>
    ${st.map((s) => `<tr><td>${esc(s.imported_at)}</td><td>${esc(s.filename || '')}</td><td>${fmtDate(s.date_from)} a ${fmtDate(s.date_to)}</td><td class="num">${s.lines}</td><td class="num">${s.matched}</td>
      <td class="num">${s.opening_cents !== null ? money(s.opening_cents) : '—'}</td><td class="num"><button class="icon-btn small danger" data-del="${s.id}">apagar</button></td></tr>`).join('')
      || '<tr><td colspan="7" class="empty">Nenhum extrato importado.</td></tr>'}</tbody></table></div>
    <p class="muted small">Apagar um extrato retira os seus movimentos e desfaz as conciliações; os lançamentos contabilísticos não são alterados.</p></div>`;
  el.querySelectorAll('[data-del]').forEach((b) => { b.onclick = async () => {
    if (!(await confirmDialog('Apagar extrato', 'Os movimentos deste extrato e as respetivas conciliações são removidos. Os lançamentos ficam como estão.', 'Apagar'))) return;
    try { await api.post('bank.delete_statement', { id: Number(b.dataset.del) }); toast('Extrato apagado.'); go(link({ r: Date.now() })); } catch (err) { toast(err.message, 'bad'); }
  }; });
}
