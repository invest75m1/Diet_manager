/* Diet Manager – frontend (vanilla JS, bez build kroku) */
'use strict';

// ---------- Konštanty ----------
const MEALS = {
  breakfast: 'Raňajky', snack1: 'Desiata', lunch: 'Obed', snack2: 'Olovrant', dinner: 'Večera', other: 'Iné / neskoro',
};
const MEAL_ORDER = Object.keys(MEALS);
const NUTR = [
  { k: 'protein', name: 'Bielkoviny', unit: 'g', kind: 'min' },
  { k: 'carbs', name: 'Sacharidy', unit: 'g', kind: 'max' },
  { k: 'fat', name: 'Tuky', unit: 'g', kind: 'max' },
  { k: 'fiber', name: 'Vláknina', unit: 'g', kind: 'min' },
];
const TOL = 0.05;
const ACTIVITY_LEVELS = {
  sedentary: 'Sedavý – kancelária, takmer bez pohybu',
  light: 'Ľahko aktívny – bežná chôdza, občas pohyb',
  moderate: 'Stredne aktívny – veľa chôdze, práca na nohách',
  active: 'Veľmi aktívny – fyzická práca',
  very_active: 'Extrémne aktívny – ťažká fyzická práca',
};

// ---------- Stav ----------
const state = {
  user: null,
  aiEnabled: false,
  date: todayStr(),
  day: null,
  reportMode: 'week',
  reportAnchor: todayStr(),
};

// ---------- Pomocné funkcie ----------
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
const nf0 = new Intl.NumberFormat('sk-SK', { maximumFractionDigits: 0 });
const nf1 = new Intl.NumberFormat('sk-SK', { maximumFractionDigits: 1 });
function f0(n) { return nf0.format(Math.round(Number(n) || 0)); }
function f1(n) { return nf1.format(Math.round((Number(n) || 0) * 10) / 10); }
function num(v) {
  if (v === null || v === undefined || v === '') return null;
  const n = parseFloat(String(v).replace(',', '.').replace(/\s/g, ''));
  return Number.isFinite(n) ? n : null;
}
function pad(n) { return String(n).padStart(2, '0'); }
function dateStr(d) { return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; }
function todayStr() { return dateStr(new Date()); }
function parseDate(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); }
function addDays(s, n) { const d = parseDate(s); d.setDate(d.getDate() + n); return dateStr(d); }
function nowTime() { const d = new Date(); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; }
const DOW = ['Ne', 'Po', 'Ut', 'St', 'Št', 'Pi', 'So'];
const DOW_LONG = ['nedeľa', 'pondelok', 'utorok', 'streda', 'štvrtok', 'piatok', 'sobota'];
const MONTHS = ['január', 'február', 'marec', 'apríl', 'máj', 'jún', 'júl', 'august', 'september', 'október', 'november', 'december'];
function fmtDate(s, long = false) {
  const d = parseDate(s);
  return long ? `${DOW_LONG[d.getDay()]} ${d.getDate()}. ${d.getMonth() + 1}. ${d.getFullYear()}` : `${DOW[d.getDay()]} ${d.getDate()}. ${d.getMonth() + 1}.`;
}
function dayLabel(s) {
  const t = todayStr();
  if (s === t) return 'Dnes';
  if (s === addDays(t, -1)) return 'Včera';
  if (s === addDays(t, 1)) return 'Zajtra';
  return fmtDate(s);
}
function mealForTime(hhmm) {
  const [h, m] = hhmm.split(':').map(Number);
  const t = h * 60 + m;
  if (t >= 240 && t < 600) return 'breakfast';
  if (t >= 600 && t < 690) return 'snack1';
  if (t >= 690 && t < 870) return 'lunch';
  if (t >= 870 && t < 1050) return 'snack2';
  if (t >= 1050 && t < 1290) return 'dinner';
  return 'other';
}
function debounce(fn, ms) { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; }

/**
 * Stav hodnoty voči cieľu (farba + text):
 *  max-ciele (kalórie, sacharidy, tuky): zelená < 95 %, oranžová 95–105 % (blízko limitu), červená > 105 %
 *  min-ciele (bielkoviny, vláknina, voda): modrá = ešte chýba, zelená ≥ 95 %
 */
function statusOf(kind, value, goal) {
  if (!goal || goal <= 0) return 'none';
  const r = value / goal;
  if (kind === 'max') return r < 1 - TOL ? 'good' : r <= 1 + TOL ? 'warn' : 'bad';
  return r >= 1 - TOL ? 'good' : 'progress';
}
function diffText(kind, value, goal, unit) {
  if (!goal) return '';
  const d = goal - value;
  if (kind === 'max') {
    return d >= 0 ? `zostáva ${f0(d)} ${unit}` : `${f0(-d)} ${unit} nad cieľom`;
  }
  return d > 0 ? `chýba ${f0(d)} ${unit}` : `cieľ splnený (+${f0(-d)} ${unit})`;
}
const STATUS_LABEL = { good: 'v cieli', warn: 'blízko limitu', bad: 'nad limitom', progress: 'pod cieľom', none: '' };

// ---------- API ----------
async function api(method, route, body, opts = {}) {
  const [path, qs] = route.split('?');
  const url = `api.php?r=${encodeURIComponent(path)}${qs ? '&' + qs : ''}`;
  const init = { method, headers: { 'X-Requested-With': 'DietManager' }, credentials: 'same-origin' };
  if (body instanceof FormData) init.body = body;
  else if (body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(body); }
  let res;
  try {
    res = await fetch(url, init);
  } catch (e) {
    throw new Error('Bez pripojenia na internet');
  }
  let data = null;
  try { data = await res.json(); } catch (e) { /* nie JSON */ }
  if (!res.ok) {
    const err = new Error(data?.error || `Chyba servera (${res.status})`);
    err.status = res.status; err.code = data?.code;
    if (!opts.raw) {
      if (err.code === 'unauthenticated') { state.user = null; renderLogin(); }
      else if (err.code === 'must_change_password') { renderForcedPassword(); }
      else if (err.code === 'not_installed') { location.href = 'install.php'; }
    }
    throw err;
  }
  return data;
}

// ---------- UI primitíva ----------
let toastTimer;
function toast(msg, isErr = false) {
  const t = $('#toast');
  t.textContent = msg;
  t.className = 'show' + (isErr ? ' err' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { t.className = ''; }, isErr ? 4200 : 2400);
}
function showError(e) { if (e && e.code !== 'unauthenticated' && e.code !== 'must_change_password') toast(e.message || String(e), true); }

/** Spodný panel (sheet). Vráti objekt { body, foot, close, el }. */
function sheet(title, { onClose, wide } = {}) {
  const wrap = document.createElement('div');
  wrap.className = 'sheet-wrap';
  wrap.innerHTML = `<div class="sheet" role="dialog" aria-modal="true" aria-label="${esc(title)}">
    <div class="sheet-head"><h2>${esc(title)}</h2><button class="icon-btn" data-close aria-label="Zavrieť">✕</button></div>
    <div class="sheet-body"></div><div class="sheet-foot hidden"></div></div>`;
  document.body.appendChild(wrap);
  document.body.style.overflow = 'hidden';
  const api_ = {
    el: wrap,
    body: $('.sheet-body', wrap),
    foot: $('.sheet-foot', wrap),
    setTitle(t) { $('.sheet-head h2', wrap).textContent = t; },
    close() {
      if (!wrap.isConnected) return;
      wrap.remove();
      if (!$('.sheet-wrap')) document.body.style.overflow = '';
      onClose && onClose();
    },
  };
  wrap.addEventListener('click', (e) => { if (e.target === wrap || e.target.closest('[data-close]')) api_.close(); });
  return api_;
}
function setFoot(sh, html) { sh.foot.innerHTML = html; sh.foot.classList.toggle('hidden', !html); }

function confirmBox(message, okLabel = 'Áno', danger = false) {
  return new Promise((resolve) => {
    const sh = sheet('Potvrdenie', { onClose: () => resolve(false) });
    sh.body.innerHTML = `<p>${esc(message)}</p><div class="grid2"><button class="btn" data-no>Zrušiť</button>
      <button class="btn ${danger ? 'danger' : 'primary'}" data-yes>${esc(okLabel)}</button></div>`;
    $('[data-no]', sh.body).onclick = () => sh.close();
    $('[data-yes]', sh.body).onclick = () => { resolve(true); sh.el.remove(); document.body.style.overflow = $('.sheet-wrap') ? 'hidden' : ''; };
  });
}

async function withBusy(btn, fn) {
  const old = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner sm"></span>';
  try { return await fn(); } finally { if (btn.isConnected) { btn.disabled = false; btn.innerHTML = old; } }
}

function photoUrl(ref) { return `api.php?r=${encodeURIComponent('/photo')}&f=${encodeURIComponent(ref)}`; }

/** Zmenšenie fotky v prehliadači pred odoslaním (rýchlejšie odoslanie, nižšie náklady AI). */
async function resizeImage(file, max = 1568, quality = 0.85) {
  try {
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, max / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas');
    c.width = Math.round(bmp.width * scale);
    c.height = Math.round(bmp.height * scale);
    const ctx = c.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, c.width, c.height);
    ctx.drawImage(bmp, 0, 0, c.width, c.height);
    const blob = await new Promise((r) => c.toBlob(r, 'image/jpeg', quality));
    return blob || file;
  } catch (e) {
    return file; // starší prehliadač – pošleme originál, server ho zmenší
  }
}

// ---------- Grafy (SVG) ----------
function ringSvg(value, goal, status, size = 150) {
  const stroke = 13;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const ratio = goal > 0 ? value / goal : 0;
  const main = Math.min(1, ratio);
  const over = ratio > 1 ? Math.min(1, ratio - 1) : 0;
  const cx = size / 2;
  return `<svg width="${size}" height="${size}" viewBox="0 0 ${size} ${size}" aria-hidden="true">
    <circle cx="${cx}" cy="${cx}" r="${r}" fill="none" stroke="var(--track)" stroke-width="${stroke}"/>
    ${main > 0 ? `<circle class="s-${status}" cx="${cx}" cy="${cx}" r="${r}" fill="none" stroke="var(--s)" stroke-width="${stroke}"
      stroke-linecap="round" stroke-dasharray="${(c * main).toFixed(1)} ${c.toFixed(1)}" transform="rotate(-90 ${cx} ${cx})"/>` : ''}
    ${over > 0 ? `<circle cx="${cx}" cy="${cx}" r="${r}" fill="none" stroke="var(--bg)" stroke-width="${stroke + 2}" stroke-dasharray="2 ${c}" transform="rotate(-91 ${cx} ${cx})"/>
    <circle class="s-bad" cx="${cx}" cy="${cx}" r="${r - stroke - 3}" fill="none" stroke="var(--s)" stroke-width="5" stroke-linecap="round"
      stroke-dasharray="${(2 * Math.PI * (r - stroke - 3) * over).toFixed(1)} 9999" transform="rotate(-90 ${cx} ${cx})"/>` : ''}
  </svg>`;
}

function macroBar(n, value, goal) {
  const st = statusOf(n.kind, value, goal);
  const ratio = goal > 0 ? value / goal : 0;
  // Stupnica do 125 % cieľa, aby bolo vidno aj prekročenie.
  const scaleMax = Math.max(1.25, Math.min(ratio, 2));
  const fillW = Math.min(ratio, scaleMax) / scaleMax * 100;
  const goalX = 1 / scaleMax * 100;
  return `<div class="macro s-${st}">
    <div class="macro-head"><span class="name">${n.name}</span>
      <span class="val num"><b>${f0(value)}</b> / ${goal ? f0(goal) : '–'} ${n.unit}</span></div>
    <div class="bar" role="img" aria-label="${n.name}: ${f0(value)} z ${f0(goal)} ${n.unit}">
      <div class="fill" style="width:${fillW}%"></div>
      ${goal ? `<div class="goal" style="left:calc(${goalX}% - 1px)"></div>` : ''}
    </div>
    <div class="macro-foot"><span class="st">${goal ? `<span class="dot"></span>${STATUS_LABEL[st]}` : ''}</span>
      <span>${goal ? diffText(n.kind, value, goal, n.unit) : ''}</span></div>
  </div>`;
}

function kcalSummary(totals, goal) {
  const g = goal?.kcal || 0;
  const v = totals.kcal;
  const st = statusOf('max', v, g);
  const d = g - v;
  const mid = !g ? `<span class="big num">${f0(v)}</span><span class="sub">kcal prijaté</span>`
    : d >= 0 ? `<span class="big num">${f0(d)}</span><span class="sub">kcal<br>zostáva</span>`
      : `<span class="big num" style="color:var(--bad)">+${f0(-d)}</span><span class="sub">kcal<br>nad cieľom</span>`;
  const training = goal?.training_kcal > 0
    ? `<div class="kcal-meta">z toho tréning <b class="num">+${f0(goal.training_kcal)}</b> kcal</div>` : '';
  return `<div class="summary">
    <div class="ring s-${st}">${ringSvg(v, g, st)}<div class="mid">${mid}</div></div>
    <div>
      ${g ? `<div class="status-line s-${st}"><span class="dot"></span>Kalórie: ${STATUS_LABEL[st]}</div>` : ''}
      <div class="kcal-meta">Prijaté <b class="num">${f0(v)}</b> kcal</div>
      <div class="kcal-meta">Cieľ <b class="num">${g ? f0(g) : '–'}</b> kcal</div>
      ${training}
      ${g ? `<div class="kcal-meta">${f0(Math.min(999, v / g * 100))} % denného cieľa</div>` : ''}
    </div>
  </div>`;
}

/**
 * Stĺpcový graf kalórií po dňoch s čiarou cieľa.
 * days: [{date, kcal, goal, status, future, logged}]
 */
