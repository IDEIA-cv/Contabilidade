// Fatura eletrónica (e-Fatura, DNRE): estado e fila de comunicação, configuração da ligação ao Middleware,
// documentação oficial (manual técnico, listas de códigos) e passos de adesão.
import { api } from '../api.js';
import { setCrumbs, go, cpath, state } from '../main.js';
import { esc, money, fmtDate, toast, confirmDialog, formData, MONTHS } from '../util.js';

const STATUS = { pendente: ['Por comunicar', 'info'], autorizado: ['Autorizado', 'good'], rejeitado: ['Rejeitado', 'bad'], erro: ['Com erros', 'bad'], excluido: ['Não comunicar', ''] };
const TABS = [['', 'Estado e envio'], ['recebidos', 'Documentos recebidos'], ['conferencia', 'Conferência do IVA'], ['configurar', 'Configuração'], ['documentacao', 'Documentação oficial']];

const kb = (n) => (n > 1048576 ? (n / 1048576).toLocaleString('pt-PT', { maximumFractionDigits: 1 }) + ' MB' : Math.ceil(n / 1024) + ' KB');

export async function render(ctx) {
  const tab = ctx.params[0] || '';
  const ov = await api.get('efatura.overview');
  setCrumbs('Fatura eletrónica', TABS.find(([k]) => k === tab)?.[1]);
  ctx.view.innerHTML = `
    <div class="page-head"><div><h1>Fatura eletrónica (e-Fatura)</h1>
      <p class="muted">Comunicação dos documentos de venda à DNRE pela plataforma e-Fatura de Cabo Verde, segundo o manual técnico v11.0 e o XSD oficial.</p></div>
      <div class="toolbar">${ov.enabled ? `<span class="badge good">Ativa · ${esc(ov.repositories[ov.settings.repo])}</span>` : '<span class="badge warn">Não ativa</span>'}</div></div>
    <nav class="tabs" aria-label="Secções da e-Fatura">${TABS.map(([k, l]) => `<a href="#/${cpath('efatura' + (k ? '/' + k : ''))}" class="${k === tab ? 'active' : ''}" ${k === tab ? 'aria-current="page"' : ''}>${l}</a>`).join('')}</nav>
    <div id="tab"></div>`;
  const box = ctx.view.querySelector('#tab');
  if (tab === 'configurar') return renderSettings(box, ov, ctx);
  if (tab === 'documentacao') return renderDocs(box, ov);
  if (tab === 'recebidos') return renderReceived(box);
  if (tab === 'conferencia') return renderConference(box);
  return renderQueue(box, ov, ctx);
}

// =========================================================================== estado e envio

function readinessHtml(ov) {
  const php = Object.entries(ov.php).filter(([, ok]) => !ok).map(([k]) => `Extensão PHP <b>${k}</b> desativada (ative-a no php.ini do XAMPP).`);
  const items = [...ov.readiness.map(esc), ...php];
  if (!items.length) return '';
  return `<div class="alert warn"><b>Antes de comunicar documentos falta:</b><ul>${items.map((x) => `<li>${x}</li>`).join('')}</ul>
    <p class="small">Os dados da empresa preenchem-se em <a href="#/${cpath('empresa')}">Empresa e cópias</a>; o LED e o código do software em <a href="#/${cpath('efatura/configurar')}">Configuração</a>.</p></div>`;
}

