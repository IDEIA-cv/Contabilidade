// Gráficos SVG simples, sem bibliotecas externas (funciona offline).
import { compact, esc, money } from './util.js';

let tip;
function tooltip(e, html) {
  if (!tip) { tip = document.createElement('div'); tip.className = 'tooltip'; document.body.appendChild(tip); }
  if (!html) { tip.style.display = 'none'; return; }
  tip.innerHTML = html;
  tip.style.display = 'block';
  tip.style.left = Math.min(e.clientX + 12, window.innerWidth - tip.offsetWidth - 8) + 'px';
  tip.style.top = (e.clientY - 36) + 'px';
}

function niceScale(min, max, ticks = 5) {
  if (min === max) { max = min + 1; }
  const span = max - min;
  const step0 = span / ticks;
  const mag = Math.pow(10, Math.floor(Math.log10(step0)));
  const step = [1, 2, 2.5, 5, 10].map((m) => m * mag).find((s) => s >= step0) || step0;
  const lo = Math.floor(min / step) * step;
  const hi = Math.ceil(max / step) * step;
  const out = [];
  for (let v = lo; v <= hi + step / 2; v += step) out.push(Math.round(v));
  return out;
}

/**
 * Barras agrupadas (valores em centavos), com linha opcional.
 * opts: { labels, series: [{name, values, color}], line: {name, values, color}, onClick(index, seriesIndex), height }
 */
export function barChart(el, opts) {
  const W = 760, H = opts.height || 260, L = 64, R = 12, T = 12, B = 28;
  const all = [...opts.series.flatMap((s) => s.values), ...(opts.line ? opts.line.values : []), 0].filter((v) => v !== null && v !== undefined);
  const ticks = niceScale(Math.min(...all), Math.max(...all));
  const lo = ticks[0], hi = ticks[ticks.length - 1];
  const y = (v) => T + (H - T - B) * (1 - (v - lo) / (hi - lo || 1));
  const n = opts.labels.length;
  const band = (W - L - R) / n;
  const gw = band * 0.7;
  const bw = gw / opts.series.length;
  let svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(opts.title || 'Gráfico')}">`;
  svg += '<g class="grid">' + ticks.map((t) => `<line x1="${L}" x2="${W - R}" y1="${y(t)}" y2="${y(t)}"/>`).join('') + '</g>';
  svg += '<g class="axis">' + ticks.map((t) => `<text x="${L - 6}" y="${y(t) + 4}" text-anchor="end">${compact(t)}</text>`).join('');
  svg += opts.labels.map((l, i) => `<text x="${L + band * i + band / 2}" y="${H - 8}" text-anchor="middle">${esc(l)}</text>`).join('') + '</g>';
  svg += `<line x1="${L}" x2="${W - R}" y1="${y(0)}" y2="${y(0)}" stroke="var(--muted)" stroke-width="1"/>`;
  opts.series.forEach((s, si) => {
    s.values.forEach((v, i) => {
      if (v === null || v === undefined) return;
      const x = L + band * i + (band - gw) / 2 + bw * si;
      const y0 = y(Math.max(v, 0)), y1 = y(Math.min(v, 0));
      svg += `<rect class="bar" data-i="${i}" data-s="${si}" x="${x + 1}" y="${y0}" width="${Math.max(bw - 2, 1)}" height="${Math.max(y1 - y0, v ? 1 : 0)}" rx="2" fill="${s.color}"/>`;
    });
  });
  if (opts.line) {
    const ok = (v) => v !== null && v !== undefined;
    const pts = opts.line.values.map((v, i) => (ok(v) ? `${L + band * i + band / 2},${y(v)}` : null)).filter(Boolean).join(' ');
    svg += `<polyline points="${pts}" fill="none" stroke="${opts.line.color}" stroke-width="2"/>`;
    svg += opts.line.values.map((v, i) => !ok(v) ? '' : `<circle class="bar" data-i="${i}" data-s="line" cx="${L + band * i + band / 2}" cy="${y(v)}" r="3.5" fill="${opts.line.color}"/>`).join('');
  }
  svg += '</svg>';
  const legend = [...opts.series, ...(opts.line ? [opts.line] : [])].map((s) => `<span><i style="background:${s.color}"></i>${esc(s.name)}</span>`).join('');
  el.innerHTML = `<div class="chart">${svg}</div><div class="legend">${legend}</div>`;
  el.querySelectorAll('.bar').forEach((b) => {
    const i = Number(b.dataset.i);
    const s = b.dataset.s === 'line' ? opts.line : opts.series[Number(b.dataset.s)];
    b.addEventListener('mousemove', (e) => tooltip(e, `<b>${esc(opts.labels[i])}</b> · ${esc(s.name)}: ${money(s.values[i])}`));
    b.addEventListener('mouseleave', (e) => tooltip(e, null));
    if (opts.onClick) b.addEventListener('click', () => { tooltip(null, null); opts.onClick(i, b.dataset.s); });
  });
}

/** Barras horizontais (ranking). items: [{label, value, sub}] */
export function hbars(el, items, { onClick } = {}) {
  const max = Math.max(...items.map((i) => Math.abs(i.value)), 1);
  el.innerHTML = items.map((it, i) => `<div class="hbar ${onClick ? 'clickable' : ''}" data-i="${i}">
      <div title="${esc(it.label)}" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(it.label)}</div>
      <div class="track"><div class="fill" style="width:${(Math.abs(it.value) / max * 100).toFixed(1)}%"></div></div>
      <div class="num">${money(it.value)}${it.sub ? ` <span class="muted small">${esc(it.sub)}</span>` : ''}</div></div>`).join('') || '<div class="empty">Sem dados</div>';
  if (onClick) el.querySelectorAll('.hbar').forEach((r) => { r.style.cursor = 'pointer'; r.onclick = () => onClick(items[Number(r.dataset.i)]); });
}