function kcalChart(days, onPick) {
  const W = 340, H = 180, padL = 34, padR = 6, padT = 10, padB = 24;
  const vals = days.map((d) => Math.max(d.kcal || 0, d.goal || 0));
  const maxV = Math.max(500, ...vals) * 1.1;
  const step = maxV > 4000 ? 1000 : maxV > 2000 ? 500 : 250;
  const top = Math.ceil(maxV / step) * step;
  const y = (v) => padT + (H - padT - padB) * (1 - v / top);
  const n = days.length;
  const bw = (W - padL - padR) / n;
  const barW = Math.max(3, Math.min(26, bw - 2));
  let grid = '';
  for (let v = 0; v <= top; v += step) {
    grid += `<line class="grid" x1="${padL}" x2="${W - padR}" y1="${y(v)}" y2="${y(v)}"/>
      <text x="${padL - 4}" y="${y(v) + 3}" text-anchor="end">${v >= 1000 ? f1(v / 1000) + 'k' : v}</text>`;
  }
  let bars = '';
  let goalPath = '';
  const labelEvery = n > 14 ? Math.ceil(n / 8) : 1;
  days.forEach((d, i) => {
    const cx = padL + bw * i + bw / 2;
    if (d.kcal > 0) {
      const h = Math.max(2, y(0) - y(d.kcal));
      // Zaoblený horný koniec (4px), ukotvený na základnej čiare
      const x0 = cx - barW / 2, y0 = y(0) - h, r = Math.min(4, barW / 2, h);
      bars += `<path class="s-${d.status || 'none'}" fill="var(--s)" opacity="${d.inProgress ? 0.55 : 1}"
        d="M${x0},${y(0)} V${y0 + r} Q${x0},${y0} ${x0 + r},${y0} H${x0 + barW - r} Q${x0 + barW},${y0} ${x0 + barW},${y0 + r} V${y(0)} Z"/>`;
    }
    if (d.goal) goalPath += `${goalPath ? 'L' : 'M'}${(cx - bw / 2).toFixed(1)},${y(d.goal).toFixed(1)} H${(cx + bw / 2).toFixed(1)} `;
    if (i % labelEvery === 0) {
      const dt = parseDate(d.date);
      bars += `<text x="${cx}" y="${H - 8}" text-anchor="middle">${n <= 7 ? DOW[dt.getDay()] : dt.getDate()}</text>`;
    }
    bars += `<rect data-i="${i}" x="${padL + bw * i}" y="${padT}" width="${bw}" height="${H - padT - padB}" fill="transparent" style="cursor:pointer"/>`;
  });
  const wrap = document.createElement('div');
  wrap.className = 'chart';
  wrap.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Kalórie po dňoch v porovnaní s cieľom">
    ${grid}<path class="goal-line" d="${goalPath}"/>${bars}</svg>
    <div class="legend"><span class="s-good"><i class="sw"></i>v cieli</span><span class="s-warn"><i class="sw"></i>blízko limitu (±5 %)</span>
    <span class="s-bad"><i class="sw"></i>nad limitom</span><span><i class="ln"></i>cieľ</span></div>`;
  const tip = document.createElement('div');
  tip.className = 'tip hidden';
  wrap.appendChild(tip);
  const svg = $('svg', wrap);
  const show = (i) => {
    const d = days[i];
    if (!d || d.future) { tip.classList.add('hidden'); return; }
    const rect = svg.getBoundingClientRect();
    const sx = rect.width / W;
    const cx = (padL + bw * i + bw / 2) * sx;
    tip.innerHTML = `<b>${fmtDate(d.date)}</b><br>${d.logged ? f0(d.kcal) + ' kcal' : 'bez záznamu'}${d.goal ? ' / cieľ ' + f0(d.goal) : ''}`;
    tip.style.left = Math.max(50, Math.min(rect.width - 50, cx)) + 'px';
    tip.style.top = (y(Math.max(d.kcal, d.goal || 0)) * sx) + 'px';
    tip.classList.remove('hidden');
  };
  svg.addEventListener('pointermove', (e) => { const i = e.target.dataset?.i; if (i !== undefined) show(+i); });
  svg.addEventListener('pointerleave', () => tip.classList.add('hidden'));
  svg.addEventListener('click', (e) => { const i = e.target.dataset?.i; if (i !== undefined && onPick && !days[+i].future) onPick(days[+i]); });
  return wrap;
}

/** Čiarový graf (hmotnosť). points: [{date, v}] */
function lineChart(points, unit = 'kg') {
  const wrap = document.createElement('div');
  wrap.className = 'chart';
  if (points.length < 2) {
    wrap.innerHTML = `<p class="muted small">Na graf sú potrebné aspoň 2 záznamy.</p>`;
    return wrap;
  }
  const W = 340, H = 170, padL = 36, padR = 8, padT = 12, padB = 24;
  const t0 = parseDate(points[0].date).getTime();
  const t1 = parseDate(points[points.length - 1].date).getTime();
  const span = Math.max(1, t1 - t0);
  const vs = points.map((p) => p.v);
  let lo = Math.min(...vs), hi = Math.max(...vs);
  const padV = Math.max(0.5, (hi - lo) * 0.15);
  lo = Math.floor(lo - padV); hi = Math.ceil(hi + padV);
  const x = (d) => padL + (W - padL - padR) * ((parseDate(d).getTime() - t0) / span);
  const y = (v) => padT + (H - padT - padB) * (1 - (v - lo) / (hi - lo));
  const stepV = Math.max(1, Math.ceil((hi - lo) / 4));
  let grid = '';
  for (let v = lo; v <= hi; v += stepV) {
    grid += `<line class="grid" x1="${padL}" x2="${W - padR}" y1="${y(v)}" y2="${y(v)}"/><text x="${padL - 4}" y="${y(v) + 3}" text-anchor="end">${v}</text>`;
  }
  const path = points.map((p, i) => `${i ? 'L' : 'M'}${x(p.date).toFixed(1)},${y(p.v).toFixed(1)}`).join(' ');
  const first = points[0], last = points[points.length - 1];
  const fd = (s) => { const d = parseDate(s); return `${d.getDate()}. ${d.getMonth() + 1}.`; };
  wrap.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Vývoj hmotnosti">
    ${grid}<path class="series-line" d="${path}"/>
    ${points.length <= 40 ? points.map((p) => `<circle cx="${x(p.date)}" cy="${y(p.v)}" r="3.5" fill="var(--progress)" stroke="var(--surface)" stroke-width="2"/>`).join('') : ''}
    <circle class="hover-dot hidden" r="5" fill="var(--progress)" stroke="var(--surface)" stroke-width="2"/>
    <line class="hover-line hidden" y1="${padT}" y2="${H - padB}" stroke="var(--muted)" stroke-width="1"/>
    <text x="${padL}" y="${H - 6}">${fd(first.date)}</text><text x="${W - padR}" y="${H - 6}" text-anchor="end">${fd(last.date)}</text>
    <rect x="${padL}" y="${padT}" width="${W - padL - padR}" height="${H - padT - padB}" fill="transparent" class="hit"/></svg>`;
  const tip = document.createElement('div');
  tip.className = 'tip hidden';
  wrap.appendChild(tip);
  const svg = $('svg', wrap);
  const dot = $('.hover-dot', svg), ln = $('.hover-line', svg);
  svg.addEventListener('pointermove', (e) => {
    const rect = svg.getBoundingClientRect();
    const sx = rect.width / W;
    const px = (e.clientX - rect.left) / sx;
    let best = points[0];
    for (const p of points) if (Math.abs(x(p.date) - px) < Math.abs(x(best.date) - px)) best = p;
    dot.setAttribute('cx', x(best.date)); dot.setAttribute('cy', y(best.v));
    ln.setAttribute('x1', x(best.date)); ln.setAttribute('x2', x(best.date));
    dot.classList.remove('hidden'); ln.classList.remove('hidden');
    tip.innerHTML = `<b>${f1(best.v)} ${unit}</b><br>${fmtDate(best.date)}`;
    tip.style.left = Math.max(44, Math.min(rect.width - 44, x(best.date) * sx)) + 'px';
    tip.style.top = (y(best.v) * sx - 6) + 'px';
    tip.classList.remove('hidden');
  });
  svg.addEventListener('pointerleave', () => { tip.classList.add('hidden'); dot.classList.add('hidden'); ln.classList.add('hidden'); });
  return wrap;
}

// ---------- Kostra aplikácie a navigácia ----------
const ROUTES = {
  '': viewDashboard,
  reporty: viewReports,
  telo: viewBody,
  viac: viewMore,
  ciel: viewGoal,
  potraviny: viewFoods,
  admin: viewAdmin,
  heslo: viewPassword,
};

function shell(active) {
  const app = $('#app');
  if (!$('.nav', app)) {
    app.innerHTML = `<main class="page" id="page"></main>
    <nav class="nav" aria-label="Hlavná navigácia"><div class="nav-inner">
      <a href="#/" data-nav=""><span class="ico">🍽️</span>Prehľad</a>
      <a href="#/reporty" data-nav="reporty"><span class="ico">📊</span>Reporty</a>
      <button class="plus" id="navAdd" aria-label="Pridať jedlo">+</button>
      <a href="#/telo" data-nav="telo"><span class="ico">⚖️</span>Telo</a>
      <a href="#/viac" data-nav="viac"><span class="ico">☰</span>Viac</a>
    </div></nav>`;
    $('#navAdd').onclick = () => openAdd({ date: state.date });
  }
  $$('.nav a', app).forEach((a) => a.classList.toggle('on', a.dataset.nav === active));
  const page = $('#page');
  page.innerHTML = '';
  window.scrollTo(0, 0);
  return page;
}