async function renderQueue(box, ov, ctx) {
  const f = { status: ctx.query.status ?? 'pendente', from: ctx.query.from || '', to: ctx.query.to || '' };
  const res = await api.get('efatura.documents', f);
  const c = ov.counts;
  const kpi = (k, label, cls = '') => `<a class="kpi kpi-link ${f.status === k ? 'current' : ''}" href="#/${cpath('efatura?status=' + k)}"><div class="label">${label}</div><div class="value ${cls && c[k] ? 'tone-' + cls : ''}">${c[k] || 0}</div></a>`;
  box.innerHTML = `
    ${ov.enabled ? '' : `<div class="alert info">A e-Fatura ainda não está ativa nesta empresa. Os documentos fiscais ficam aqui marcados como <b>por comunicar</b>.
      Para ligar a aplicação à e-Fatura siga os passos em <a href="#/${cpath('efatura/documentacao')}">Documentação oficial</a> e preencha a <a href="#/${cpath('efatura/configurar')}">Configuração</a>.</div>`}
    ${readinessHtml(ov)}
    <div class="kpis">${kpi('pendente', 'Por comunicar', 'warn')}${kpi('autorizado', 'Autorizados', 'good')}${kpi('rejeitado', 'Rejeitados', 'bad')}${kpi('erro', 'Com erros', 'bad')}${kpi('excluido', 'Não comunicar')}</div>
    <div class="card"><form class="form" id="flt">
      <label>Estado<select name="status"><option value="">Todos</option>${Object.entries(STATUS).map(([k, [l]]) => `<option value="${k}" ${f.status === k ? 'selected' : ''}>${l}</option>`).join('')}</select></label>
      <label>De<input type="date" name="from" value="${esc(f.from)}"></label>
      <label>Até<input type="date" name="to" value="${esc(f.to)}"></label>
    </form></div>
    <div class="card">
      <div class="toolbar" style="justify-content:space-between;margin-bottom:8px">
        <span class="small muted">${res.since ? `Comunicação desde ${fmtDate(res.since)}. Documentos anteriores ficam como "Não comunicar".` : ''}</span>
        <span class="toolbar"><button id="exclude">Não comunicar</button><button id="restore">Repor na fila</button>
          <button id="send-sel" ${ov.enabled ? '' : 'disabled'}>Enviar selecionados</button><button class="primary" id="send-all" ${ov.enabled && c.pendente ? '' : 'disabled'}>Enviar todos por comunicar</button></span></div>
      <div class="table-wrap"><table id="ef-docs"><caption class="sr-only">Documentos fiscais e estado na e-Fatura</caption>
        <thead><tr><th><input type="checkbox" id="all" aria-label="Selecionar todos"></th><th>Documento</th><th>Data</th><th>Cliente</th><th class="num">Total</th><th>Estado</th><th>Resposta / erro</th><th></th></tr></thead>
        <tbody>${res.rows.map((r) => {
          const [l, cls] = STATUS[r.efatura_status] || [r.efatura_status, ''];
          const late = r.efatura_status === 'pendente' && r.status === 'emitido' && r.iud && r.days >= 1;
          return `<tr data-id="${r.id}"><td><input type="checkbox" class="sel" aria-label="Selecionar ${esc(r.doc_no)}"></td>
            <td><a href="#/${cpath('vendas/' + r.id)}">${esc(r.doc_no)}</a>${r.status === 'anulado' ? ' <span class="badge bad">Anulado</span>' : ''}</td>
            <td>${fmtDate(r.date)}</td><td>${esc(r.customer_name || 'Consumidor final')}${r.customer_nif ? ` <span class="small mono muted">${esc(r.customer_nif)}</span>` : ''}</td>
            <td class="num">${money(r.total_cents)}</td>
            <td><span class="badge ${cls}">${esc(l)}</span>${late ? `<div class="small ${r.days > 7 ? 'neg' : 'muted'}">${r.days > 7 ? 'mais de 7 dias: fora do prazo de contingência' : 'em contingência (offline)'}</div>` : ''}</td>
            <td class="small">${esc(r.efatura_message || (r.iud ? '' : 'Sem IUD (emitido antes de ativar a e-Fatura)'))}</td>
            <td style="white-space:nowrap">${r.iud ? `<button data-check="${r.id}">Verificar</button> <a class="btn" href="${api.href('efatura.xml', { id: r.id })}">XML</a>` : ''}</td></tr>`;
        }).join('') || '<tr><td colspan="8" class="empty">Não há documentos com este filtro.</td></tr>'}</tbody></table></div>
      <div id="out"></div>
    </div>`;
  const flt = box.querySelector('#flt');
  flt.querySelectorAll('select, input').forEach((i) => { i.onchange = () => go(cpath('efatura?' + new URLSearchParams(formData(flt)))); });
  const selected = () => [...box.querySelectorAll('.sel:checked')].map((i) => Number(i.closest('tr').dataset.id));
  box.querySelector('#all').onchange = (e) => box.querySelectorAll('.sel').forEach((i) => { i.checked = e.target.checked; });
  const out = box.querySelector('#out');
  const resultsHtml = (results) => {
    const ok = results.filter((r) => r.ok).length;
    return `<div class="alert ${ok === results.length ? 'good' : 'warn'}"><b>${ok} de ${results.length} documento(s) autorizados.</b>
      <ul>${results.map((r) => `<li>${esc(r.doc_no)}: ${esc((STATUS[r.status] || [r.status])[0])} — ${esc(r.message)}</li>`).join('')}</ul></div>`;
  };
  const send = async (payload) => {
    try {
      const r = await api.post('efatura.send', payload);
      await render(ctx);
      const o = ctx.view.querySelector('#out');
      if (o) o.innerHTML = resultsHtml(r);
    } catch (e) { toast(e.message, 'bad'); }
  };
  box.querySelector('#send-sel').onclick = () => { const ids = selected(); if (!ids.length) return toast('Selecione os documentos a enviar.', 'bad'); send({ ids }); };
  box.querySelector('#send-all').onclick = async () => {
    if (await confirmDialog('Enviar à e-Fatura?', `Vão ser enviados ${c.pendente} documento(s) por comunicar através do Middleware, para o repositório <b>${esc(ov.repositories[ov.settings.repo])}</b>.`, 'Enviar')) send({ pending: 1 });
  };
  const exclude = async (flag) => {
    const ids = selected();
    if (!ids.length) return toast('Selecione os documentos.', 'bad');
    const r = await api.post('efatura.exclude', { ids, exclude: flag });
    toast(`${r.changed} documento(s) alterado(s).`, 'good');
    render(ctx);
  };
  box.querySelector('#exclude').onclick = () => exclude(true);
  box.querySelector('#restore').onclick = () => exclude(false);
  box.querySelectorAll('[data-check]').forEach((b) => {
    b.onclick = async () => {
      const r = await api.get('efatura.check', { id: b.dataset.check });
      const doc = b.closest('tr').querySelector('a').textContent;
      out.innerHTML = r.ok ? `<div class="alert good">${esc(doc)}: o XML cumpre o XSD oficial da e-Fatura.</div>`
        : `<div class="alert bad"><b>${esc(doc)}: corrija antes de enviar.</b><ul>${r.errors.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></div>`;
      out.scrollIntoView({ block: 'nearest' });
    };
  });
}

