/**
 * periodontalChart.js — Periodontal Chart V2 (perio-tools style)
 * 6 sites per tooth, tooth status, mobility, furcation, BOP/PI/suppuration.
 *
 * All user-visible strings come from window.PerioI18n (populated via xl() in PHP).
 * No hardcoded Spanish/English strings are emitted here.
 */
(function() {
  'use strict';

  const cfg = window.PerioConfig || {};
  const i18n = window.PerioI18n || {};

  const SITES = [
    { id: 1, key: 'MB',   label: 'MB' },
    { id: 2, key: 'B',    label: 'B'  },
    { id: 3, key: 'DB',   label: 'DB' },
    { id: 4, key: 'DL',   label: 'DL' },
    { id: 5, key: 'L',    label: 'L'  },
    { id: 6, key: 'ML',   label: 'ML' },
  ];

  const ALL_TEETH = [...(cfg.upperTeeth||[]), ...(cfg.lowerTeeth||[])];
  const state = new Map(); // fdi -> {status, mobility, furcation:{b,l,p}, sites: Map}

  function defaultToothState() {
    return {
      status: 'present',
      mobility: 0,
      furcation: { buccal: 0, lingual: 0, palatal: 0 },
      sites: new Map(SITES.map(s => [s.id, { pd: null, gm: null, bop: 0, plaque: 0, supp: 0 }]))
    };
  }

  function initState() {
    ALL_TEETH.forEach(fdi => state.set(fdi, defaultToothState()));
    const init = cfg.initialState || {};
    Object.keys(init).forEach(k => {
      const fdi = parseInt(k, 10);
      if (!state.has(fdi)) state.set(fdi, defaultToothState());
      const t = state.get(fdi);
      const src = init[k] || {};
      t.status = src.status || 'present';
      t.mobility = src.mobility != null ? parseInt(src.mobility,10) : 0;
      if (src.furcation) {
        t.furcation.buccal = parseInt(src.furcation.buccal||0,10);
        t.furcation.lingual = parseInt(src.furcation.lingual||0,10);
        t.furcation.palatal = parseInt(src.furcation.palatal||0,10);
      }
      const sitesSrc = src.sites || {};
      Object.keys(sitesSrc).forEach(sk => {
        const sid = parseInt(sk,10);
        const sv = sitesSrc[sk] || {};
        t.sites.set(sid, {
          pd: sv.pd != null ? sv.pd : null,
          gm: sv.gm != null ? sv.gm : null,
          bop: sv.bop ? 1 : 0,
          plaque: sv.plaque ? 1 : 0,
          supp: sv.supp ? 1 : 0
        });
      });
    });
    // Legacy conversion (client-side fallback if server didn't have v2 yet)
    if (Object.keys(init).length === 0 && cfg.legacyDetails && cfg.legacyDetails.length) {
      cfg.legacyDetails.forEach(row => {
        const fdi = parseInt(row.tooth_fdi,10);
        if (!state.has(fdi)) state.set(fdi, defaultToothState());
        const t = state.get(fdi);
        const site = legacyToSite(row.surface, parseInt(row.point,10));
        if (!site) return;
        const cur = t.sites.get(site) || { pd:null, gm:null, bop:0, plaque:0, supp:0 };
        const pd = row.probing_depth != null ? parseInt(row.probing_depth,10) : null;
        const rec = row.recession != null ? parseInt(row.recession,10) : null;
        cur.pd = pd;
        cur.gm = rec != null ? -rec : null;
        cur.bop = row.bleeding ? 1 : 0;
        cur.supp = row.suppuration ? 1 : 0;
        t.sites.set(site, cur);
        if (row.mobility != null) t.mobility = parseInt(row.mobility,10);
        if (row.surface === 'V' && row.furcation != null) t.furcation.buccal = parseInt(row.furcation,10);
      });
    }
  }

  function legacyToSite(surface, point) {
    const map = { V: {1:1,2:2,3:3}, P: {1:6,2:5,3:4} };
    return (map[surface] && map[surface][point]) || null;
  }

  function siteToLegacy(site) {
    // inverse for dual-write
    if (site===1) return {surface:'V', point:1};
    if (site===2) return {surface:'V', point:2};
    if (site===3) return {surface:'V', point:3};
    if (site===6) return {surface:'P', point:1};
    if (site===5) return {surface:'P', point:2};
    if (site===4) return {surface:'P', point:3};
    return null;
  }

  function esc(s) {
    if (s==null) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function pdClass(pd) {
    if (pd==null || isNaN(pd)) return '';
    if (pd >= 6) return 'pd-danger';
    if (pd >= 4) return 'pd-warning';
    if (pd >= 1) return 'pd-healthy';
    return '';
  }

  function fdiToUniversal(fdi) {
    const map = {
      18:1,17:2,16:3,15:4,14:5,13:6,12:7,11:8,
      21:9,22:10,23:11,24:12,25:13,26:14,27:15,28:16,
      38:17,37:18,36:19,35:20,34:21,33:22,32:23,31:24,
      41:25,42:26,43:27,44:28,45:29,46:30,47:31,48:32
    };
    return map[fdi] || fdi;
  }

  function fdiToPalmer(fdi) {
    const q = Math.floor(fdi / 10); // 1,2,3,4
    const pos = fdi % 10; // 1 central -> 8 third molar
    const sym = {1:'┐', 2:'┌', 3:'└', 4:'┘'}[q] || '';
    return sym + pos;
  }

  function fdiToHaderup(fdi) {
    const q = Math.floor(fdi / 10);
    const pos = fdi % 10; // 1..8
    if (q === 1) return pos + '+';   // upper right 8+,7+...1+
    if (q === 2) return '+' + pos;   // upper left +1...+8
    if (q === 3) return '-' + pos;   // lower left -1...-8
    if (q === 4) return pos + '-';   // lower right 8-...1-
    return String(fdi);
  }

  function displayNumber(fdi) {
    const sys = localStorage.getItem('perio_numbering') || 'FDI';
    if (sys === 'Universal') return String(fdiToUniversal(fdi));
    if (sys === 'Palmer') return fdiToPalmer(fdi);
    if (sys === 'Haderup') return fdiToHaderup(fdi);
    return String(fdi);
  }

  // ---------- Rendering ----------
  function artPathForFdi(fdi) {
    // User-drawn SVG per tooth (72x96 vertical). Falls back to guide if missing (onerror hides img).
    // Naming: tooth-11.svg ... tooth-48.svg ; implant uses tooth-implant.svg
    const t0 = state.get(fdi);
    if (t0 && t0.status === 'implant') {
      return (cfg.webRoot||'') + '/interface/forms/periodontics/js/tooth-art/tooth-implant.svg';
    }
    return (cfg.webRoot||'') + '/interface/forms/periodontics/js/tooth-art/tooth-' + fdi + '.svg';
  }

  function toothCardHTML(fdi) {
    const t = state.get(fdi);
    const isMolarFurc = (cfg.molarFDI||[]).includes(fdi);
    const statusLabel = t.status === 'missing' ? i18n.missing : (t.status === 'implant' ? i18n.implant : i18n.present);
    const showFurc = isMolarFurc && t.status !== 'missing';
    const upper = fdi < 30;
    const artUrl = artPathForFdi(fdi);

    let sitesHtml = '';
    // Top row: MB, B, DB (1,2,3) then bottom row DL, L, ML (4,5,6) — order left->right
    const order = [1,2,3,4,5,6];
    order.forEach(sid => {
      const s = t.sites.get(sid) || { pd:null, gm:null, bop:0, plaque:0, supp:0 };
      const siteDef = SITES.find(x => x.id === sid);
      const pdVal = s.pd != null ? s.pd : '';
      const gmVal = s.gm != null ? s.gm : '';
      const dis = t.status === 'missing' ? 'disabled' : '';
      sitesHtml += `
        <div class="site-cell" data-site="${sid}">
          <div class="site-label">${esc(siteDef.key)}</div>
          <input class="pd-input ${pdClass(s.pd)}" data-fdi="${fdi}" data-site="${sid}" type="number"
                 min="0" max="12" inputmode="numeric" value="${esc(pdVal)}" ${dis}
                 aria-label="${esc(i18n.probingDepth||'PD')} ${fdi} ${siteDef.key}" placeholder="–">
          <input class="gm-input" data-fdi="${fdi}" data-site="${sid}" type="number"
                 min="-12" max="12" inputmode="numeric" value="${esc(gmVal)}" ${dis}
                 aria-label="${esc(i18n.gingivalMargin||'GM')} ${fdi} ${siteDef.key}" placeholder="GM">
          <div class="site-toggles">
            <button type="button" class="btn-bop ${s.bop?'bop-on':''}" data-fdi="${fdi}" data-site="${sid}"
              title="${esc(i18n.bleeding||'BOP')}" aria-pressed="${s.bop? 'true':'false'}" ${dis}>●</button>
            <button type="button" class="btn-plaque ${s.plaque?'plaque-on':''}" data-fdi="${fdi}" data-site="${sid}"
              title="${esc(i18n.plaque||'Plaque')}" aria-pressed="${s.plaque?'true':'false'}" ${dis}>◆</button>
            <button type="button" class="btn-supp ${s.supp?'supp-on':''}" data-fdi="${fdi}" data-site="${sid}"
              title="${esc(i18n.suppuration||'Suppuration')}" aria-pressed="${s.supp?'true':'false'}" ${dis}>▲</button>
          </div>
        </div>`;
    });

    let furHtml = '';
    if (showFurc) {
      // Short labels to fit narrow card; full name in title tooltip
      const bShort = (i18n.buccal||'B').slice(0,3);
      const lShort = (i18n.lingual||'L').slice(0,3);
      const pShort = (i18n.palatal||'P').slice(0,3);
      furHtml = `
        <div class="fur-row" data-furc-for="${fdi}">
          <select class="fur-buccal" data-fdi="${fdi}" title="${esc(i18n.buccal||'Buccal')}" ${t.status==='missing'?'disabled':''}>
            <option value="">${esc(bShort)}<\/option>
            <option value="1" ${t.furcation.buccal==1?'selected':''}>I<\/option>
            <option value="2" ${t.furcation.buccal==2?'selected':''}>II<\/option>
            <option value="3" ${t.furcation.buccal==3?'selected':''}>III<\/option>
          </select>
          <select class="fur-lingual" data-fdi="${fdi}" title="${esc(i18n.lingual||'Lingual')}" ${t.status==='missing'?'disabled':''}>
            <option value="">${esc(lShort)}<\/option>
            <option value="1" ${t.furcation.lingual==1?'selected':''}>I<\/option>
            <option value="2" ${t.furcation.lingual==2?'selected':''}>II<\/option>
            <option value="3" ${t.furcation.lingual==3?'selected':''}>III<\/option>
          </select>
          ${upper ? `<select class="fur-palatal" data-fdi="${fdi}" title="${esc(i18n.palatal||'Palatal')}" ${t.status==='missing'?'disabled':''}>
            <option value="">${esc(pShort)}<\/option>
            <option value="1" ${t.furcation.palatal==1?'selected':''}>I<\/option>
            <option value="2" ${t.furcation.palatal==2?'selected':''}>II<\/option>
            <option value="3" ${t.furcation.palatal==3?'selected':''}>III<\/option>
          </select>` : ''}
        </div>`;
    }

    return `
      <div class="tooth-card status-${esc(t.status)}" data-tooth="${fdi}" role="group" aria-label="${esc(i18n.toothStatus||'Tooth')} ${fdi}">
        <div class="tooth-header">
          <button type="button" class="tooth-num btn-status" data-fdi="${fdi}"
            title="${esc(i18n.clickToChangeStatus||'Click to change tooth status')}" aria-label="${esc(i18n.toothStatus||'Tooth')} ${fdi}">TP</button>
          <span class="tooth-status-badge">${esc(statusLabel)}</span>
        </div>
        <div class="tooth-controls">
          <label for="mob-${fdi}">${esc(i18n.mobility||'Mob')}</label>
          <select id="mob-${fdi}" class="mob-select" data-fdi="${fdi}" ${t.status==='missing'?'disabled':''}>
            <option value="0" ${t.mobility==0?'selected':''}>0</option>
            <option value="1" ${t.mobility==1?'selected':''}>I</option>
            <option value="2" ${t.mobility==2?'selected':''}>II</option>
            <option value="3" ${t.mobility==3?'selected':''}>III</option>
          </select>
        </div>
        ${furHtml}
        <div class="tooth-chart">
          <!-- Background tooth art (user SVG, 72x96). Falls back to guide rect if not present. -->
          <img class="tooth-art" src="${artUrl}" alt="" onerror="this.style.display='none'">
          <svg viewBox="0 0 72 96" preserveAspectRatio="none" aria-hidden="true">
            <rect x="6" y="6" width="60" height="84" fill="none" stroke="#ced4da" stroke-width="1"/>
            <line x1="6" y1="48" x2="66" y2="48" stroke="#adb5bd" stroke-width="1" stroke-dasharray="3 2"/>
            <text x="36" y="52" font-size="7" text-anchor="middle" fill="#adb5bd">${fdi}</text>
          </svg>
          <svg class="chart-lines" viewBox="0 0 72 96" preserveAspectRatio="none" style="position:absolute; inset:0;" aria-hidden="true"></svg>
        </div>
        <div class="sites-grid">${sitesHtml}</div>
      </div>`;
  }

  // ---------- Numbering (fast path: relabel only, no rebuild) ----------
  function updateNumberLabels() {
    document.querySelectorAll('.tooth-card[data-tooth]').forEach(card => {
      const fdi = parseInt(card.dataset.tooth, 10);
      const disp = displayNumber(fdi);
      const btn = card.querySelector('.tooth-num');
      if (btn) btn.textContent = disp;
      const txt = card.querySelector('.tooth-chart svg text');
      if (txt) txt.textContent = disp;
    });
  }

  function renderArches() {
    const upperEl = document.getElementById('teeth-upper');
    const lowerEl = document.getElementById('teeth-lower');
    if (!upperEl || !lowerEl) return;

    function build(teeth, container) {
      container.innerHTML = '';
      teeth.forEach((fdi, idx) => {
        const disp = displayNumber(fdi);
        const tmp = document.createElement('div');
        tmp.innerHTML = toothCardHTML(fdi);
        const card = tmp.firstElementChild;
        const numBtn = card.querySelector('.tooth-num');
        if (numBtn) numBtn.textContent = disp;
        container.appendChild(card);
        if (idx === 7) {
          const sep = document.createElement('div');
          sep.className = 'midline-spacer';
          container.appendChild(sep);
        }
      });
    }

    build(cfg.upperTeeth||[], upperEl);
    build(cfg.lowerTeeth||[], lowerEl);

    // Draw lines after DOM ready
    ALL_TEETH.forEach(fdi => drawToothLines(fdi));
    updateStats();
    bindEvents();
  }

  // ---------- SVG lines ----------
  function drawToothLines(fdi) {
    const card = document.querySelector(`.tooth-card[data-tooth="${fdi}"]`);
    if (!card) return;
    const svg = card.querySelector('.chart-lines');
    if (!svg) return;
    const t = state.get(fdi);
    if (!t || t.status === 'missing') {
      svg.innerHTML = `<text x="36" y="52" font-size="16" text-anchor="middle" fill="#adb5bd">×</text>`;
      return;
    }
    // Vertical chart: x for sites 1,2,3 => 20,36,52 ; y base 48 (CEJ)
    // Upper teeth are drawn crown-down / root-up, so both GM and PD go
    // DOWN from CEJ (toward crown). Lower teeth also go down (toward root).
    // Result: red (PD) always below blue (GM) in both arches.
    const xMap = {1:20, 2:36, 3:52, 6:20, 5:36, 4:52};
    const scale = 2.5; // px per mm (vertical)

    function pt(siteId) {
      const s = t.sites.get(siteId) || { pd:null, gm:null };
      const gm = s.gm != null ? s.gm : 0;
      const pd = s.pd != null ? s.pd : 0;
      const yGMcalc = 48 + gm * scale;       // down from CEJ
      const yPDcalc = yGMcalc + pd * scale;  // down from GM (always below)
      return { x: xMap[siteId], yGM: yGMcalc, yPD: yPDcalc };
    }

    const buccal = [1,2,3].map(pt);
    const lingual = [6,5,4].map(pt); // ML,L,DL left->right

    const gmPathB = buccal.map((p,i)=> (i===0?'M':'L')+p.x+','+p.yGM).join(' ');
    const pdPathB = buccal.map((p,i)=> (i===0?'M':'L')+p.x+','+p.yPD).join(' ');
    const gmPathL = lingual.map((p,i)=> (i===0?'M':'L')+p.x+','+p.yGM).join(' ');
    const pdPathL = lingual.map((p,i)=> (i===0?'M':'L')+p.x+','+p.yPD).join(' ');

    // Markers for BOP
    let markers = '';
    [1,2,3,6,5,4].forEach(sid => {
      const s = t.sites.get(sid);
      if (s && s.bop) {
        const p = pt(sid);
        markers += `<circle cx="${p.x}" cy="${p.yPD + 3}" r="2.5" fill="#dc3545"/>`;
      }
      if (s && s.plaque) {
        const p = pt(sid);
        markers += `<rect x="${p.x-2}" y="${p.yGM - 5}" width="4" height="4" fill="#ffc107" stroke="#e0a800" stroke-width="0.5"/>`;
      }
    });

    svg.innerHTML = `
      <path d="${gmPathB}" fill="none" stroke="#007bff" stroke-width="1.8" stroke-linejoin="round"/>
      <path d="${pdPathB}" fill="none" stroke="#dc3545" stroke-width="1.8" stroke-linejoin="round"/>
      <path d="${gmPathL}" fill="none" stroke="#007bff" stroke-width="1.8" stroke-linejoin="round"/>
      <path d="${pdPathL}" fill="none" stroke="#dc3545" stroke-width="1.8" stroke-linejoin="round"/>
      ${markers}
    `;
  }

  // ---------- Stats ----------
  function updateStats() {
    let pdSum=0, pdN=0, alSum=0, alN=0;
    let bopPos=0, bopN=0, piPos=0, piN=0;
    let present=0;
    state.forEach((t, fdi) => {
      if (t.status === 'missing') return;
      present++;
      t.sites.forEach(s => {
        if (s.pd != null && !isNaN(s.pd)) {
          pdSum += s.pd; pdN++;
          const gm = s.gm != null ? s.gm : 0;
          const al = s.pd - gm;
          alSum += al; alN++;
        }
        // BOP/PI over evaluable sites (those with PD recorded)
        if (s.pd != null && !isNaN(s.pd)) {
          bopN++; piN++;
          if (s.bop) bopPos++;
          if (s.plaque) piPos++;
        }
      });
    });
    const $ = (id)=> document.getElementById(id);
    if ($('stat-pd')) $('stat-pd').textContent = (pdN? (pdSum/pdN).toFixed(1) : '0.0');
    if ($('stat-al')) $('stat-al').textContent = (alN? (alSum/alN).toFixed(1) : '0.0');
    if ($('stat-bop')) $('stat-bop').textContent = (bopN? Math.round(bopPos/bopN*100) : 0);
    if ($('stat-pi')) $('stat-pi').textContent = (piN? Math.round(piPos/piN*100) : 0);
    if ($('stat-teeth')) $('stat-teeth').textContent = present + '/' + ALL_TEETH.length;
    return { pdSum, pdN, alSum, alN, bopPos, bopN, piPos, piN, present };
  }

  // ---------- Events (delegate) ----------
  let _eventsBound = false;
  function bindEvents() {
    if (_eventsBound) return;
    _eventsBound = true;
    const root = document.body;

    // Status cycle
    root.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-status');
      if (btn) {
        const fdi = parseInt(btn.dataset.fdi,10);
        const t = state.get(fdi);
        // cycle present -> missing -> implant -> present
        if (t.status === 'present') t.status = 'missing';
        else if (t.status === 'missing') t.status = 'implant';
        else t.status = 'present';
        renderTooth(fdi);
        updateStats();
        return;
      }
      const bopBtn = e.target.closest('.btn-bop');
      if (bopBtn) {
        const fdi = parseInt(bopBtn.dataset.fdi,10), sid = parseInt(bopBtn.dataset.site,10);
        const s = state.get(fdi).sites.get(sid);
        s.bop = s.bop ? 0 : 1;
        bopBtn.classList.toggle('bop-on', !!s.bop);
        bopBtn.setAttribute('aria-pressed', s.bop ? 'true':'false');
        drawToothLines(fdi); updateStats(); return;
      }
      const plaqueBtn = e.target.closest('.btn-plaque');
      if (plaqueBtn) {
        const fdi = parseInt(plaqueBtn.dataset.fdi,10), sid = parseInt(plaqueBtn.dataset.site,10);
        const s = state.get(fdi).sites.get(sid);
        s.plaque = s.plaque ? 0 : 1;
        plaqueBtn.classList.toggle('plaque-on', !!s.plaque);
        plaqueBtn.setAttribute('aria-pressed', s.plaque ? 'true':'false');
        drawToothLines(fdi); updateStats(); return;
      }
      const suppBtn = e.target.closest('.btn-supp');
      if (suppBtn) {
        const fdi = parseInt(suppBtn.dataset.fdi,10), sid = parseInt(suppBtn.dataset.site,10);
        const s = state.get(fdi).sites.get(sid);
        s.supp = s.supp ? 0 : 1;
        suppBtn.classList.toggle('supp-on', !!s.supp);
        suppBtn.setAttribute('aria-pressed', s.supp ? 'true':'false');
        return;
      }
      const _sysBtn = (id) => document.getElementById(id);
      function setNumbering(sys) {
        localStorage.setItem('perio_numbering', sys);
        ['btn-fdi','btn-univ','btn-palmer','btn-haderup'].forEach(bid => {
          const el = _sysBtn(bid);
          if (!el) return;
          const map = {'btn-fdi':'FDI','btn-univ':'Universal','btn-palmer':'Palmer','btn-haderup':'Haderup'};
          el.className = (map[bid] === sys) ? 'btn btn-primary' : 'btn btn-outline-secondary';
        });
        updateNumberLabels(); // instant: relabel only, no full rebuild
      }
      if (e.target.closest('#btn-fdi')) { setNumbering('FDI'); return; }
      if (e.target.closest('#btn-univ')) { setNumbering('Universal'); return; }
      if (e.target.closest('#btn-palmer')) { setNumbering('Palmer'); return; }
      if (e.target.closest('#btn-haderup')) { setNumbering('Haderup'); return; }
      if (e.target.closest('#btn-print')) { window.print(); return; }
      if (e.target.closest('#btn-save') || e.target.closest('#btn-save-bottom')) { saveExam(); return; }
    });

    root.addEventListener('input', (e) => {
      if (e.target.matches('.pd-input, .gm-input')) {
        const fdi = parseInt(e.target.dataset.fdi,10), sid = parseInt(e.target.dataset.site,10);
        const t = state.get(fdi);
        const s = t.sites.get(sid);
        const raw = e.target.value;
        const val = raw === '' ? null : parseInt(raw,10);
        if (e.target.matches('.pd-input')) {
          s.pd = (val != null && !isNaN(val)) ? Math.max(0, Math.min(12, val)) : null;
          e.target.className = 'pd-input ' + pdClass(s.pd);
        } else {
          s.gm = (val != null && !isNaN(val)) ? Math.max(-12, Math.min(12, val)) : null;
        }
        drawToothLines(fdi); updateStats();
      }
      if (e.target.matches('.mob-select, .fur-buccal, .fur-lingual, .fur-palatal')) {
        const fdi = parseInt(e.target.dataset.fdi,10);
        const t = state.get(fdi);
        if (e.target.matches('.mob-select')) t.mobility = parseInt(e.target.value||0,10);
        if (e.target.matches('.fur-buccal')) t.furcation.buccal = e.target.value==='' ? 0 : parseInt(e.target.value,10);
        if (e.target.matches('.fur-lingual')) t.furcation.lingual = e.target.value==='' ? 0 : parseInt(e.target.value,10);
        if (e.target.matches('.fur-palatal')) t.furcation.palatal = e.target.value==='' ? 0 : parseInt(e.target.value,10);
      }
    });

    // Keyboard: Enter moves to next PD input in probing order
    root.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && e.target.matches('.pd-input, .gm-input')) {
        e.preventDefault();
        const inputs = Array.from(document.querySelectorAll('.pd-input:not([disabled])'));
        const idx = inputs.indexOf(e.target);
        const next = inputs[idx+1] || inputs[0];
        if (next) next.focus();
      }
    });
  }

  function renderTooth(fdi) {
    const old = document.querySelector(`.tooth-card[data-tooth="${fdi}"]`);
    if (!old) return;
    const tmp = document.createElement('div');
    tmp.innerHTML = toothCardHTML(fdi);
    const fresh = tmp.firstElementChild;
    fresh.querySelector('.tooth-num').textContent = displayNumber(fdi);
    old.replaceWith(fresh);
    drawToothLines(fdi);
  }

  // ---------- Save ----------
  function serialize() {
    const teeth = [];
    const legacy = [];
    state.forEach((t, fdi) => {
      // Even missing teeth are saved as tooth row (no sites)
      const sites = [];
      t.sites.forEach((s, sid) => {
        const pd = s.pd;
        const gm = s.gm;
        const al = (pd != null && gm != null) ? (pd - gm) : (pd != null ? pd - (gm||0) : null);
        if (pd != null || gm != null || s.bop || s.plaque || s.supp) {
          sites.push({ site: sid, pd, gm, al, bop: s.bop, plaque: s.plaque, supp: s.supp });
        }
        // legacy dual-write entry
        if (pd != null || gm != null || s.bop || s.supp) {
          const lg = siteToLegacy(sid);
          if (lg) {
            legacy.push({
              tooth_fdi: fdi, surface: lg.surface, point: lg.point,
              probing_depth: pd, recession: gm != null ? -gm : null,
              bleeding: s.bop, suppuration: s.supp,
              furcation: sid===2 ? t.furcation.buccal : 0,
              mobility: sid===2 ? t.mobility : 0
            });
          }
        }
      });
      teeth.push({
        fdi, status: t.status, mobility: t.mobility || 0,
        furcation: t.furcation, sites
      });
    });

    return {
      diagnosis: document.getElementById('perio_diagnosis')?.value || '',
      grade: document.getElementById('perio_grade')?.value || '',
      notes: document.getElementById('perio_notes')?.value || '',
      teeth,
      details: legacy // backward compat payload
    };
  }

  function saveExam() {
    const payload = serialize();
    const btn = document.getElementById('btn-save');
    const label = document.getElementById('save-label');
    if (btn) btn.disabled = true;
    if (label) label.textContent = i18n.saving || 'Saving...';

    const fd = new FormData();
    fd.append('csrf_token_form', cfg.csrfToken || '');
    fd.append('formid', cfg.formId || 0);
    fd.append('payload', JSON.stringify(payload));

    fetch(cfg.saveUrl, { method: 'POST', body: fd })
      .then(r => r.json())
      .then(resp => {
        if (resp.success) {
          if (label) label.textContent = i18n.saveExam || 'Save Exam';
          if (btn) btn.disabled = false;
          alert(resp.message || (i18n.savedOk || 'Saved'));
          // If new form created, reload with id
          if (resp.formid && String(resp.formid) !== String(cfg.formId)) {
            window.location.href = window.location.pathname + '?id=' + resp.formid;
          }
        } else {
          alert((i18n.saveError || 'Error') + ': ' + (resp.message || ''));
          if (btn) btn.disabled = false;
          if (label) label.textContent = i18n.saveExam || 'Save Exam';
        }
      })
      .catch(() => {
        alert(i18n.saveError || 'Error');
        if (btn) btn.disabled = false;
        if (label) label.textContent = i18n.saveExam || 'Save Exam';
      });
  }

  // ---------- Boot ----------
  document.addEventListener('DOMContentLoaded', () => {
    initState();
    renderArches();
    // Highlight active numbering button
    const cur = localStorage.getItem('perio_numbering') || 'FDI';
    const map = {'btn-fdi':'FDI','btn-univ':'Universal','btn-palmer':'Palmer','btn-haderup':'Haderup'};
    Object.keys(map).forEach(bid => {
      const el = document.getElementById(bid);
      if (el) el.className = (map[bid] === cur) ? 'btn btn-primary' : 'btn btn-outline-secondary';
    });
  });
})();