function route() {
  if (!state.user) return renderLogin();
  if (state.user.must_change_password) return renderForcedPassword();
  const key = location.hash.replace(/^#\/?/, '').split('?')[0];
  const view = ROUTES[key] || viewDashboard;
  const navKey = ['ciel', 'potraviny', 'admin', 'heslo'].includes(key) ? 'viac' : (ROUTES[key] ? key : '');
  state.lastToday = todayStr();
  view(shell(navKey));
}
window.addEventListener('hashchange', route);

async function boot() {
  try {
    const me = await api('GET', '/auth/me', undefined, { raw: true });
    state.user = me.user;
    state.aiEnabled = me.ai_enabled;
    state.version = me.version;
  } catch (e) {
    if (e.code === 'not_installed') { location.href = 'install.php'; return; }
    $('#app').innerHTML = `<div class="login"><div class="card center"><h1>Server nedostupný</h1>
      <p class="muted">${esc(e.message)}</p><button class="btn primary block" onclick="location.reload()">Skúsiť znova</button></div></div>`;
    return;
  }
  route();
  if ('serviceWorker' in navigator && location.protocol === 'https:') {
    navigator.serviceWorker.register('sw.js').catch(() => {});
  }
}

// Pri návrate do aplikácie po polnoci prepni "dnes".
document.addEventListener('visibilitychange', () => {
  if (document.visibilityState === 'visible' && state.user && state.lastToday && state.lastToday !== todayStr()) {
    if (state.date === state.lastToday) state.date = todayStr();
    route();
  }
});

// ---------- Prihlásenie ----------
const LOGO = `<svg class="logo" viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="16" fill="var(--accent)"/>
  <circle cx="32" cy="32" r="17" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="6"/>
  <path d="M32 15 a17 17 0 1 1 -16.2 22" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round"/></svg>`;

function renderLogin() {
  $('#app').innerHTML = `<div class="login"><form class="card" id="loginForm" autocomplete="on">
    ${LOGO}<h1 class="center">Diet Manager</h1><p class="center muted small" style="margin:4px 0 8px">Sledovanie stravy</p>
    <label class="f" for="lu">Meno</label>
    <input class="input" id="lu" name="username" autocomplete="username" autocapitalize="none" autocorrect="off" required>
    <label class="f" for="lp">Heslo</label>
    <input class="input" id="lp" name="password" type="password" autocomplete="current-password" required>
    <label class="check"><input type="checkbox" id="lr" checked> Zapamätať si ma na 30 dní</label>
    <button class="btn primary block" type="submit">Prihlásiť sa</button>
    <div class="error-box hidden" id="lerr"></div>
    <p class="muted tiny center" style="margin-top:14px">Zabudnuté heslo? Požiadajte administrátora o nové.</p>
  </form></div>`;
  $('#loginForm').onsubmit = async (e) => {
    e.preventDefault();
    const btn = $('button[type=submit]', e.target);
    const err = $('#lerr');
    err.classList.add('hidden');
    await withBusy(btn, async () => {
      try {
        const r = await api('POST', '/auth/login', { username: $('#lu').value, password: $('#lp').value, remember: $('#lr').checked }, { raw: true });
        state.user = r.user;
        state.aiEnabled = r.ai_enabled;
        state.date = todayStr();
        if (location.hash && location.hash !== '#/') location.hash = '#/';
        route();
      } catch (ex) {
        err.textContent = ex.message;
        err.classList.remove('hidden');
      }
    });
  };
}

function passwordForm(forced) {
  return `<form id="pwForm" class="${forced ? 'card' : ''}">
    ${forced ? `${LOGO}<h1 class="center">Nastavte si nové heslo</h1>
      <p class="muted small center">Heslo od administrátora je dočasné. Pred pokračovaním si zvoľte vlastné.</p>` : ''}
    <label class="f" for="pc">${forced ? 'Dočasné heslo' : 'Súčasné heslo'}</label>
    <input class="input" id="pc" type="password" autocomplete="current-password" required>
    <label class="f" for="pn">Nové heslo (min. 8 znakov)</label>
    <input class="input" id="pn" type="password" autocomplete="new-password" minlength="8" required>
    <label class="f" for="pn2">Nové heslo znova</label>
    <input class="input" id="pn2" type="password" autocomplete="new-password" minlength="8" required>
    <button class="btn primary block" type="submit" style="margin-top:16px">Uložiť heslo</button>
    <div class="error-box hidden" id="perr"></div>
    ${forced ? '<button type="button" class="btn ghost block" id="pwLogout" style="margin-top:6px">Odhlásiť sa</button>' : ''}
  </form>`;
}
function bindPasswordForm(onDone) {
  $('#pwForm').onsubmit = async (e) => {
    e.preventDefault();
    const err = $('#perr');
    err.classList.add('hidden');
    if ($('#pn').value !== $('#pn2').value) { err.textContent = 'Nové heslá sa nezhodujú'; err.classList.remove('hidden'); return; }
    await withBusy($('button[type=submit]', e.target), async () => {
      try {
        const r = await api('POST', '/auth/password', { current_password: $('#pc').value, new_password: $('#pn').value }, { raw: true });
        state.user = r.user;
        toast('Heslo bolo zmenené');
        onDone();
      } catch (ex) { err.textContent = ex.message; err.classList.remove('hidden'); }
    });
  };
  const lo = $('#pwLogout');
  if (lo) lo.onclick = logout;
}
function renderForcedPassword() {
  $('#app').innerHTML = `<div class="login">${passwordForm(true)}</div>`;
  bindPasswordForm(() => { location.hash = '#/'; route(); });
}
async function logout() {
  try { await api('POST', '/auth/logout', {}, { raw: true }); } catch (e) { /* aj tak odhlásiť */ }
  state.user = null;
  renderLogin();
}

// ---------- Prehľad dňa ----------
async function viewDashboard(page) {
  page.innerHTML = `<div class="daybar">
      <button class="icon-btn" id="dPrev" aria-label="Predchádzajúci deň">‹</button>
      <div class="lbl"><strong id="dLbl"></strong><span class="muted small" id="dSub"></span></div>
      <button class="icon-btn" id="dNext" aria-label="Nasledujúci deň">›</button>
    </div><div id="dBody"><div class="boot" style="min-height:40vh"><div class="spinner"></div></div></div>`;
  const setLabels = () => {
    $('#dLbl').textContent = dayLabel(state.date);
    $('#dSub').textContent = fmtDate(state.date, true);
    $('#dNext').disabled = state.date >= todayStr();
    $('#dNext').style.opacity = state.date >= todayStr() ? 0.3 : 1;
  };
  setLabels();
  $('#dPrev').onclick = () => { state.date = addDays(state.date, -1); setLabels(); loadDay(); };
  $('#dNext').onclick = () => { if (state.date < todayStr()) { state.date = addDays(state.date, 1); setLabels(); loadDay(); } };
  $('#dLbl').onclick = () => { state.date = todayStr(); setLabels(); loadDay(); };
  // Potiahnutie prstom doľava/doprava = zmena dňa
  let sx = null;
  page.addEventListener('touchstart', (e) => { if (!e.target.closest('.chart')) sx = e.touches[0].clientX; }, { passive: true });
  page.addEventListener('touchend', (e) => {
    if (sx === null) return;
    const dx = e.changedTouches[0].clientX - sx;
    sx = null;
    if (Math.abs(dx) > 90) (dx > 0 ? $('#dPrev') : $('#dNext')).click();
  }, { passive: true });
  await loadDay();
}

async function loadDay() {
  const body = $('#dBody');
  if (!body) return;
  const date = state.date;
  try {
    const d = await api('GET', `/day?date=${date}`);
    if (date !== state.date || !$('#dBody')) return;
    state.day = d;
    renderDay($('#dBody'), d, true);
  } catch (e) { showError(e); }
}

function renderDay(body, d, editable) {
  const g = d.goal;
  const t = d.totals;
  const noGoal = !g ? `<div class="info-box" style="margin-bottom:12px">Zatiaľ nemáte nastavený denný cieľ.
      <a href="#/ciel"><b>Nastaviť cieľ →</b></a></div>` : '';
  const waterGoal = g?.water_ml || 0;
  const cups = Math.max(8, Math.ceil(waterGoal / 250));
  const full = Math.floor(d.water_ml / 250);
  const waterSt = statusOf('min', d.water_ml, waterGoal);
  const trainings = d.activities.map((a) => `<span class="chip accent">🏃 ${esc(a.name)} +${f0(a.kcal)} kcal</span>`).join(' ');

  const byMeal = {};
  for (const e of d.entries) (byMeal[e.meal] ||= []).push(e);
  const meals = MEAL_ORDER.filter((m) => m !== 'other' || byMeal.other).map((m) => {
    const list = byMeal[m] || [];
    const sum = list.reduce((s, e) => s + e.kcal, 0);
    return `<section class="card meal" data-meal="${m}">
      <div class="meal-head"><h2>${MEALS[m]}</h2><span class="kc num">${list.length ? f0(sum) + ' kcal' : ''}</span>
        ${editable ? `<button class="icon-btn" data-add="${m}" aria-label="Pridať do: ${MEALS[m]}" style="color:var(--accent);font-size:1.6rem">+</button>` : ''}</div>
      ${list.map((e) => `<div class="entry" data-id="${e.id}">
        ${e.photo ? `<img class="thumb" loading="lazy" src="${photoUrl(e.photo)}" alt="">` : ''}
        <div class="grow"><div class="nm ellipsis">${esc(e.name)}</div>
          <div class="mc num">${e.amount_g ? f0(e.amount_g) + ' g · ' : ''}B ${f0(e.protein)} · S ${f0(e.carbs)} · T ${f0(e.fat)}</div></div>
        <div class="kc num">${f0(e.kcal)} kcal</div></div>`).join('')}
      ${!list.length && editable ? `<div class="meal-actions"><button class="btn sm ghost" data-copy="${m}">↺ Ako včera</button></div>` : ''}
    </section>`;
  }).join('');

  body.innerHTML = `${noGoal}
    <section class="card">${kcalSummary(t, g)}
      ${trainings || editable ? `<div class="row wrap" style="margin-top:12px">${trainings}
        ${editable ? `<button class="btn sm ghost" id="addTraining">+ Tréning</button>` : ''}</div>` : ''}
    </section>
    <section class="card">${NUTR.map((n) => macroBar(n, t[n.k], g?.[n.k] || 0)).join('')}</section>
    <section class="card s-${waterSt}">
      <div class="card-title"><h2>💧 Voda</h2><span class="num small"><b>${f1(d.water_ml / 1000)}</b> / ${waterGoal ? f1(waterGoal / 1000) : '–'} l</span></div>
      <div class="water"><div class="drops" aria-hidden="true">${Array.from({ length: cups }, (_, i) => `<span class="drop ${i < full ? 'full' : ''}"></span>`).join('')}</div>
      ${editable ? `<button class="btn sm" data-water="-250" aria-label="Odobrať 250 ml">−</button><button class="btn sm primary" data-water="250">+250 ml</button>` : ''}</div>
    </section>
    ${meals}
    ${editable ? `<button class="btn block" id="copyDay">↺ Skopírovať celý včerajší deň</button>` : ''}`;

  if (!editable) {
    $$('.entry', body).forEach((el) => { el.style.cursor = 'default'; });
    return;
  }
  $$('[data-add]', body).forEach((b) => { b.onclick = () => openAdd({ date: d.date, meal: b.dataset.add }); });
  $$('.entry', body).forEach((el) => {
    el.onclick = () => openEditEntry(d.entries.find((e) => e.id === +el.dataset.id));
  });
  $$('[data-water]', body).forEach((b) => {
    b.onclick = async () => {
      try { await api('POST', '/water', { date: d.date, delta: +b.dataset.water }); loadDay(); } catch (e) { showError(e); }
    };
  });
  $$('[data-copy]', body).forEach((b) => { b.onclick = () => copyFromYesterday(d.date, b.dataset.copy); });
  $('#copyDay').onclick = () => copyFromYesterday(d.date, '');
  $('#addTraining').onclick = () => openTraining(d.date, loadDay);
}

async function copyFromYesterday(date, meal) {
  const from = addDays(date, -1);
  const what = meal ? MEALS[meal].toLowerCase() : 'všetky jedlá';
  if (!await confirmBox(`Skopírovať ${what} z ${fmtDate(from)} do ${dayLabel(date).toLowerCase()}?`, 'Skopírovať')) return;
  try {
    const r = await api('POST', '/entries/copy', { from_date: from, to_date: date, meal });
    toast(r.copied ? `Skopírované položky: ${r.copied}` : 'Včera tu nebolo nič zapísané');
    loadDay();
  } catch (e) { showError(e); }
}

// ---------- Pridanie jedla ----------
function whenPicker(ctx) {
  return `<div class="grid2" style="margin-bottom:12px">
    <div><label class="f" style="margin-top:0" for="wMeal">Jedlo dňa</label>
      <select class="input" id="wMeal">${MEAL_ORDER.map((m) => `<option value="${m}" ${m === ctx.meal ? 'selected' : ''}>${MEALS[m]}</option>`).join('')}</select></div>
    <div><label class="f" style="margin-top:0" for="wTime">Čas · ${esc(dayLabel(ctx.date))}</label>
      <input class="input" id="wTime" type="time" value="${ctx.time}"></div>
  </div>`;
}
function bindWhen(root, ctx) {
  const mealSel = $('#wMeal', root), timeIn = $('#wTime', root);
  mealSel.onchange = () => { ctx.meal = mealSel.value; ctx.mealManual = true; };
  timeIn.onchange = () => {
    ctx.time = timeIn.value || ctx.time;
    if (!ctx.mealManual) { ctx.meal = mealForTime(ctx.time); mealSel.value = ctx.meal; }
  };
}

/**
 * Hlavné okno na pridanie jedla.
 * ctx: { date, meal?, tab? }
 */
function openAdd(opts = {}) {
  const isToday = (opts.date || todayStr()) === todayStr();
  const ctx = {
    date: opts.date || todayStr(),
    time: isToday ? nowTime() : '12:00',
  };
  ctx.meal = opts.meal || mealForTime(ctx.time);
  ctx.mealManual = !!opts.meal;
  if (opts.meal && !isToday) ctx.time = { breakfast: '07:30', snack1: '10:00', lunch: '12:30', snack2: '15:30', dinner: '18:30', other: '21:30' }[opts.meal];
  if (opts.meal && isToday && mealForTime(ctx.time) !== opts.meal) {
    ctx.time = { breakfast: '07:30', snack1: '10:00', lunch: '12:30', snack2: '15:30', dinner: '18:30', other: '21:30' }[opts.meal];
  }
  const sh = sheet('Pridať jedlo', { onClose: () => stopScanner() });
  ctx.sheet = sh;
  ctx.onSaved = () => { sh.close(); if (location.hash.replace(/^#\/?/, '') === '') { state.date = ctx.date; loadDay(); } };
  const tabs = [
    ['search', '🔍 Hľadať'],
    ['photo', '📷 Fotka'],
    ['text', '✍️ Popis (AI)'],
    ['barcode', '▦ Kód'],
    ['manual', '✎ Ručne'],
  ];
  sh.body.innerHTML = `${whenPicker(ctx)}<div class="tabs" role="tablist">${tabs.map(([k, l]) => `<button role="tab" data-tab="${k}">${l}</button>`).join('')}</div><div id="addPane"></div>`;
  bindWhen(sh.body, ctx);
  const pane = $('#addPane', sh.body);
  const select = (k) => {
    stopScanner();
    $$('[data-tab]', sh.body).forEach((b) => b.classList.toggle('on', b.dataset.tab === k));
    ({ search: paneSearch, photo: panePhoto, text: paneText, barcode: paneBarcode, manual: paneManual })[k](pane, ctx);
  };
  $$('[data-tab]', sh.body).forEach((b) => { b.onclick = () => select(b.dataset.tab); });
  select(opts.tab || 'search');
}

function foodLine(f) {
  const serving = f.serving_g ? ` · ${esc(f.serving_name || 'porcia')} ${f1(f.serving_g)} g` : '';
  const badge = f.is_recipe ? '<span class="chip accent">recept</span> ' : f.is_own ? '<span class="chip">moja</span> ' : '';
  return `<div class="grow"><div class="t ellipsis">${f.favorite ? '★ ' : ''}${esc(f.name)}</div>
    <div class="s ellipsis">${badge}${f.brand ? esc(f.brand) + ' · ' : ''}${f0(f.kcal)} kcal/100 g${serving}</div></div>`;
}

async function paneSearch(pane, ctx) {
  pane.innerHTML = `<input class="input" id="sq" type="search" placeholder="Napr. jogurt, chlieb, banán…" autocomplete="off" enterkeyhint="search">
    <div id="sres" style="margin-top:10px"></div>`;
  const q = $('#sq', pane);
  const res = $('#sres', pane);
  setTimeout(() => q.focus(), 50);
  let recent = null;
  const showRecent = async () => {
    if (!recent) {
      res.innerHTML = '<div class="center" style="padding:20px"><span class="spinner"></span></div>';
      try { recent = await api('GET', '/foods/recent'); } catch (e) { showError(e); return; }
    }
    if (q.value.trim().length >= 2) return;
    let html = '';
    if (recent.favorites.length) {
      html += `<div class="section-label">★ Obľúbené</div><div class="list">${recent.favorites.map((f, i) => `<div class="li" data-fav="${i}">${foodLine(f)}</div>`).join('')}</div>`;
    }
    if (recent.recent.length) {
      html += `<div class="section-label">Naposledy jedené</div><div class="list">${recent.recent.map((r, i) => r.type === 'food'
        ? `<div class="li" data-rec="${i}">${foodLine(r.food)}<div class="r muted small">${r.last_amount_g ? f0(r.last_amount_g) + ' g' : ''}</div></div>`
        : `<div class="li" data-rec="${i}"><div class="grow"><div class="t ellipsis">${esc(r.entry.name)}</div>
            <div class="s">${r.entry.amount_g ? f0(r.entry.amount_g) + ' g · ' : ''}${f0(r.entry.kcal)} kcal</div></div></div>`).join('')}</div>`;
    }
    if (!html) html = `<div class="empty center" style="padding:24px 0">Začnite písať názov potraviny.<br>Hľadá sa vo vašich potravinách a v databáze Open Food Facts.</div>`;
    res.innerHTML = html;
    $$('[data-fav]', res).forEach((el) => { el.onclick = () => openAmount(recent.favorites[+el.dataset.fav], ctx); });
    $$('[data-rec]', res).forEach((el) => {
      const r = recent.recent[+el.dataset.rec];
      el.onclick = () => (r.type === 'food' ? openAmount(r.food, ctx, r.last_amount_g) : openRepeatEntry(r.entry, ctx));
    });
  };
  const runSearch = debounce(async () => {
    const term = q.value.trim();
    if (term.length < 2) { showRecent(); return; }
    let local = [];
    try { local = (await api('GET', `/foods/search?q=${encodeURIComponent(term)}`)).foods; } catch (e) { showError(e); return; }
    if (q.value.trim() !== term) return;
    res.innerHTML = `${local.length ? `<div class="list" id="lres">${local.map((f, i) => `<div class="li" data-i="${i}">${foodLine(f)}</div>`).join('')}</div>`
      : '<div class="empty">Vo vašej databáze nič. Skúste vyhľadať online:</div>'}
      <div class="grid2" style="margin-top:10px">
        <button class="btn" id="sOnline">🌐 Hľadať online</button>
        <button class="btn" id="sAi" ${state.aiEnabled ? '' : 'disabled'}>✨ Vypočítať cez AI</button>
      </div><div id="ores"></div>`;
    $$('#lres [data-i]', res).forEach((el) => { el.onclick = () => openAmount(local[+el.dataset.i], ctx); });
    $('#sAi', res).onclick = () => { $$('[data-tab]', ctx.sheet.body).forEach((b) => b.classList.toggle('on', b.dataset.tab === 'text')); paneText(pane, ctx, term); };
    $('#sOnline', res).onclick = async (e) => {
      await withBusy(e.currentTarget, async () => {
        try {
          const online = (await api('GET', `/foods/online?q=${encodeURIComponent(term)}`)).foods;
          const known = new Set(local.map((f) => f.id));
          const fresh = online.filter((f) => !known.has(f.id));
          const o = $('#ores', res);
          o.innerHTML = fresh.length
            ? `<div class="section-label">Open Food Facts</div><div class="list">${fresh.map((f, i) => `<div class="li" data-o="${i}">${foodLine(f)}</div>`).join('')}</div>`
            : '<div class="empty" style="margin-top:10px">Online sa nič nenašlo. Skúste iný názov, AI výpočet alebo zadajte ručne.</div>';
          $$('[data-o]', o).forEach((el) => { el.onclick = () => openAmount(fresh[+el.dataset.o], ctx); });
        } catch (ex) { showError(ex); }
      });
    };
  }, 250);
  q.addEventListener('input', runSearch);
  showRecent();
}

/** Zadanie množstva pre potravinu (gramy alebo porcie). */
function openAmount(food, ctx, lastG) {
  const sh = sheet(food.name);
  const hasServing = food.serving_g > 0;
  let unit = hasServing && !lastG ? 'serving' : 'g';
  sh.body.innerHTML = `${food.brand ? `<p class="muted small" style="margin-top:0">${esc(food.brand)}</p>` : ''}
    <div class="row" style="align-items:flex-end">
      <div class="grow"><label class="f" for="amt">Množstvo</label>
        <input class="input num" id="amt" inputmode="decimal" value=""></div>
      ${hasServing ? `<div class="seg" style="flex:1.3"><button data-u="g">gramy</button><button data-u="serving">${esc(food.serving_name || 'porcia')}</button></div>` : '<div class="muted" style="padding-bottom:12px">g</div>'}
    </div>
    <div class="row wrap" style="margin-top:8px" id="quick"></div>
    <div class="section-label">Nutričné hodnoty</div>
    <div class="nutr-preview num" id="prev"></div>
    <p class="muted tiny" style="margin-top:8px">Na 100 g: ${f0(food.kcal)} kcal · B ${f1(food.protein)} g · S ${f1(food.carbs)} g${food.sugar != null ? ` (cukry ${f1(food.sugar)} g)` : ''} · T ${f1(food.fat)} g · V ${f1(food.fiber)} g</p>
    <button class="btn sm ghost" id="fav">${food.favorite ? '★ V obľúbených' : '☆ Pridať do obľúbených'}</button>`;
  setFoot(sh, `<button class="btn primary block" id="save">Pridať do: ${esc(MEALS[ctx.meal])}</button>`);
  const amt = $('#amt', sh.body);
  const grams = () => { const v = num(amt.value) || 0; return unit === 'serving' ? v * food.serving_g : v; };
  const quick = () => {
    const opts = unit === 'serving' ? [0.5, 1, 1.5, 2] : [50, 100, 150, 200, 250];
    $('#quick', sh.body).innerHTML = opts.map((o) => `<button class="btn sm" data-q="${o}">${unit === 'serving' ? f1(o) + '×' : o + ' g'}</button>`).join('');
    $$('[data-q]', sh.body).forEach((b) => { b.onclick = () => { amt.value = String(b.dataset.q).replace('.', ','); update(); }; });
  };
  const update = () => {
    const g = grams();
    const r = g / 100;
    $('#prev', sh.body).innerHTML = `<div><b>${f0(food.kcal * r)}</b><span>kcal</span></div><div><b>${f1(food.protein * r)}</b><span>bielk.</span></div>
      <div><b>${f1(food.carbs * r)}</b><span>sach.</span></div><div><b>${f1(food.fat * r)}</b><span>tuky</span></div><div><b>${f1(food.fiber * r)}</b><span>vlákn.</span></div>`;
  };
  const setUnit = (u) => {
    const g = grams();
    unit = u;
    $$('[data-u]', sh.body).forEach((b) => b.classList.toggle('on', b.dataset.u === u));
    if (g > 0) amt.value = String(u === 'serving' ? Math.round(g / food.serving_g * 100) / 100 : Math.round(g)).replace('.', ',');
    quick(); update();
  };
  amt.value = unit === 'serving' ? '1' : String(Math.round(lastG || 100));
  $$('[data-u]', sh.body).forEach((b) => { b.onclick = () => setUnit(b.dataset.u); });
  setUnit(unit);
  amt.addEventListener('input', update);
  setTimeout(() => { amt.focus(); amt.select(); }, 60);
  $('#fav', sh.body).onclick = async (e) => {
    try {
      const r = await api('POST', `/foods/${food.id}/favorite`);
      food.favorite = r.favorite;
      e.target.textContent = r.favorite ? '★ V obľúbených' : '☆ Pridať do obľúbených';
    } catch (ex) { showError(ex); }
  };
  $('#save', sh.foot).onclick = (e) => withBusy(e.currentTarget, async () => {
    const g = grams();
    if (!(g > 0)) { toast('Zadajte množstvo', true); return; }
    try {
      await api('POST', '/entries', { date: ctx.date, time: ctx.time, meal: ctx.meal, food_id: food.id, amount_g: Math.round(g * 10) / 10, source: 'food' });
      toast(`Pridané: ${food.name}`);
      sh.close();
      ctx.onSaved();
    } catch (ex) { showError(ex); }
  });
}

/** Zopakovanie predchádzajúceho AI/ručného záznamu (s možnosťou zmeniť gramy). */
function openRepeatEntry(entry, ctx) {
  reviewItems(ctx, [{ ...entry, source: entry.source === 'ai_photo' ? 'ai_text' : entry.source }], '', null, 'Pridať znova');
}

function panePhoto(pane, ctx) {
  if (!state.aiEnabled) { pane.innerHTML = aiDisabledBox(); return; }
  let file = null;
  pane.innerHTML = `<div class="seg" id="kind"><button data-k="meal" class="on">🍽️ Jedlo na tanieri</button><button data-k="menu">📋 Položka z menu</button></div>
    <div class="grid2" style="margin-top:12px">
      <label class="btn primary">📷 Odfotiť<input type="file" accept="image/*" capture="environment" hidden id="pCam"></label>
      <label class="btn">🖼️ Z galérie<input type="file" accept="image/*" hidden id="pGal"></label>
    </div>
    <div id="pPrev" style="margin-top:12px"></div>
    <label class="f" for="pDesc">Popis (nepovinné)</label>
    <textarea class="input" id="pDesc" placeholder="Napr. polovičná porcia, bez omáčky, 2 knedle navyše…"></textarea>
    <button class="btn primary block" id="pGo" style="margin-top:12px" disabled>✨ Vyhodnotiť fotku</button>
    <p class="muted tiny">Fotka sa pošle na vyhodnotenie do Claude AI. Výsledok si pred uložením skontrolujete a upravíte.</p>`;
  let kind = 'meal';
  $$('[data-k]', pane).forEach((b) => { b.onclick = () => { kind = b.dataset.k; $$('[data-k]', pane).forEach((x) => x.classList.toggle('on', x === b)); }; });
  const onFile = async (e) => {
    const f = e.target.files[0];
    if (!f) return;
    $('#pPrev', pane).innerHTML = '<div class="center"><span class="spinner"></span></div>';
    file = await resizeImage(f);
    $('#pPrev', pane).innerHTML = `<img class="photo-preview" src="${URL.createObjectURL(file)}" alt="Náhľad fotky">`;
    $('#pGo', pane).disabled = false;
  };
  $('#pCam', pane).onchange = onFile;
  $('#pGal', pane).onchange = onFile;
  $('#pGo', pane).onclick = async (e) => {
    const btn = e.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner sm"></span> Analyzujem… (5–30 s)';
    try {
      const fd = new FormData();
      fd.append('image', file, 'foto.jpg');
      fd.append('kind', kind);
      fd.append('description', $('#pDesc', pane).value);
      const r = await api('POST', '/ai/photo', fd);
      reviewItems(ctx, r.items.map((i) => ({ ...i, source: 'ai_photo' })), r.comment, r.photo);
    } catch (ex) { showError(ex); }
    if (btn.isConnected) { btn.disabled = false; btn.textContent = '✨ Vyhodnotiť fotku'; }
  };
}

function paneText(pane, ctx, preset = '') {
  if (!state.aiEnabled) { pane.innerHTML = aiDisabledBox(); return; }
  pane.innerHTML = `<label class="f" style="margin-top:0" for="tTxt">Čo ste jedli?</label>
    <textarea class="input" id="tTxt" rows="4" placeholder="Napr.: 2 vajcia natvrdo, krajec chleba s maslom a šunkou, biela káva">${esc(preset)}</textarea>
    <button class="btn primary block" id="tGo" style="margin-top:12px">✨ Vypočítať</button>
    <p class="muted tiny">Uveďte čo najviac detailov – množstvo, gramáž, spôsob prípravy. AI odhadne kalórie a makroživiny.</p>`;
  const ta = $('#tTxt', pane);
  setTimeout(() => ta.focus(), 50);
  $('#tGo', pane).onclick = async (e) => {
    const btn = e.currentTarget;
    if (ta.value.trim().length < 2) { toast('Napíšte, čo ste jedli', true); return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner sm"></span> Počítam…';
    try {
      const r = await api('POST', '/ai/text', { text: ta.value });
      reviewItems(ctx, r.items.map((i) => ({ ...i, source: 'ai_text' })), r.comment, null);
    } catch (ex) { showError(ex); }
    if (btn.isConnected) { btn.disabled = false; btn.textContent = '✨ Vypočítať'; }
  };
}

function aiDisabledBox() {
  return `<div class="info-box">AI rozpoznávanie nie je zapnuté. Administrátor musí doplniť Anthropic API kľúč do súboru <code>app/config.php</code>.</div>`;
}

/** Kontrola a úprava položiek navrhnutých AI pred uložením. */
function reviewItems(ctx, items, comment, photo, title = 'Skontrolujte a uložte') {
  const sh = sheet(title);
  const rows = items.map((it) => ({ ...it, base: { ...it } }));
  const KEYS = ['kcal', 'protein', 'carbs', 'fat', 'fiber'];
  const render = () => {
    const tot = KEYS.reduce((o, k) => ({ ...o, [k]: rows.reduce((s, r) => s + (num(r[k]) || 0), 0) }), {});
    sh.body.innerHTML = `${photo ? `<img class="photo-preview" src="${photoUrl(photo)}" alt="" style="margin-bottom:10px">` : ''}
      ${comment ? `<div class="info-box" style="margin-bottom:10px">💬 ${esc(comment)}</div>` : ''}
      ${!rows.length ? '<div class="empty">Žiadne jedlo nebolo rozpoznané. Skúste inú fotku alebo doplňte popis.</div>' : ''}
      ${rows.map((r, i) => `<div class="ai-item" data-i="${i}">
        <div class="top"><input class="input sm grow" data-f="name" value="${esc(r.name)}" aria-label="Názov">
          ${r.confidence === 'low' ? '<span class="chip warn" title="Nízka istota odhadu">?</span>' : ''}
          <button class="icon-btn" data-del aria-label="Odstrániť položku">🗑</button></div>
        <div class="vals">
          <div><label>gramy</label><input class="input sm num" data-f="amount_g" inputmode="decimal" value="${r.amount_g ? f0(r.amount_g) : ''}"></div>
          <div><label>kcal</label><input class="input sm num" data-f="kcal" inputmode="decimal" value="${f0(r.kcal)}"></div>
          <div><label>bielk.</label><input class="input sm num" data-f="protein" inputmode="decimal" value="${f1(r.protein)}"></div>
          <div><label>sach.</label><input class="input sm num" data-f="carbs" inputmode="decimal" value="${f1(r.carbs)}"></div>
          <div><label>tuky</label><input class="input sm num" data-f="fat" inputmode="decimal" value="${f1(r.fat)}"></div>
          <div><label>vlákn.</label><input class="input sm num" data-f="fiber" inputmode="decimal" value="${f1(r.fiber)}"></div>
        </div></div>`).join('')}
      ${rows.length ? `<div class="section-label">Spolu</div><div class="nutr-preview num" id="rtot">${totHtml(tot)}</div>
        <p class="muted tiny">Zmena gramov prepočíta ostatné hodnoty položky. Hodnoty môžete upraviť aj priamo.</p>` : ''}`;
    $$('.ai-item', sh.body).forEach((el) => {
      const r = rows[+el.dataset.i];
      $('[data-del]', el).onclick = () => { rows.splice(+el.dataset.i, 1); render(); };
      $$('[data-f]', el).forEach((inp) => {
        inp.addEventListener('input', () => {
          const f = inp.dataset.f;
          if (f === 'name') { r.name = inp.value; return; }
          r[f] = num(inp.value);
          if (f === 'amount_g' && r.base.amount_g > 0 && r.amount_g > 0) {
            const k = r.amount_g / r.base.amount_g;
            KEYS.forEach((key) => {
              r[key] = Math.round(r.base[key] * k * 10) / 10;
              $(`[data-f="${key}"]`, el).value = key === 'kcal' ? f0(r[key]) : f1(r[key]);
            });
          }
          const t = KEYS.reduce((o, k) => ({ ...o, [k]: rows.reduce((s, x) => s + (num(x[k]) || 0), 0) }), {});
          $('#rtot', sh.body).innerHTML = totHtml(t);
        });
      });
    });
    setFoot(sh, rows.length ? `<button class="btn primary block" id="rSave">Uložiť do: ${esc(MEALS[ctx.meal])}</button>` : '');
    const save = $('#rSave', sh.foot);
    if (save) {
      save.onclick = (e) => withBusy(e.currentTarget, async () => {
        try {
          await api('POST', '/entries', {
            date: ctx.date, time: ctx.time, meal: ctx.meal, photo,
            items: rows.map((r) => ({
              name: r.name, amount_g: num(r.amount_g), kcal: num(r.kcal) || 0, protein: num(r.protein) || 0,
              carbs: num(r.carbs) || 0, fat: num(r.fat) || 0, fiber: num(r.fiber) || 0, source: r.source || 'manual',
            })),
          });
          toast('Uložené');
          sh.close();
          ctx.onSaved();
        } catch (ex) { showError(ex); }
      });
    }
  };
  render();
}
function totHtml(t) {
  return `<div><b>${f0(t.kcal)}</b><span>kcal</span></div><div><b>${f1(t.protein)}</b><span>bielk.</span></div>
    <div><b>${f1(t.carbs)}</b><span>sach.</span></div><div><b>${f1(t.fat)}</b><span>tuky</span></div><div><b>${f1(t.fiber)}</b><span>vlákn.</span></div>`;
}

// --- Čiarový kód ---
let scanStream = null, scanTimer = null, zxingControls = null;
function stopScanner() {
  if (scanTimer) { clearInterval(scanTimer); scanTimer = null; }
  if (zxingControls) { try { zxingControls.stop(); } catch (e) { /* */ } zxingControls = null; }
  if (scanStream) { scanStream.getTracks().forEach((t) => t.stop()); scanStream = null; }
}
function loadScript(src) {
  return new Promise((ok, fail) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = fail; document.head.appendChild(s); });
}
function paneBarcode(pane, ctx) {
  pane.innerHTML = `<div class="scanner hidden" id="scan"><video id="vid" playsinline muted></video><div class="frame"></div></div>
    <button class="btn primary block" id="bStart">📷 Skenovať kamerou</button>
    <label class="f" for="bCode">alebo zadajte číslo pod čiarovým kódom</label>
    <div class="row"><input class="input grow num" id="bCode" inputmode="numeric" placeholder="8580000000000"><button class="btn" id="bFind">Hľadať</button></div>
    <div id="bRes" style="margin-top:12px"></div>`;
  const lookup = async (code) => {
    stopScanner();
    $('#scan', pane).classList.add('hidden');
    $('#bCode', pane).value = code;
    const res = $('#bRes', pane);
    res.innerHTML = '<div class="center"><span class="spinner"></span></div>';
    try {
      const r = await api('GET', `/foods/barcode/${encodeURIComponent(code)}`);
      if (r.food) { res.innerHTML = ''; openAmount(r.food, ctx); return; }
      res.innerHTML = `<div class="info-box">Produkt s kódom <b>${esc(code)}</b> sa v databáze nenašiel.</div>
        <button class="btn block" id="bNew" style="margin-top:10px">+ Vytvoriť potravinu s týmto kódom</button>`;
      $('#bNew', res).onclick = () => openFoodForm({ barcode: code }, (food) => openAmount(food, ctx));
    } catch (ex) { res.innerHTML = ''; showError(ex); }
  };
  $('#bFind', pane).onclick = () => { const c = $('#bCode', pane).value.replace(/\D/g, ''); if (c.length >= 6) lookup(c); else toast('Zadajte platný kód', true); };
  $('#bStart', pane).onclick = async () => {
    const video = $('#vid', pane);
    try {
      $('#scan', pane).classList.remove('hidden');
      $('#bStart', pane).classList.add('hidden');
      if ('BarcodeDetector' in window) {
        scanStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        video.srcObject = scanStream;
        await video.play();
        const det = new window.BarcodeDetector({ formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128'] });
        scanTimer = setInterval(async () => {
          try {
            const codes = await det.detect(video);
            if (codes.length) { navigator.vibrate?.(60); lookup(codes[0].rawValue); }
          } catch (e) { /* ďalší snímok */ }
        }, 250);
      } else {
        // iPhone / Firefox: knižnica ZXing
        if (!window.ZXingBrowser) await loadScript('assets/vendor/zxing-browser.min.js');
        const reader = new window.ZXingBrowser.BrowserMultiFormatReader();
        zxingControls = await reader.decodeFromVideoDevice(undefined, video, (result) => {
          if (result) { navigator.vibrate?.(60); lookup(result.getText()); }
        });
      }
    } catch (e) {
      stopScanner();
      $('#scan', pane).classList.add('hidden');
      $('#bStart', pane).classList.remove('hidden');
      toast('Kameru sa nepodarilo spustiť – povoľte prístup alebo zadajte kód ručne', true);
    }
  };
}

function paneManual(pane, ctx) {
  pane.innerHTML = `<label class="f" style="margin-top:0" for="mName">Názov</label><input class="input" id="mName" placeholder="Napr. Káva s mliekom">
    <div class="grid2"><div><label class="f" for="mKcal">Kalórie (kcal)</label><input class="input num" id="mKcal" inputmode="decimal"></div>
      <div><label class="f" for="mG">Množstvo (g) – nepovinné</label><input class="input num" id="mG" inputmode="decimal"></div></div>
    <div class="grid2"><div><label class="f" for="mP">Bielkoviny (g)</label><input class="input num" id="mP" inputmode="decimal"></div>
      <div><label class="f" for="mC">Sacharidy (g)</label><input class="input num" id="mC" inputmode="decimal"></div>
      <div><label class="f" for="mF">Tuky (g)</label><input class="input num" id="mF" inputmode="decimal"></div>
      <div><label class="f" for="mV">Vláknina (g)</label><input class="input num" id="mV" inputmode="decimal"></div></div>
    <button class="btn primary block" id="mSave" style="margin-top:16px">Pridať do: ${esc(MEALS[ctx.meal])}</button>`;
  $('#mSave', pane).onclick = (e) => withBusy(e.currentTarget, async () => {
    try {
      await api('POST', '/entries', {
        date: ctx.date, time: ctx.time, meal: ctx.meal, name: $('#mName', pane).value, source: 'manual',
        kcal: num($('#mKcal', pane).value), amount_g: num($('#mG', pane).value), protein: num($('#mP', pane).value) || 0,
        carbs: num($('#mC', pane).value) || 0, fat: num($('#mF', pane).value) || 0, fiber: num($('#mV', pane).value) || 0,
      });
      toast('Pridané');
      ctx.onSaved();
    } catch (ex) { showError(ex); }
  });
}

/** Úprava existujúceho záznamu. */
function openEditEntry(e) {
  if (!e) return;
  const sh = sheet('Upraviť záznam');
  sh.body.innerHTML = `${e.photo ? `<img class="photo-preview" src="${photoUrl(e.photo)}" alt="" style="margin-bottom:8px">` : ''}
    <label class="f" style="margin-top:0" for="eName">Názov</label><input class="input" id="eName" value="${esc(e.name)}">
    <div class="grid2"><div><label class="f" for="eMeal">Jedlo dňa</label><select class="input" id="eMeal">${MEAL_ORDER.map((m) => `<option value="${m}" ${m === e.meal ? 'selected' : ''}>${MEALS[m]}</option>`).join('')}</select></div>
      <div><label class="f" for="eTime">Čas</label><input class="input" type="time" id="eTime" value="${e.time}"></div></div>
    <label class="f" for="eG">Množstvo (g)</label><input class="input num" id="eG" inputmode="decimal" value="${e.amount_g ? f1(e.amount_g) : ''}" ${e.amount_g ? '' : 'placeholder="neuvedené"'}>
    <div class="grid3" style="margin-top:4px">
      <div><label class="f" for="eK">kcal</label><input class="input num" id="eK" inputmode="decimal" value="${f1(e.kcal)}"></div>
      <div><label class="f" for="eP">Bielk. g</label><input class="input num" id="eP" inputmode="decimal" value="${f1(e.protein)}"></div>
      <div><label class="f" for="eC">Sach. g</label><input class="input num" id="eC" inputmode="decimal" value="${f1(e.carbs)}"></div>
      <div><label class="f" for="eF">Tuky g</label><input class="input num" id="eF" inputmode="decimal" value="${f1(e.fat)}"></div>
      <div><label class="f" for="eV">Vlákn. g</label><input class="input num" id="eV" inputmode="decimal" value="${f1(e.fiber)}"></div>
    </div>
    <p class="muted tiny">Pri zmene gramov sa hodnoty prepočítajú pomerne.</p>`;
  setFoot(sh, `<div class="grid2"><button class="btn danger" id="eDel">Zmazať</button><button class="btn primary" id="eSave">Uložiť</button></div>`);
  const g0 = e.amount_g;
  const base = { kcal: e.kcal, protein: e.protein, carbs: e.carbs, fat: e.fat, fiber: e.fiber };
  const ids = { kcal: 'eK', protein: 'eP', carbs: 'eC', fat: 'eF', fiber: 'eV' };
  $('#eG', sh.body).addEventListener('input', () => {
    const g = num($('#eG', sh.body).value);
    if (g0 > 0 && g > 0) Object.entries(ids).forEach(([k, id]) => { $('#' + id, sh.body).value = f1(base[k] * g / g0); });
  });
  $('#eSave', sh.foot).onclick = (ev) => withBusy(ev.currentTarget, async () => {
    const payload = {
      name: $('#eName', sh.body).value, meal: $('#eMeal', sh.body).value, time: $('#eTime', sh.body).value,
      amount_g: num($('#eG', sh.body).value),
    };
    Object.entries(ids).forEach(([k, id]) => { payload[k] = num($('#' + id, sh.body).value) || 0; });
    try { await api('PUT', `/entries/${e.id}`, payload); toast('Uložené'); sh.close(); loadDay(); } catch (ex) { showError(ex); }
  });
  $('#eDel', sh.foot).onclick = async () => {
    if (!await confirmBox(`Zmazať „${e.name}“?`, 'Zmazať', true)) return;
    try { await api('DELETE', `/entries/${e.id}`); toast('Zmazané'); sh.close(); loadDay(); } catch (ex) { showError(ex); }
  };
}

/** Pridanie tréningu – screenshot z Garmin Connect (AI) alebo ručne. */
function openTraining(date, onDone) {
  const sh = sheet(`Tréning · ${dayLabel(date)}`);
  sh.body.innerHTML = `<p class="muted small" style="margin-top:0">Spálené kalórie z tréningu sa pripočítajú k dennému cieľu
      (bielkoviny ostávajú, navýšia sa sacharidy a tuky).</p>
    ${state.aiEnabled ? `<label class="btn primary block">📱 Nahrať screenshot z Garmin Connect<input type="file" accept="image/*" hidden id="tShot"></label>
      <p class="muted tiny">Najlepšie obrazovka aktivity, na ktorej sú vidieť „Kalórie“ alebo „Aktívne kalórie“.</p>` : ''}
    <div id="tAi"></div>
    <div class="section-label">${state.aiEnabled ? 'alebo zadajte ručne' : 'Zadajte údaje'}</div>
    <label class="f" for="tName">Aktivita</label><input class="input" id="tName" placeholder="Beh, bicykel, posilňovňa…">
    <div class="grid2"><div><label class="f" for="tKcal">Spálené kcal</label><input class="input num" id="tKcal" inputmode="numeric"></div>
      <div><label class="f" for="tDur">Trvanie (min)</label><input class="input num" id="tDur" inputmode="numeric"></div></div>
    <label class="f" for="tDate">Dátum</label><input class="input" type="date" id="tDate" value="${date}" max="${todayStr()}">`;
  setFoot(sh, '<button class="btn primary block" id="tSave">Uložiť tréning</button>');
  let photo = null;
  const shot = $('#tShot', sh.body);
  if (shot) {
    shot.onchange = async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      const box = $('#tAi', sh.body);
      box.innerHTML = '<div class="info-box"><span class="spinner sm"></span> Čítam screenshot…</div>';
      try {
        const fd = new FormData();
        fd.append('image', await resizeImage(f), 'garmin.jpg');
        const r = await api('POST', '/ai/activity', fd);
        photo = r.photo;
        $('#tName', sh.body).value = r.name;
        $('#tKcal', sh.body).value = r.kcal;
        $('#tDur', sh.body).value = r.duration_min || '';
        if (r.date && r.date <= todayStr()) $('#tDate', sh.body).value = r.date;
        box.innerHTML = `<div class="info-box" style="margin-top:10px">✔ Načítané: <b>${esc(r.name)}</b>, ${f0(r.kcal)} kcal. ${esc(r.comment || '')}<br>Skontrolujte údaje nižšie a uložte.</div>`;
      } catch (ex) { box.innerHTML = ''; showError(ex); }
    };
  }
  $('#tSave', sh.foot).onclick = (e) => withBusy(e.currentTarget, async () => {
    try {
      await api('POST', '/activities', {
        date: $('#tDate', sh.body).value, name: $('#tName', sh.body).value || 'Tréning',
        kcal: num($('#tKcal', sh.body).value), duration_min: num($('#tDur', sh.body).value), photo,
      });
      toast('Tréning uložený – denný cieľ bol navýšený');
      sh.close();
      onDone && onDone();
    } catch (ex) { showError(ex); }
  });
}

// ---------- Reporty ----------
function periodRange(mode, anchor) {
  const d = parseDate(anchor);
  if (mode === 'day') return [anchor, anchor];
  if (mode === 'week') {
    const dow = (d.getDay() + 6) % 7; // pondelok = 0
    const from = addDays(anchor, -dow);
    return [from, addDays(from, 6)];
  }
  const from = dateStr(new Date(d.getFullYear(), d.getMonth(), 1));
  const to = dateStr(new Date(d.getFullYear(), d.getMonth() + 1, 0));
  return [from, to];
}
function periodLabel(mode, from, to) {
  if (mode === 'day') return dayLabel(from) === fmtDate(from) ? fmtDate(from, true) : `${dayLabel(from)} · ${fmtDate(from)}`;
  if (mode === 'week') {
    const a = parseDate(from), b = parseDate(to);
    return `${a.getDate()}. ${a.getMonth() + 1}. – ${b.getDate()}. ${b.getMonth() + 1}. ${b.getFullYear()}`;
  }
  const a = parseDate(from);
  return `${MONTHS[a.getMonth()]} ${a.getFullYear()}`;
}

function viewReports(page) {
  page.innerHTML = `<div class="topbar"><h1>Reporty</h1></div>
    <div class="seg" id="rMode"><button data-m="day">Deň</button><button data-m="week">Týždeň</button><button data-m="month">Mesiac</button></div>
    <div class="daybar" style="margin-top:8px"><button class="icon-btn" id="rPrev" aria-label="Predchádzajúce obdobie">‹</button>
      <div class="lbl"><strong id="rLbl"></strong></div><button class="icon-btn" id="rNext" aria-label="Nasledujúce obdobie">›</button></div>
    <div id="rBody"></div>`;
  const draw = () => {
    $$('#rMode button').forEach((b) => b.classList.toggle('on', b.dataset.m === state.reportMode));
    const [from, to] = periodRange(state.reportMode, state.reportAnchor);
    $('#rLbl').textContent = periodLabel(state.reportMode, from, to);
    const atEnd = to >= todayStr();
    $('#rNext').disabled = atEnd;
    $('#rNext').style.opacity = atEnd ? 0.3 : 1;
    loadReport(from, to);
  };
  const shift = (dir) => {
    const m = state.reportMode;
    const d = parseDate(state.reportAnchor);
    if (m === 'day') state.reportAnchor = addDays(state.reportAnchor, dir);
    else if (m === 'week') state.reportAnchor = addDays(state.reportAnchor, 7 * dir);
    else state.reportAnchor = dateStr(new Date(d.getFullYear(), d.getMonth() + dir, 1));
    if (state.reportAnchor > todayStr()) state.reportAnchor = todayStr();
    draw();
  };
  $$('#rMode button').forEach((b) => { b.onclick = () => { state.reportMode = b.dataset.m; draw(); }; });
  $('#rPrev').onclick = () => shift(-1);
  $('#rNext').onclick = () => shift(1);
  draw();
}

async function loadReport(from, to) {
  const body = $('#rBody');
  body.innerHTML = '<div class="boot" style="min-height:30vh"><div class="spinner"></div></div>';
  const key = from + to + state.reportMode;
  body.dataset.key = key;
  try {
    if (state.reportMode === 'day') {
      const d = await api('GET', `/day?date=${from}`);
      if (body.dataset.key !== key) return;
      const evalTxt = from === todayStr() ? '<div class="info-box" style="margin-bottom:12px">Deň ešte prebieha – hodnotenie je priebežné.</div>' : '';
      renderDay(body, d, false);
      body.insertAdjacentHTML('afterbegin', evalTxt);
      if (!d.entries.length) body.insertAdjacentHTML('afterbegin', '<div class="info-box" style="margin-bottom:12px">V tento deň nie sú žiadne záznamy.</div>');
      return;
    }
    const r = await api('GET', `/reports?from=${from}&to=${to}`);
    if (body.dataset.key !== key) return;
    renderReport(body, r);
  } catch (e) { showError(e); }
}

function renderReport(body, r) {
  const s = r.summary;
  const days = r.days.filter((d) => !d.future);
  const chartDays = r.days.map((d) => ({
    date: d.date, future: !!d.future, logged: d.logged, kcal: d.totals?.kcal || 0, goal: d.goal?.kcal || 0,
    inProgress: d.in_progress, status: d.logged && d.goal ? statusOf('max', d.totals.kcal, d.goal.kcal) : 'none',
  }));
  const avg = s.average, ag = s.average_goal;
  const c = s.counts.kcal;
  const avgSt = avg && ag ? statusOf('max', avg.kcal, ag.kcal) : 'none';
  body.innerHTML = '';
  if (!s.days_evaluated) {
    body.insertAdjacentHTML('beforeend', `<div class="info-box" style="margin-bottom:12px">V tomto období zatiaľ nie sú žiadne ukončené dni so záznamom.
      ${days.some((d) => d.in_progress && d.logged) ? 'Dnešný deň ešte prebieha.' : ''}</div>`);
  }
  body.insertAdjacentHTML('beforeend', `<div class="tiles">
      <div class="tile s-${avgSt}"><div class="k">Priemer kalórií / deň</div><div class="v num">${avg ? f0(avg.kcal) : '–'}</div>
        <div class="d">${ag ? `cieľ ${f0(ag.kcal)} · <span class="dot"></span> ${avg.kcal <= ag.kcal ? `o ${f0(ag.kcal - avg.kcal)} menej` : `o ${f0(avg.kcal - ag.kcal)} viac`}` : '&nbsp;'}</div></div>
      <div class="tile"><div class="k">Dni v cieli (kalórie)</div><div class="v num">${c.in}<span class="muted" style="font-size:1rem"> / ${s.days_evaluated}</span></div>
        <div class="d">${c.over ? `<span class="s-bad"><span class="dot"></span></span> nad cieľom: <b>${c.over}</b>` : 'žiadny deň nad cieľom'}</div></div>
      <div class="tile"><div class="k">Zaznamenané dni</div><div class="v num">${s.days_evaluated}<span class="muted" style="font-size:1rem"> / ${days.filter((d) => !d.in_progress).length}</span></div>
        <div class="d">ukončené dni so záznamom</div></div>
      <div class="tile"><div class="k">Hmotnosť</div><div class="v num">${s.weight ? f1(s.weight.last) + ' kg' : '–'}</div>
        <div class="d">${s.weight ? `zmena ${s.weight.change > 0 ? '+' : ''}${f1(s.weight.change)} kg` : 'bez záznamu'}</div></div>
    </div>`);

  const chartCard = document.createElement('section');
  chartCard.className = 'card';
  chartCard.innerHTML = '<div class="card-title"><h2>Kalórie po dňoch</h2><span class="muted tiny">ťuknite na deň</span></div>';
  chartCard.appendChild(kcalChart(chartDays, (d) => { state.reportMode = 'day'; state.reportAnchor = d.date; viewReports(shell('reporty')); }));
  body.appendChild(chartCard);

  const rowsHtml = [{ k: 'kcal', name: 'Kalórie', unit: 'kcal', kind: 'max' }, ...NUTR].map((n) => {
    const cn = s.counts[n.k];
    const st = avg && ag ? statusOf(n.kind, avg[n.k], ag[n.k]) : 'none';
    const bad = n.kind === 'max' ? cn.over : cn.under;
    return `<tr><td><span class="s-${st}"><span class="dot"></span></span> ${n.name}</td>
      <td class="n">${avg ? f0(avg[n.k]) : '–'}</td><td class="n">${ag ? f0(ag[n.k]) : '–'}</td>
      <td class="n">${cn.in}</td><td class="n">${bad}${n.kind === 'max' ? ' ▲' : ' ▼'}</td></tr>`;
  }).join('');
  body.insertAdjacentHTML('beforeend', `<section class="card"><div class="card-title"><h2>Priemer na deň vs. cieľ</h2></div>
    <table class="t"><thead><tr><th></th><th class="n">priemer</th><th class="n">cieľ</th><th class="n">v cieli</th><th class="n">mimo</th></tr></thead>
    <tbody>${rowsHtml}</tbody></table>
    <p class="muted tiny" style="margin-top:8px">▲ dni nad cieľom (kalórie, sacharidy, tuky – max. +5 %), ▼ dni pod cieľom (bielkoviny, vláknina – min. −5 %).
    ${s.water_avg != null ? `Priemerne vody: <b>${f1(s.water_avg / 1000)} l</b>/deň.` : ''} ${s.training_total ? `Tréningy spolu: <b>${f0(s.training_total)} kcal</b>.` : ''}</p></section>`);

  if (avg && ag) {
    body.insertAdjacentHTML('beforeend', `<section class="card"><div class="card-title"><h2>Makroživiny – priemer</h2></div>
      ${NUTR.map((n) => macroBar(n, avg[n.k], ag[n.k])).join('')}</section>`);
  }

  const weights = days.filter((d) => d.weight_kg != null).map((d) => ({ date: d.date, v: d.weight_kg }));
  if (weights.length) {
    const wc = document.createElement('section');
    wc.className = 'card';
    wc.innerHTML = '<div class="card-title"><h2>Hmotnosť</h2></div>';
    wc.appendChild(lineChart(weights));
    body.appendChild(wc);
  }

  const list = days.slice().reverse().map((d) => {
    const st = d.logged && d.goal ? statusOf('max', d.totals.kcal, d.goal.kcal) : 'none';
    return `<div class="li" data-d="${d.date}"><span class="s-${st}"><span class="dot"></span></span>
      <div class="grow"><div class="t">${fmtDate(d.date)}${d.in_progress ? ' <span class="chip">prebieha</span>' : ''}</div>
        <div class="s num">${d.logged ? `B ${f0(d.totals.protein)} · S ${f0(d.totals.carbs)} · T ${f0(d.totals.fat)} g` : 'bez záznamu'}${d.training_kcal ? ` · 🏃 +${f0(d.training_kcal)}` : ''}</div></div>
      <div class="r num">${d.logged ? f0(d.totals.kcal) : '–'}<div class="muted tiny">${d.goal ? '/ ' + f0(d.goal.kcal) : ''}</div></div></div>`;
  }).join('');
  body.insertAdjacentHTML('beforeend', `<div class="section-label">Jednotlivé dni</div><div class="list">${list}</div>`);
  $$('[data-d]', body).forEach((el) => { el.onclick = () => { state.reportMode = 'day'; state.reportAnchor = el.dataset.d; viewReports(shell('reporty')); }; });
}

// ---------- Telo: hmotnosť a tréningy ----------
async function viewBody(page) {
  page.innerHTML = `<div class="topbar"><h1>Telo</h1></div>
    <section class="card"><div class="card-title"><h2>⚖️ Hmotnosť</h2></div>
      <div class="row"><input class="input num grow" id="wKg" inputmode="decimal" placeholder="kg" aria-label="Hmotnosť v kg">
        <input class="input" type="date" id="wDate" value="${todayStr()}" max="${todayStr()}" style="width:auto;flex:1" aria-label="Dátum">
        <button class="btn primary" id="wSave">Uložiť</button></div>
      <div class="seg" id="wRange" style="margin-top:14px"><button data-r="30">30 dní</button><button data-r="90" class="on">3 mesiace</button><button data-r="365">rok</button></div>
      <div id="wChart" style="margin-top:12px"></div><div id="wList"></div></section>
    <section class="card"><div class="card-title"><h2>🏃 Tréningy</h2><button class="btn sm primary" id="tAdd">+ Pridať</button></div>
      <div id="tList"></div></section>`;
  let range = 90;
  const loadW = async () => {
    try {
      const r = await api('GET', `/weights?from=${addDays(todayStr(), -range)}&to=${todayStr()}`);
      const pts = r.weights.map((w) => ({ date: w.date, v: w.kg }));
      const ch = $('#wChart');
      ch.innerHTML = '';
      ch.appendChild(lineChart(pts));
      if (pts.length >= 2) {
        const ch1 = pts[pts.length - 1].v - pts[0].v;
        ch.insertAdjacentHTML('beforeend', `<p class="small" style="margin:8px 0 0">Zmena za obdobie: <b class="num">${ch1 > 0 ? '+' : ''}${f1(ch1)} kg</b></p>`);
      }
      $('#wList').innerHTML = r.weights.length ? `<div class="list" style="margin-top:10px">${r.weights.slice(-8).reverse().map((w) => `<div class="li">
        <div class="grow"><div class="t num">${f1(w.kg)} kg</div><div class="s">${fmtDate(w.date)}</div></div>
        <button class="icon-btn" data-wdel="${w.id}" aria-label="Zmazať">🗑</button></div>`).join('')}</div>` : '';
      $$('[data-wdel]').forEach((b) => {
        b.onclick = async (e) => {
          e.stopPropagation();
          if (!await confirmBox('Zmazať tento záznam hmotnosti?', 'Zmazať', true)) return;
          try { await api('DELETE', `/weights/${b.dataset.wdel}`); loadW(); } catch (ex) { showError(ex); }
        };
      });
    } catch (e) { showError(e); }
  };
  const loadT = async () => {
    try {
      const r = await api('GET', `/activities?from=${addDays(todayStr(), -60)}&to=${todayStr()}`);
      $('#tList').innerHTML = r.activities.length ? `<div class="list">${r.activities.map((a) => `<div class="li">
        <div class="grow"><div class="t">${esc(a.name)}</div><div class="s">${fmtDate(a.date)}${a.duration_min ? ` · ${a.duration_min} min` : ''}</div></div>
        <div class="r num">+${f0(a.kcal)} kcal</div><button class="icon-btn" data-adel="${a.id}" aria-label="Zmazať">🗑</button></div>`).join('')}</div>`
        : '<p class="empty">Zatiaľ žiadne tréningy za posledných 60 dní.</p>';
      $$('[data-adel]').forEach((b) => {
        b.onclick = async () => {
          if (!await confirmBox('Zmazať tréning? Denný cieľ sa zníži späť.', 'Zmazať', true)) return;
          try { await api('DELETE', `/activities/${b.dataset.adel}`); loadT(); } catch (ex) { showError(ex); }
        };
      });
    } catch (e) { showError(e); }
  };
  $('#wSave').onclick = (e) => withBusy(e.currentTarget, async () => {
    try {
      await api('POST', '/weights', { kg: num($('#wKg').value), date: $('#wDate').value });
      $('#wKg').value = '';
      toast('Hmotnosť uložená');
      loadW();
    } catch (ex) { showError(ex); }
  });
  $$('#wRange button').forEach((b) => {
    b.onclick = () => { range = +b.dataset.r; $$('#wRange button').forEach((x) => x.classList.toggle('on', x === b)); loadW(); };
  });
  $('#tAdd').onclick = () => openTraining(todayStr(), loadT);
  loadW();
  loadT();
}

// ---------- Viac ----------
function viewMore(page) {
  const u = state.user;
  page.innerHTML = `<div class="topbar"><h1>Viac</h1></div>
    <section class="card row"><div style="font-size:2rem">👤</div><div class="grow"><b>${esc(u.display_name)}</b>
      <div class="muted small">${esc(u.username)}${u.is_admin ? ' · administrátor' : ''}</div></div></section>
    <div class="list">
      <a class="li" href="#/ciel" style="color:inherit;text-decoration:none"><span>🎯</span><div class="grow t">Denný cieľ</div><span class="muted">›</span></a>
      <a class="li" href="#/potraviny" style="color:inherit;text-decoration:none"><span>🥫</span><div class="grow t">Moje potraviny a recepty</div><span class="muted">›</span></a>
      <a class="li" href="#/heslo" style="color:inherit;text-decoration:none"><span>🔑</span><div class="grow t">Zmeniť heslo</div><span class="muted">›</span></a>
      ${u.is_admin ? `<a class="li" href="#/admin" style="color:inherit;text-decoration:none"><span>👥</span><div class="grow t">Správa užívateľov</div><span class="muted">›</span></a>` : ''}
      <div class="li" id="logout"><span>🚪</span><div class="grow t">Odhlásiť sa</div></div>
    </div>
    <p class="muted tiny center" style="margin-top:20px">Diet Manager ${esc(state.version || '')} · AI rozpoznávanie: ${state.aiEnabled ? 'zapnuté' : 'vypnuté'}<br>
      Údaje o potravinách: Open Food Facts (ODbL)</p>`;
  $('#logout').onclick = logout;
}

function viewPassword(page) {
  page.innerHTML = `<div class="topbar"><a class="icon-btn" href="#/viac" aria-label="Späť">‹</a><h1>Zmeniť heslo</h1></div><section class="card">${passwordForm(false)}</section>`;
  bindPasswordForm(() => { location.hash = '#/viac'; });
}

// ---------- Denný cieľ ----------
async function viewGoal(page) {
  page.innerHTML = `<div class="topbar"><a class="icon-btn" href="#/viac" aria-label="Späť">‹</a><h1>Denný cieľ</h1></div><div id="gBody"><div class="spinner"></div></div>`;
  let data;
  try { data = await api('GET', '/goal'); } catch (e) { showError(e); return; }
  const p = data.profile || {};
  const g = data.goal || { kcal: '', protein: '', carbs: '', fat: '', fiber: 30, water_ml: 2500, input_mode: 'grams' };
  let mode = g.input_mode || 'grams';
  $('#gBody').innerHTML = `
    <section class="card"><div class="card-title"><h2>🧮 Navrhnúť cieľ</h2></div>
      <p class="muted small" style="margin-top:0">Výpočet podľa vzorca Mifflin-St Jeor. Úroveň aktivity zvoľte <b>bez tréningov</b> –
        tie sa pripočítajú zvlášť podľa záznamu z Garmin Connect.</p>
      <div class="grid2">
        <div><label class="f" for="pSex">Pohlavie</label><select class="input" id="pSex"><option value="male">muž</option><option value="female">žena</option></select></div>
        <div><label class="f" for="pBy">Rok narodenia</label><input class="input num" id="pBy" inputmode="numeric" value="${p.birth_year || ''}"></div>
        <div><label class="f" for="pH">Výška (cm)</label><input class="input num" id="pH" inputmode="decimal" value="${p.height_cm || ''}"></div>
        <div><label class="f" for="pW">Hmotnosť (kg)</label><input class="input num" id="pW" inputmode="decimal" value="${p.weight_kg || ''}"></div>
      </div>
      <label class="f" for="pAct">Bežná denná aktivita</label>
      <select class="input" id="pAct">${Object.entries(ACTIVITY_LEVELS).map(([k, v]) => `<option value="${k}">${v}</option>`).join('')}</select>
      <label class="f">Zámer</label>
      <div class="seg" id="pGoal"><button data-g="lose">Chudnúť</button><button data-g="maintain">Udržať</button><button data-g="gain">Pribrať</button></div>
      <button class="btn block" id="pCalc" style="margin-top:14px">Vypočítať návrh</button>
      <div id="pRes"></div>
    </section>
    <section class="card"><div class="card-title"><h2>🎯 Môj denný cieľ</h2></div>
      <label class="f" style="margin-top:0" for="gK">Kalórie (kcal)</label><input class="input num" id="gK" inputmode="numeric" value="${g.kcal ? f0(g.kcal).replace(/\s/g, '') : ''}">
      <label class="f">Makroživiny zadávať v</label>
      <div class="seg" id="gMode"><button data-m="grams">gramoch</button><button data-m="percent">% z kalórií</button></div>
      <div class="grid3" style="margin-top:4px">
        <div><label class="f" for="gP">Bielkoviny <span class="u"></span></label><input class="input num" id="gP" inputmode="decimal"></div>
        <div><label class="f" for="gC">Sacharidy <span class="u"></span></label><input class="input num" id="gC" inputmode="decimal"></div>
        <div><label class="f" for="gF">Tuky <span class="u"></span></label><input class="input num" id="gF" inputmode="decimal"></div>
      </div>
      <div class="muted small" id="gCheck" style="margin-top:8px"></div>
      <div class="grid2">
        <div><label class="f" for="gV">Vláknina (g)</label><input class="input num" id="gV" inputmode="decimal" value="${g.fiber ?? ''}"></div>
        <div><label class="f" for="gWa">Voda (ml)</label><input class="input num" id="gWa" inputmode="numeric" value="${g.water_ml ?? ''}"></div>
      </div>
      <button class="btn primary block" id="gSave" style="margin-top:16px">Uložiť cieľ</button>
      <p class="muted tiny">Nový cieľ platí od dnešného dňa. Staršie dni sa v reportoch hodnotia podľa cieľa, ktorý vtedy platil.</p>
    </section>
    ${data.history.length > 1 ? `<div class="section-label">História cieľov</div><div class="list">${data.history.map((h) => `<div class="li">
      <div class="grow"><div class="t">od ${fmtDate(h.valid_from)} ${parseDate(h.valid_from).getFullYear()}</div>
      <div class="s num">B ${f0(h.protein)} · S ${f0(h.carbs)} · T ${f0(h.fat)} g</div></div><div class="r num">${f0(h.kcal)} kcal</div></div>`).join('')}</div>` : ''}`;

  if (p.sex) $('#pSex').value = p.sex;
  if (p.activity_level) $('#pAct').value = p.activity_level;
  let goalType = p.goal_type || 'maintain';
  const setGT = (v) => { goalType = v; $$('#pGoal button').forEach((b) => b.classList.toggle('on', b.dataset.g === v)); };
  setGT(goalType);
  $$('#pGoal button').forEach((b) => { b.onclick = () => setGT(b.dataset.g); });

  // gramy v stave; zobrazenie podľa režimu
  const grams = { protein: g.protein || 0, carbs: g.carbs || 0, fat: g.fat || 0 };
  const KC = { protein: 4, carbs: 4, fat: 9 };
  const ids = { protein: 'gP', carbs: 'gC', fat: 'gF' };
  const kcal = () => num($('#gK').value) || 0;
  const fill = () => {
    $$('#gBody .u').forEach((s) => { s.textContent = mode === 'grams' ? '(g)' : '(%)'; });
    $$('#gMode button').forEach((b) => b.classList.toggle('on', b.dataset.m === mode));
    Object.entries(ids).forEach(([k, id]) => {
      const v = mode === 'grams' ? grams[k] : (kcal() ? grams[k] * KC[k] / kcal() * 100 : 0);
      $('#' + id).value = v ? String(Math.round(v)) : '';
    });
    check();
  };
  const read = () => {
    Object.entries(ids).forEach(([k, id]) => {
      const v = num($('#' + id).value) || 0;
      grams[k] = mode === 'grams' ? v : kcal() * v / 100 / KC[k];
    });
  };
  const check = () => {
    const fromMacros = grams.protein * 4 + grams.carbs * 4 + grams.fat * 9;
    const k = kcal();
    const pct = (x) => (k ? Math.round(x / k * 100) : 0);
    const info = `${mode === 'grams'
      ? `Pomer: B ${pct(grams.protein * 4)} % · S ${pct(grams.carbs * 4)} % · T ${pct(grams.fat * 9)} %`
      : `V gramoch: B ${f0(grams.protein)} g · S ${f0(grams.carbs)} g · T ${f0(grams.fat)} g`}`;
    const diff = fromMacros - k;
    const warn = k && Math.abs(diff) > Math.max(30, k * 0.03)
      ? `<br><span style="color:var(--bad)">Makroživiny dávajú ${f0(fromMacros)} kcal, to je o ${f0(Math.abs(diff))} kcal ${diff > 0 ? 'viac' : 'menej'} ako cieľ.</span>` : '';
    $('#gCheck').innerHTML = info + warn;
  };
  Object.values(ids).forEach((id) => $('#' + id).addEventListener('input', () => { read(); check(); }));
  $('#gK').addEventListener('input', () => { if (mode === 'percent') read(); check(); });
  $$('#gMode button').forEach((b) => { b.onclick = () => { read(); mode = b.dataset.m; fill(); }; });
  fill();

  $('#pCalc').onclick = (e) => withBusy(e.currentTarget, async () => {
    try {
      const r = await api('POST', '/goal/suggest', {
        sex: $('#pSex').value, birth_year: num($('#pBy').value), height_cm: num($('#pH').value),
        weight_kg: num($('#pW').value), activity_level: $('#pAct').value, goal_type: goalType,
      });
      const sg = r.suggestion;
      $('#pRes').innerHTML = `<div class="info-box" style="margin-top:12px">Bazálny metabolizmus <b>${f0(sg.bmr)} kcal</b>, výdaj bez tréningov <b>${f0(sg.tdee)} kcal</b>.<br>
        Návrh: <b>${f0(sg.kcal)} kcal</b> · B ${f0(sg.protein)} g · S ${f0(sg.carbs)} g · T ${f0(sg.fat)} g · vláknina ${f0(sg.fiber)} g · voda ${f1(sg.water_ml / 1000)} l
        <button class="btn sm primary block" id="pUse" style="margin-top:10px">Použiť návrh ↓</button></div>`;
      $('#pUse').onclick = () => {
        $('#gK').value = sg.kcal;
        Object.assign(grams, { protein: sg.protein, carbs: sg.carbs, fat: sg.fat });
        $('#gV').value = sg.fiber;
        $('#gWa').value = sg.water_ml;
        fill();
        $('#gSave').scrollIntoView({ behavior: 'smooth', block: 'center' });
      };
    } catch (ex) { showError(ex); }
  });
  $('#gSave').onclick = (e) => withBusy(e.currentTarget, async () => {
    read();
    try {
      await api('POST', '/goal', {
        kcal: kcal(), protein: Math.round(grams.protein * 10) / 10, carbs: Math.round(grams.carbs * 10) / 10,
        fat: Math.round(grams.fat * 10) / 10, fiber: num($('#gV').value) || 0, water_ml: num($('#gWa').value) || 0, input_mode: mode,
      });
      toast('Cieľ uložený');
      location.hash = '#/';
    } catch (ex) { showError(ex); }
  });
}

// ---------- Moje potraviny a recepty ----------
async function viewFoods(page) {
  page.innerHTML = `<div class="topbar"><a class="icon-btn" href="#/viac" aria-label="Späť">‹</a><h1>Potraviny a recepty</h1></div>
    <div class="grid2"><button class="btn primary" id="nFood">+ Potravina</button><button class="btn primary" id="nRec">+ Recept</button></div>
    <p class="muted small">Vlastné potraviny a recepty vidíte iba vy. Hodnoty sa zadávajú na 100 g.</p><div id="fList"></div>`;
  const load = async () => {
    try {
      const r = await api('GET', '/foods/mine');
      $('#fList').innerHTML = r.foods.length ? `<div class="list">${r.foods.map((f, i) => `<div class="li" data-i="${i}">${foodLine(f)}<span class="muted">›</span></div>`).join('')}</div>`
        : '<p class="empty center" style="padding:20px 0">Zatiaľ nemáte žiadne vlastné potraviny ani recepty.</p>';
      $$('#fList [data-i]').forEach((el) => {
        const f = r.foods[+el.dataset.i];
        el.onclick = async () => {
          if (f.is_recipe) {
            try { const full = (await api('GET', `/foods/${f.id}`)).food; openRecipeForm(full, load); } catch (e) { showError(e); }
          } else openFoodForm(f, load);
        };
      });
    } catch (e) { showError(e); }
  };
  $('#nFood').onclick = () => openFoodForm({}, load);
  $('#nRec').onclick = () => openRecipeForm(null, load);
  load();
}

function openFoodForm(food, onSaved) {
  const isEdit = !!food.id;
  const sh = sheet(isEdit ? 'Upraviť potravinu' : 'Nová potravina');
  const v = (k) => (food[k] != null ? String(food[k]).replace('.', ',') : '');
  sh.body.innerHTML = `<label class="f" style="margin-top:0" for="fN">Názov</label><input class="input" id="fN" value="${esc(food.name || '')}">
    <div class="grid2"><div><label class="f" for="fB">Značka (nepovinné)</label><input class="input" id="fB" value="${esc(food.brand || '')}"></div>
      <div><label class="f" for="fCode">Čiarový kód</label><input class="input num" id="fCode" inputmode="numeric" value="${esc(food.barcode || '')}"></div></div>
    <div class="section-label">Nutričné hodnoty na 100 g</div>
    <div class="grid2">
      <div><label class="f" for="fK">Energia (kcal)</label><input class="input num" id="fK" inputmode="decimal" value="${v('kcal')}"></div>
      <div><label class="f" for="fP">Bielkoviny (g)</label><input class="input num" id="fP" inputmode="decimal" value="${v('protein')}"></div>
      <div><label class="f" for="fC">Sacharidy (g)</label><input class="input num" id="fC" inputmode="decimal" value="${v('carbs')}"></div>
      <div><label class="f" for="fS">z toho cukry (g)</label><input class="input num" id="fS" inputmode="decimal" value="${v('sugar')}"></div>
      <div><label class="f" for="fF">Tuky (g)</label><input class="input num" id="fF" inputmode="decimal" value="${v('fat')}"></div>
      <div><label class="f" for="fV">Vláknina (g)</label><input class="input num" id="fV" inputmode="decimal" value="${v('fiber')}"></div>
    </div>
    <div class="section-label">Porcia (nepovinné)</div>
    <div class="grid2"><div><label class="f" for="fSn">Názov porcie</label><input class="input" id="fSn" placeholder="napr. kus, plátok, téglik" value="${esc(food.serving_name || '')}"></div>
      <div><label class="f" for="fSg">Hmotnosť porcie (g)</label><input class="input num" id="fSg" inputmode="decimal" value="${v('serving_g')}"></div></div>`;
  setFoot(sh, `<div class="${isEdit ? 'grid2' : ''}">${isEdit ? '<button class="btn danger" id="fDel">Zmazať</button>' : ''}<button class="btn primary ${isEdit ? '' : 'block'}" id="fSave">Uložiť</button></div>`);
  $('#fSave', sh.foot).onclick = (e) => withBusy(e.currentTarget, async () => {
    const payload = {
      name: $('#fN', sh.body).value, brand: $('#fB', sh.body).value, barcode: $('#fCode', sh.body).value.replace(/\D/g, ''),
      kcal: $('#fK', sh.body).value, protein: $('#fP', sh.body).value, carbs: $('#fC', sh.body).value, sugar: $('#fS', sh.body).value,
      fat: $('#fF', sh.body).value, fiber: $('#fV', sh.body).value, serving_name: $('#fSn', sh.body).value, serving_g: $('#fSg', sh.body).value,
    };
    try {
      const r = isEdit ? await api('PUT', `/foods/${food.id}`, payload) : await api('POST', '/foods', payload);
      toast('Uložené');
      sh.close();
      onSaved && onSaved(r.food);
    } catch (ex) { showError(ex); }
  });
  if (isEdit) {
    $('#fDel', sh.foot).onclick = async () => {
      if (!await confirmBox(`Zmazať „${food.name}“? Staré záznamy jedál zostanú zachované.`, 'Zmazať', true)) return;
      try { await api('DELETE', `/foods/${food.id}`); toast('Zmazané'); sh.close(); onSaved && onSaved(); } catch (ex) { showError(ex); }
    };
  }
}

function openRecipeForm(recipe, onSaved) {
  const isEdit = !!recipe;
  const sh = sheet(isEdit ? 'Upraviť recept' : 'Nový recept');
  const items = isEdit ? recipe.items.map((i) => ({ food: i.food, grams: i.grams })) : [];
  sh.body.innerHTML = `<label class="f" style="margin-top:0" for="rN">Názov receptu</label><input class="input" id="rN" value="${esc(recipe?.name || '')}">
    <div class="grid2"><div><label class="f" for="rPor">Počet porcií</label><input class="input num" id="rPor" inputmode="numeric" value="${isEdit && recipe.serving_g ? Math.round((recipe.recipe_total_g || 0) / recipe.serving_g) || 1 : 1}"></div>
      <div><label class="f" for="rTot">Hmotnosť hotového jedla (g)</label><input class="input num" id="rTot" inputmode="decimal" placeholder="súčet surovín" value="${isEdit && recipe.recipe_total_g ? f0(recipe.recipe_total_g).replace(/\s/g, '') : ''}"></div></div>
    <p class="muted tiny">Ak jedlo po uvarení zmení hmotnosť (napr. ryža, cestoviny), zvážte hotové jedlo – výpočet na 100 g bude presnejší.</p>
    <div class="section-label">Suroviny</div><div id="rItems"></div>
    <div class="row" style="margin-top:8px"><input class="input grow" id="rQ" type="search" placeholder="Pridať surovinu – hľadať…" autocomplete="off"></div>
    <div id="rRes"></div>
    <div class="section-label">Spolu</div><div class="nutr-preview num" id="rSum"></div><p class="muted tiny" id="rPer"></p>`;
  setFoot(sh, `<div class="${isEdit ? 'grid2' : ''}">${isEdit ? '<button class="btn danger" id="rDel">Zmazať</button>' : ''}<button class="btn primary ${isEdit ? '' : 'block'}" id="rSave">Uložiť recept</button></div>`);
  const sum = () => {
    const t = { kcal: 0, protein: 0, carbs: 0, fat: 0, fiber: 0 };
    let w = 0;
    items.forEach((i) => { const g = num(i.grams) || 0; w += g; Object.keys(t).forEach((k) => { t[k] += i.food[k] * g / 100; }); });
    $('#rSum', sh.body).innerHTML = totHtml(t);
    const por = Math.max(1, num($('#rPor', sh.body).value) || 1);
    const tot = num($('#rTot', sh.body).value) || w;
    $('#rPer', sh.body).textContent = items.length ? `1 porcia ≈ ${f0(tot / por)} g · ${f0(t.kcal / por)} kcal · na 100 g ${tot ? f0(t.kcal / tot * 100) : 0} kcal` : '';
  };
  const drawItems = () => {
    $('#rItems', sh.body).innerHTML = items.length ? `<div class="list">${items.map((i, ix) => `<div class="li" style="cursor:default">
      <div class="grow"><div class="t ellipsis">${esc(i.food.name)}</div><div class="s">${f0(i.food.kcal)} kcal/100 g</div></div>
      <input class="input sm num" style="width:80px" data-g="${ix}" inputmode="decimal" value="${f0(i.grams).replace(/\s/g, '')}" aria-label="gramy"><span class="muted small">g</span>
      <button class="icon-btn" data-x="${ix}" aria-label="Odstrániť">✕</button></div>`).join('')}</div>` : '<p class="empty">Pridajte suroviny vyhľadaním nižšie.</p>';
    $$('[data-g]', sh.body).forEach((inp) => inp.addEventListener('input', () => { items[+inp.dataset.g].grams = num(inp.value) || 0; sum(); }));
    $$('[data-x]', sh.body).forEach((b) => { b.onclick = () => { items.splice(+b.dataset.x, 1); drawItems(); }; });
    sum();
  };
  drawItems();
  ['rPor', 'rTot'].forEach((id) => $('#' + id, sh.body).addEventListener('input', sum));
  const rq = $('#rQ', sh.body);
  rq.addEventListener('input', debounce(async () => {
    const term = rq.value.trim();
    const box = $('#rRes', sh.body);
    if (term.length < 2) { box.innerHTML = ''; return; }
    try {
      const local = (await api('GET', `/foods/search?q=${encodeURIComponent(term)}`)).foods.filter((f) => !recipe || f.id !== recipe.id);
      box.innerHTML = `<div class="list" style="margin-top:8px">${local.map((f, i) => `<div class="li" data-i="${i}">${foodLine(f)}<span style="color:var(--accent);font-weight:700">+</span></div>`).join('')}</div>
        <button class="btn sm block" id="rOnline" style="margin-top:6px">🌐 Hľadať online</button>`;
      const pick = (f) => { items.push({ food: f, grams: 100 }); rq.value = ''; box.innerHTML = ''; drawItems(); };
      $$('[data-i]', box).forEach((el) => { el.onclick = () => pick(local[+el.dataset.i]); });
      $('#rOnline', box).onclick = (e) => withBusy(e.currentTarget, async () => {
        const online = (await api('GET', `/foods/online?q=${encodeURIComponent(term)}`)).foods;
        box.innerHTML = online.length ? `<div class="list" style="margin-top:8px">${online.map((f, i) => `<div class="li" data-o="${i}">${foodLine(f)}<span style="color:var(--accent);font-weight:700">+</span></div>`).join('')}</div>`
          : '<p class="empty">Nič sa nenašlo.</p>';
        $$('[data-o]', box).forEach((el) => { el.onclick = () => pick(online[+el.dataset.o]); });
      });
    } catch (e) { showError(e); }
  }, 250));
  $('#rSave', sh.foot).onclick = (e) => withBusy(e.currentTarget, async () => {
    const payload = {
      name: $('#rN', sh.body).value, portions: num($('#rPor', sh.body).value) || 1, total_g: num($('#rTot', sh.body).value),
      items: items.map((i) => ({ food_id: i.food.id, grams: num(i.grams) || 0 })),
    };
    try {
      if (isEdit) await api('PUT', `/recipes/${recipe.id}`, payload); else await api('POST', '/recipes', payload);
      toast('Recept uložený');
      sh.close();
      onSaved && onSaved();
    } catch (ex) { showError(ex); }
  });
  if (isEdit) {
    $('#rDel', sh.foot).onclick = async () => {
      if (!await confirmBox(`Zmazať recept „${recipe.name}“?`, 'Zmazať', true)) return;
      try { await api('DELETE', `/foods/${recipe.id}`); toast('Zmazané'); sh.close(); onSaved && onSaved(); } catch (ex) { showError(ex); }
    };
  }
}

// ---------- Správa užívateľov (admin) ----------
function genPassword() {
  const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const a = new Uint32Array(10);
  crypto.getRandomValues(a);
  return [...a].map((x) => chars[x % chars.length]).join('');
}

async function viewAdmin(page) {
  if (!state.user.is_admin) { location.hash = '#/'; return; }
  page.innerHTML = `<div class="topbar"><a class="icon-btn" href="#/viac" aria-label="Späť">‹</a><h1>Správa užívateľov</h1></div><div id="aBody"></div>`;
  const load = async () => {
    let r;
    try { r = await api('GET', '/admin/users'); } catch (e) { showError(e); return; }
    const full = r.users.length >= r.max_users;
    $('#aBody').innerHTML = `<div class="row" style="margin-bottom:10px"><div class="grow muted small">Užívatelia: <b>${r.users.length}</b> / ${r.max_users}</div>
      <button class="btn primary sm" id="aNew" ${full ? 'disabled' : ''}>+ Nový užívateľ</button></div>
      <div class="list">${r.users.map((u, i) => `<div class="li" data-i="${i}">
        <div class="grow"><div class="t">${esc(u.display_name)} <span class="muted small">(${esc(u.username)})</span></div>
          <div class="badge-list">${u.is_admin ? '<span class="chip accent">admin</span>' : ''}${!u.is_active ? '<span class="chip bad">deaktivovaný</span>' : ''}
            ${u.must_change_password ? '<span class="chip warn">čaká na zmenu hesla</span>' : ''}
            <span class="s">${u.last_login_at ? 'naposledy ' + fmtDate(u.last_login_at.slice(0, 10)) : 'zatiaľ neprihlásený'}</span></div></div>
        <span class="muted">›</span></div>`).join('')}</div>`;
    $('#aNew').onclick = () => openNewUser(load);
    $$('#aBody [data-i]').forEach((el) => { el.onclick = () => openUser(r.users[+el.dataset.i], load); });
  };
  load();
}

function openNewUser(onDone) {
  const sh = sheet('Nový užívateľ');
  const pw = genPassword();
  sh.body.innerHTML = `<label class="f" style="margin-top:0" for="nuName">Meno (zobrazované)</label><input class="input" id="nuName" placeholder="napr. Jana">
    <label class="f" for="nuUser">Prihlasovacie meno</label><input class="input" id="nuUser" autocapitalize="none" placeholder="napr. jana">
    <p class="muted tiny">Malé písmená bez diakritiky, číslice, bodka, pomlčka.</p>
    <label class="f" for="nuPw">Počiatočné heslo</label><div class="row"><input class="input grow" id="nuPw" value="${pw}"><button class="btn" id="nuGen" aria-label="Vygenerovať heslo">🎲</button></div>
    <p class="muted tiny">Užívateľ si heslo pri prvom prihlásení povinne zmení.</p>`;
  setFoot(sh, '<button class="btn primary block" id="nuSave">Vytvoriť užívateľa</button>');
  $('#nuName', sh.body).addEventListener('input', (e) => {
    const u = $('#nuUser', sh.body);
    if (!u.dataset.touched) u.value = e.target.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9._-]/g, '');
  });
  $('#nuUser', sh.body).addEventListener('input', (e) => { e.target.dataset.touched = '1'; });
  $('#nuGen', sh.body).onclick = () => { $('#nuPw', sh.body).value = genPassword(); };
  $('#nuSave', sh.foot).onclick = (e) => withBusy(e.currentTarget, async () => {
    const data = { display_name: $('#nuName', sh.body).value, username: $('#nuUser', sh.body).value, password: $('#nuPw', sh.body).value };
    try {
      await api('POST', '/admin/users', data);
      sh.close();
      showCredentials(data.username, data.password, 'Užívateľ vytvorený');
      onDone();
    } catch (ex) { showError(ex); }
  });
}

function showCredentials(username, password, title) {
  const sh = sheet(title);
  const text = `Prihlasovacie meno: ${username}\nHeslo: ${password}\nAdresa: ${location.origin}${location.pathname}`;
  sh.body.innerHTML = `<p>Odovzdajte tieto údaje užívateľovi. Heslo si pri prvom prihlásení zmení.</p>
    <pre class="card" style="white-space:pre-wrap;font-size:.95rem">${esc(text)}</pre>
    <button class="btn primary block" id="cCopy">📋 Kopírovať</button>`;
  $('#cCopy', sh.body).onclick = async () => {
    try { await navigator.clipboard.writeText(text); toast('Skopírované'); } catch (e) { toast('Kopírovanie nie je podporované', true); }
  };
}

function openUser(u, onDone) {
  const self = u.id === state.user.id;
  const sh = sheet(u.display_name);
  sh.body.innerHTML = `<p class="muted small" style="margin-top:0">${esc(u.username)} · vytvorený ${fmtDate(u.created_at.slice(0, 10))}</p>
    <label class="f" for="euName">Zobrazované meno</label><div class="row"><input class="input grow" id="euName" value="${esc(u.display_name)}"><button class="btn" id="euRen">Uložiť</button></div>
    <div class="section-label">Zabudnuté heslo</div>
    <div class="row"><input class="input grow" id="euPw" value="${genPassword()}"><button class="btn primary" id="euReset">Nastaviť</button></div>
    <p class="muted tiny">Užívateľ bude odhlásený zo všetkých zariadení a pri prihlásení si heslo zmení.</p>
    ${self ? '' : `<div class="section-label">Účet</div>
      <div class="grid2"><button class="btn" id="euAct">${u.is_active ? '⏸ Deaktivovať' : '▶ Aktivovať'}</button>
      <button class="btn danger" id="euDel">🗑 Zmazať</button></div>
      <p class="muted tiny">Zmazanie odstráni užívateľa aj všetky jeho dáta (záznamy, fotky, ciele). Nedá sa vrátiť späť.</p>`}`;
  $('#euRen', sh.body).onclick = (e) => withBusy(e.currentTarget, async () => {
    try {
      await api('PUT', `/admin/users/${u.id}`, { display_name: $('#euName', sh.body).value });
      if (self) state.user.display_name = $('#euName', sh.body).value;
      toast('Uložené'); onDone();
    } catch (ex) { showError(ex); }
  });
  $('#euReset', sh.body).onclick = (e) => withBusy(e.currentTarget, async () => {
    const pw = $('#euPw', sh.body).value;
    try {
      await api('POST', `/admin/users/${u.id}/password`, { password: pw });
      sh.close();
      if (self) { state.user = null; renderLogin(); toast('Heslo zmenené – prihláste sa znova'); return; }
      showCredentials(u.username, pw, 'Nové heslo nastavené');
      onDone();
    } catch (ex) { showError(ex); }
  });
  if (!self) {
    $('#euAct', sh.body).onclick = (e) => withBusy(e.currentTarget, async () => {
      try { await api('PUT', `/admin/users/${u.id}`, { is_active: !u.is_active }); toast(u.is_active ? 'Účet deaktivovaný' : 'Účet aktivovaný'); sh.close(); onDone(); } catch (ex) { showError(ex); }
    });
    $('#euDel', sh.body).onclick = async () => {
      if (!await confirmBox(`Naozaj zmazať užívateľa ${u.display_name} (${u.username}) a všetky jeho dáta?`, 'Zmazať natrvalo', true)) return;
      try { await api('DELETE', `/admin/users/${u.id}`); toast('Užívateľ zmazaný'); sh.close(); onDone(); } catch (ex) { showError(ex); }
    };
  }
}

// ---------- Štart ----------
boot();