// =========================================================================== configuração

function renderSettings(box, ov, ctx) {
  const s = ov.settings;
  const opt = (obj, sel) => Object.entries(obj).map(([k, v]) => `<option value="${k}" ${String(sel) === k ? 'selected' : ''}>${esc(v)}</option>`).join('');
  box.innerHTML = `
    ${readinessHtml(ov)}
    <form class="card" id="cfg"><h2>Emissor</h2>
      <div class="form">
        <label style="flex-direction:row;align-items:center;gap:8px" class="wide"><input type="checkbox" name="enabled" ${s.enabled === '1' ? 'checked' : ''}> Ativar a e-Fatura nesta empresa (os documentos fiscais passam a ter IUD e código QR)</label>
        <label>Comunicar documentos a partir de<input type="date" name="since" value="${esc(s.since)}"><span class="small muted">data da adesão; os anteriores não são comunicados</span></label>
        <label>Repositório<select name="repo">${opt(ov.repositories, s.repo)}</select><span class="small muted">use Teste ou Homologação até a DNRE autorizar a produção</span></label>
        <label>LED (lógica de emissão)<input name="led" class="mono" inputmode="numeric" maxlength="5" value="${esc(s.led)}" placeholder="ex.: 1"><span class="small muted">registado na Plataforma Eletrónica</span></label>
        <label>Código do software<input name="software_code" class="mono" maxlength="10" value="${esc(s.software_code)}" placeholder="atribuído pela DNRE"><span class="small muted">até 10 letras ou algarismos</span></label>
        <label>NIF do transmissor<input name="transmitter_nif" class="mono" inputmode="numeric" maxlength="9" value="${esc(s.transmitter_nif)}" placeholder="vazio = NIF da empresa"><span class="small muted">só se outra entidade transmite por si (ex.: contabilista)</span></label>
        <label>Motivo de contingência por omissão<select name="contingency_reason">${opt(ov.contingency_reasons, s.contingency_reason)}</select><span class="small muted">usado quando um documento é enviado mais de 24 h depois de emitido</span></label>
      </div>
      <h2 style="margin-top:20px">Ligação ao Middleware da e-Fatura</h2>
      <p class="small muted">O Middleware é a aplicação da DNRE instalada no seu computador. Assina os documentos com os certificados da Plataforma Eletrónica e envia-os à e-Fatura. Esta aplicação gera o XML, valida-o com o XSD oficial e entrega-o ao Middleware.</p>
      <div class="form">
        <label class="wide">Endereço do Middleware<input name="mw_url" class="mono" value="${esc(s.mw_url)}" placeholder="https://localhost:3443"></label>
        <label class="wide">Chave de transmissor (transmitter-key)<input name="mw_key" class="mono" type="password" autocomplete="off" value="" placeholder="${s.mw_key_set ? 'guardada (escreva para substituir)' : '64 carateres, gerada no Middleware'}"></label>
        <label style="flex-direction:row;align-items:center;gap:8px" class="wide"><input type="checkbox" name="mw_verify" ${s.mw_verify === '1' ? 'checked' : ''}> Verificar o certificado HTTPS do Middleware (desligue se o Middleware usa o certificado autoassinado de origem)</label>
        ${s.mw_key_set ? '<label style="flex-direction:row;align-items:center;gap:8px" class="wide"><input type="checkbox" name="mw_key_clear"> Apagar a chave guardada</label>' : ''}
      </div>
      <div class="toolbar" style="margin-top:12px"><button class="primary" type="submit" data-admin>Guardar configuração</button><button type="button" id="test">Testar ligação ao Middleware</button></div>
      <div id="test-out"></div>
    </form>`;
  const form = box.querySelector('#cfg');
  form.onsubmit = async (e) => {
    e.preventDefault();
    try {
      await api.post('efatura.save', formData(form));
      toast('Configuração da e-Fatura guardada.', 'good');
      render(ctx);
    } catch (err) { toast(err.message, 'bad'); }
  };
  box.querySelector('#test').onclick = async () => {
    const out = box.querySelector('#test-out');
    out.innerHTML = '<p class="muted">A testar…</p>';
    try {
      await api.post('efatura.save', formData(form));
      const r = await api.post('efatura.test', {});
      out.innerHTML = `<div class="alert ${r.ok ? 'good' : 'bad'}">${esc(r.message)}</div>`;
    } catch (err) { out.innerHTML = `<div class="alert bad">${esc(err.message)}</div>`; }
  };
}

// =========================================================================== documentação

function renderDocs(box, ov) {
  box.innerHTML = `
    <div class="grid cols-2">
      <div class="card"><h2>Documentos oficiais (cópia local)</h2>
        <ul class="doc-list">${Object.entries(ov.docs).map(([k, d]) => `<li><a href="${api.href('efatura.doc', { id: k })}" target="_blank" rel="noopener">${esc(d.title)}</a> <span class="small muted">${esc(d.file.split('.').pop().toUpperCase())} · ${kb(d.size)}</span></li>`).join('')}</ul>
        <p class="small muted">Esquemas XSD oficiais (versão de 27/05/2024) e SAF-T CV incluídos na aplicação, em <span class="mono">resources/efatura/xsd</span>. Cada documento é validado com estes esquemas antes do envio.</p></div>
      <div class="card"><h2>Páginas oficiais</h2>
        <ul class="doc-list">${ov.links.map(([u, t]) => `<li><a href="${esc(u)}" target="_blank" rel="noopener">${esc(t)}</a></li>`).join('')}</ul>
        <h3>Middleware da e-Fatura</h3>
        <ul class="doc-list">${Object.entries(ov.middleware).map(([os, u]) => `<li><a href="${esc(u)}" rel="noopener">Descarregar para ${esc(os)}</a></li>`).join('')}</ul></div>
    </div>
    <div class="card"><h2>Como ligar a aplicação à e-Fatura</h2>
      <ol class="steps">
        <li><b>Adesão.</b> Registe a empresa na <a href="https://pe.efatura.cv/register" target="_blank" rel="noopener">Plataforma Eletrónica</a> (PE) com o NIF e as credenciais do Portal do Contribuinte.</li>
        <li><b>Certificados.</b> Na PE peça o certificado digital de emissão (veja o PDF "Pedido de certificado digital: tipos de documentos") e indique os tipos de documento que vai emitir: FTE, FRE, TVE, RCE, NCE, NDE e DTE.</li>
        <li><b>LED e software.</b> Registe a lógica de emissão (LED) e o software de faturação. Anote o número do LED e o código do software.</li>
        <li><b>Middleware.</b> Instale o Middleware da DNRE neste computador (ligações acima), configure o transmissor e o emissor e copie a chave de transmissor (64 carateres).</li>
        <li><b>Dados da empresa.</b> Confirme em <a href="#/${cpath('empresa')}">Empresa e cópias</a> o NIF, a morada, o email e o telefone (obrigatórios no documento eletrónico).</li>
        <li><b>Configuração.</b> Em <a href="#/${cpath('efatura/configurar')}">Configuração</a> indique o LED, o código do software, o endereço e a chave do Middleware, escolha o repositório <b>Teste</b> e ative a e-Fatura. Use "Testar ligação".</li>
        <li><b>Homologação.</b> Emita documentos de teste, verifique-os com o XSD e envie-os. Quando a DNRE confirmar, mude o repositório para <b>Produção</b> e indique a data de início.</li>
      </ol>
      <h3>Regras aplicadas pela aplicação</h3>
      <ul class="small">
        <li>Cada documento fiscal recebe um IUD de 45 carateres (CV + repositório + data + NIF + LED + tipo + número + código aleatório + dígito de controlo) e um código QR para <span class="mono">pe.efatura.cv/dfe/view/IUD</span>.</li>
        <li>A fatura-recibo exige o NIF do cliente; para consumidor final sem NIF use o <b>talão de venda</b> (TVE), que exige identificação a partir de 20 000 ECV.</li>
        <li>As guias de entrega e de transporte partilham a mesma numeração (na e-Fatura são ambas DTE).</li>
        <li>Notas de crédito e de débito indicam o motivo (art.º 65.º do CIVA) e a fatura de origem.</li>
        <li>Isenções e unidades usam as listas oficiais de códigos.</li>
        <li>Sem ligação, o documento fica por comunicar e é enviado depois em modo de contingência (até 7 dias), com o aviso "Pendente de Autorização" impresso.</li>
      </ul></div>`;
}

// =========================================================================== documentos recebidos

const RSTATUS = { novo: ['A importar', 'info'], duplicado: ['Já existe', ''], ignorado: ['Ignorado', ''], erro: ['Com erro', 'bad'] };

function receivedTable(rows, done = false) {
  return `<div class="table-wrap"><table id="rec-rows"><caption class="sr-only">Documentos recebidos</caption>
    <thead><tr><th>Documento</th><th>Data</th><th>Fornecedor</th><th class="num">IVA</th><th class="num">Total</th><th>Resultado</th></tr></thead>
    <tbody>${rows.map((r) => {
      const [l, c] = r.created_id ? [r.created_status === 'registado' ? 'Registado' : 'Rascunho criado', 'good'] : (RSTATUS[r.status] || [r.status, '']);
      return `<tr><td>${esc(r.type_name)} <b>${esc(r.number)}</b><div class="small muted mono">${esc(r.file)}</div></td><td>${r.date ? fmtDate(r.date) : ''}</td>
        <td>${esc(r.supplier)} <span class="small mono muted">${esc(r.supplier_nif)}</span>${r.supplier_exists || !r.supplier ? '' : ' <span class="badge info">novo</span>'}</td>
        <td class="num">${money(r.vat_cents)}</td><td class="num">${money(r.total_cents)}</td>
        <td><span class="badge ${c}">${esc(l)}</span>${r.created_id ? ` <a href="#/${cpath('compras/' + r.created_id)}">abrir</a>` : ''}<div class="small">${esc(r.message || '')}</div></td></tr>`;
    }).join('') || '<tr><td colspan="6" class="empty">Nenhum documento no ficheiro.</td></tr>'}</tbody></table></div>${done ? '' : ''}`;
}

function renderReceived(box) {
  box.innerHTML = `
    <div class="card"><h2>Importar faturas de fornecedores da e-Fatura</h2>
      <p class="muted">Na <a href="https://pe.efatura.cv" target="_blank" rel="noopener">Plataforma Eletrónica</a>, consulte os documentos em que a empresa é adquirente e descarregue os XML (um a um ou num ZIP).
        A aplicação cria as faturas de fornecedores, despesas pagas e notas de crédito correspondentes, com o fornecedor (criado se não existir), as linhas e o IVA. Recibos e guias são ignorados.</p>
      <div class="form">
        <label class="wide">Ficheiro XML ou ZIP<input type="file" id="rec-file" accept=".xml,.zip"></label>
        <label class="wide" style="flex-direction:row;align-items:center;gap:8px"><input type="checkbox" id="rec-register"> Registar logo na contabilidade quando o fornecedor já tem uma conta de gastos habitual (os restantes ficam em rascunho para escolher a conta)</label>
      </div>
      <div class="toolbar" style="margin-top:10px"><button class="primary" id="rec-preview">Analisar ficheiro</button></div>
      <div id="rec-out" style="margin-top:12px"></div></div>`;
  const out = box.querySelector('#rec-out');
  box.querySelector('#rec-preview').onclick = async () => {
    const f = box.querySelector('#rec-file').files[0];
    if (!f) return toast('Escolha o ficheiro XML ou ZIP.', 'bad');
    out.innerHTML = '<div class="loading">A analisar…</div>';
    try {
      const p = await api.upload('efatura.received_preview', f);
      const c = p.counts;
      out.innerHTML = `<p><b>${c.novo}</b> a importar · ${c.duplicado} já existem · ${c.ignorado} ignorados · ${c.erro} com erro</p>${receivedTable(p.rows)}
        <div class="toolbar" style="margin-top:10px"><button class="primary" id="rec-import" ${c.novo ? '' : 'disabled'}>Importar ${c.novo} documento(s)</button></div>`;
      out.querySelector('#rec-import').onclick = async (e) => {
        e.target.disabled = true;
        try {
          const r = await api.upload('efatura.received_import', f, { register: box.querySelector('#rec-register').checked ? '1' : '0' });
          toast(`${r.created} documento(s) importado(s).`, 'good');
          out.innerHTML = `<div class="alert good">${r.created} documento(s) importado(s). Os rascunhos estão em <a href="#/${cpath('compras?status=rascunho')}">Faturas de fornecedores</a>: confirme a conta de gastos e registe.</div>${receivedTable(r.rows, true)}`;
        } catch (err) { toast(err.message, 'bad'); e.target.disabled = false; }
      };
    } catch (err) { out.innerHTML = `<div class="alert bad">${esc(err.message)}</div>`; }
  };
}

// =========================================================================== conferência do IVA

async function renderConference(box) {
  const c = await api.get('efatura.conference', { year: state.year });
  const diff = (v, before) => before ? '<td class="num muted small">antes da adesão</td>' : `<td class="num ${v ? 'neg' : ''}">${v ? money(v) : '—'}</td>`;
  const row = (r, label) => `<tr${r.month === null ? ' class="total"' : ''}><td>${label}</td>
    <td class="num">${money(r.sales_authorized)}</td><td class="num">${money(r.sales_pending)}</td><td class="num">${money(r.sales_ledger)}</td>${diff(r.sales_diff, r.before_since)}
    <td class="num">${money(r.purchases_efatura)}</td><td class="num">${money(r.purchases_manual)}</td><td class="num">${money(r.purchases_ledger)}</td>${diff(r.purchases_diff, r.before_since)}</tr>`;
  box.innerHTML = `
    <div class="card"><h2>IVA ${c.year}: e-Fatura e contabilidade</h2>
      <p class="muted small">Vendas: IVA dos documentos fiscais emitidos (autorizados na e-Fatura ou ainda por comunicar) e IVA liquidado nos lançamentos.
        Compras: IVA das faturas de fornecedores importadas da e-Fatura (com IUD) ou registadas à mão, e IVA dedutível nos lançamentos. Uma diferença indica lançamentos de IVA feitos à mão ou documentos em falta. ${c.since ? `As diferenças contam a partir da data de início da e-Fatura (${fmtDate(c.since)}).` : ''} Mude o exercício no topo.</p>
      <div class="table-wrap"><table id="conf"><caption class="sr-only">Conferência mensal do IVA</caption>
        <thead><tr><th rowspan="2">Mês</th><th colspan="4">IVA liquidado (vendas)</th><th colspan="4">IVA dedutível (compras)</th></tr>
          <tr><th class="num">Autorizado</th><th class="num">Por comunicar</th><th class="num">Contabilidade</th><th class="num">Diferença</th>
            <th class="num">Da e-Fatura</th><th class="num">Registado à mão</th><th class="num">Contabilidade</th><th class="num">Diferença</th></tr></thead>
        <tbody>${c.rows.map((r) => row(r, MONTHS[r.month - 1])).join('')}</tbody><tfoot>${row(c.totals, 'Total')}</tfoot></table></div></div>
    <div class="card"><h2>Faturas de fornecedores com IVA sem documento da e-Fatura</h2>
      <p class="muted small">Desde a e-Fatura, o IVA só é dedutível com o documento eletrónico autorizado. Importe os XML destas faturas em "Documentos recebidos" para as confirmar.</p>
      <div class="table-wrap"><table><thead><tr><th>Registo</th><th>Documento do fornecedor</th><th>Data</th><th>Fornecedor</th><th class="num">IVA</th></tr></thead>
        <tbody>${c.manual_docs.map((d) => `<tr><td><a href="#/${cpath('compras/' + d.id)}">${esc(d.doc_no)}</a></td><td>${esc(d.supplier_doc || '')}</td><td>${fmtDate(d.date)}</td>
          <td>${esc(d.supplier || '')} <span class="small mono muted">${esc(d.nif || '')}</span></td><td class="num">${money(d.vat_cents)}</td></tr>`).join('')
          || '<tr><td colspan="5" class="empty">Todas as faturas com IVA têm documento da e-Fatura.</td></tr>'}</tbody></table></div></div>`;
}

