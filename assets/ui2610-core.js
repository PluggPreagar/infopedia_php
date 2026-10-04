/**
 * assets/ui2610-core.js — pure helpers for vote-mobile.html (UI2610).
 * No DOM, no fetch. Plain globals in the browser; module.exports under node (tests).
 * Decisions: .ai/adr/ui2610-adr-2-set-kinds.md, ui2610-adr-3-indicator-model.md, ui2610-adr-4-box-indicator.md
 */

// ── Histograms (backend projection "<v>=<n>;…") ──────────────────────────────
function parseHist(s) {
    const out = {};
    for (const part of (s || '').split(';')) {
        const i = part.lastIndexOf('=');
        if (i > 0) out[part.slice(0, i)] = parseInt(part.slice(i + 1), 10) || 0;
    }
    return out;
}

const IND_KEYS = ['kP', 'kI', 'sP-', 'sP+', 'sI-', 'sI+'];
function parseIndHist(s) {
    if (!s) return null;
    const out = {};
    for (const part of s.split(';')) {
        const i = part.indexOf('=');
        out[part.slice(0, i)] = part.slice(i + 1).split(',').map(Number);
    }
    return out;
}
function parseInd(s) {
    if (!s) return null;
    const [kP, kI, sPm, sPp, sIm, sIp] = s.split(',').map(Number);
    return { kP, kI, sPm, sPp, sIm, sIp };
}
function formatInd(v) {
    return [v.kP, v.kI, v.sPm, v.sPp, v.sIm, v.sIp].join(',');
}

// ── Rating ───────────────────────────────────────────────────────────────────
function ratingStats(own, othersHist) {
    let n = 0, sum = 0;
    const r = parseInt(own, 10);
    if (r >= 1 && r <= 5) { n++; sum += r; }
    for (const [v, c] of Object.entries(parseHist(othersHist))) { n += c; sum += Number(v) * c; }
    return { n, mean: n ? sum / n : null };
}

// ── Indicator: pooled group value (ADR-3) ────────────────────────────────────
const clamp = (x, lo, hi) => Math.max(lo, Math.min(hi, x));

/** One axis. cores: 5 bins, sm/sp: 4 bins (σ step 0..3), all Voters incl. own. */
function pooledAxis(cores, sm, sp) {
    const n = cores.reduce((a, b) => a + b, 0);
    if (!n) return null;
    const mu = cores.reduce((a, c, k) => a + c * k, 0) / n;
    let svLo = 0, svHi = 0;
    cores.forEach((c, k) => {
        if (k < mu) svLo += c * (mu - k) ** 2;
        if (k > mu) svHi += c * (k - mu) ** 2;
    });
    const meanSq = bins => bins.reduce((a, c, s) => a + c * s * s, 0) / n;
    // ×2: a symmetric spread splits its variance into two halves
    const side = (bins, sv) => clamp(Math.round(Math.sqrt(meanSq(bins) + 2 * sv / n)), 0, 3);
    return { k: clamp(Math.round(mu), 0, 4), sm: side(sm, svLo), sp: side(sp, svHi) };
}

function groupInd(own, hist) {
    if (!own && !hist) return null;
    const h = {};
    IND_KEYS.forEach((key, i) => { h[key] = hist ? hist[key].slice() : Array(i < 2 ? 5 : 4).fill(0); });
    if (own) {
        h.kP[own.kP]++; h.kI[own.kI]++;
        h['sP-'][own.sPm]++; h['sP+'][own.sPp]++; h['sI-'][own.sIm]++; h['sI+'][own.sIp]++;
    }
    const P = pooledAxis(h.kP, h['sP-'], h['sP+']);
    const I = pooledAxis(h.kI, h['sI-'], h['sI+']);
    if (!P || !I) return null;
    return { kP: P.k, kI: I.k, sPm: P.sm, sPp: P.sp, sIm: I.sm, sIp: I.sp };
}

function isFit(a, b) {
    return !!a && !!b && formatInd(a) === formatInd(b);
}

// ── Icon (box indicator, ADR-4; colours + frame from Schema F) ───────────────
const TONE = {
    pro:    { dunkel: '#0E7A66', mittel: '#4E9E8C', hell: '#79BDAE' },
    contra: { dunkel: '#C03A72', mittel: '#D5709A', hell: '#E599BB' },
};
const FRAME = '#C6CBD0';

/** Box indicator (UI2610-ADR-4, supersedes Schema F satellites): every cell in
 *  P[kP−sPm, kP+sPp] × I[kI−sIm, kI+sIp] is filled — every combination in the range is valid.
 *  σ step = cells (0..3), clipped at the grid edge. Tone = distance (F2 idea): core dunkel ·
 *  ring 1 mittel · ring ≥ 2 hell. Core hell ("entwertet") only when all four sides are 3.
 *  5×5 grid, row 0 = highest Impact; null = white. */
function stateGrid(v) {
    const g = Array.from({ length: 5 }, () => Array(5).fill(null));
    for (let kI = Math.max(0, v.kI - v.sIm); kI <= Math.min(4, v.kI + v.sIp); kI++) {
        for (let kP = Math.max(0, v.kP - v.sPm); kP <= Math.min(4, v.kP + v.sPp); kP++) {
            const ring = Math.max(Math.abs(kP - v.kP), Math.abs(kI - v.kI));
            g[4 - kI][kP] = ring === 0 ? 'dunkel' : ring === 1 ? 'mittel' : 'hell';
        }
    }
    if (v.sPm === 3 && v.sPp === 3 && v.sIm === 3 && v.sIp === 3) g[4 - v.kI][v.kP] = 'hell';
    return g;
}

function iconSvg(v, opts = {}) {
    const { side = 'pro', size = 32, fit = false, faint = false } = opts;
    const T = TONE[side], g = stateGrid(v);
    const isPro = side === 'pro';
    const gx = isPro ? 0 : 1, gy = isPro ? 1 : 0, rx = isPro ? 5 : 0, ry = isPro ? 0 : 5;
    const label = `P ${v.kP + 1}/5, Impact ${v.kI + 1}/5, σ P −${v.sPm} +${v.sPp}, σ I −${v.sIm} +${v.sIp}`;
    let s = `<svg class="ind${fit ? ' fit' : ''}${faint ? ' faint' : ''}" width="${size}" height="${size}" viewBox="0 0 6 6" shape-rendering="crispEdges" role="img" aria-label="${label}">`;
    s += `<rect x="${rx}" y="0" width="1" height="6" fill="${FRAME}"/>`;
    s += `<rect x="${gx}" y="${ry}" width="5" height="1" fill="${FRAME}"/>`;
    for (let r = 0; r < 5; r++) for (let c = 0; c < 5; c++) {
        if (g[r][c]) s += `<rect x="${gx + c}" y="${gy + r}" width="1" height="1" fill="${T[g[r][c]]}"/>`;
    }
    return s + '</svg>';
}

// ── Editor gesture (REQ-UI2610-23) ───────────────────────────────────────────
/** x, y normalised 0..1 over the 5×5 grid (y top→bottom). */
function cellAt(x, y) {
    return { kP: clamp(Math.floor(x * 5), 0, 4), kI: 4 - clamp(Math.floor(y * 5), 0, 4) };
}
/** core = tap cell; lo = drag end towards lower-left (symmetric σ); hi = later drag end towards upper-right (σ+). */
function dragInd(core, lo, hi) {
    const st = d => clamp(d, 0, 3);
    const v = { kP: core.kP, kI: core.kI, sPm: 0, sPp: 0, sIm: 0, sIp: 0 };
    if (lo) {
        v.sPm = v.sPp = st(core.kP - lo.kP);
        v.sIm = v.sIp = st(core.kI - lo.kI);
    }
    if (hi) {
        v.sPp = st(hi.kP - core.kP);
        v.sIp = st(hi.kI - core.kI);
    }
    return v;
}

/** One drag: the spread only grows (min towards lower-left, max towards upper-right). */
const growLo = (lo, c) => (lo ? { kP: Math.min(lo.kP, c.kP), kI: Math.min(lo.kI, c.kI) } : c);
const growHi = (hi, c) => (hi ? { kP: Math.max(hi.kP, c.kP), kI: Math.max(hi.kI, c.kI) } : c);

// ── Trust filter (REQ-UI2610-6) ──────────────────────────────────────────────
function visibleSigns(signers, names, trusted, ownSid) {
    const nameOf = {};
    for (const part of (names || '').split(';')) {
        const i = part.indexOf('=');
        if (i > 0) nameOf[part.slice(0, i)] = part.slice(i + 1);
    }
    return (signers || '').split(',').filter(Boolean)
        .filter(sid => sid === ownSid || trusted.has(sid))
        .map(sid => ({ sid, name: nameOf[sid] || '#' + sid }));
}

// ── Comparison + ranking (REQ-UI2610-5, -7) ──────────────────────────────────
const pairKey = (a, b) => (a < b ? `${a}~${b}` : `${b}~${a}`);

/** Bradley–Terry (MM). cmp: "a~b" → {'-1': b wins, '0': tie, '1': a wins}. Prior: 1 virtual win + loss vs strength 1. */
function bradleyTerry(ids, cmp, iter = 100) {
    const p = Object.fromEntries(ids.map(id => [id, 1]));
    const games = [];
    for (const [key, h] of Object.entries(cmp)) {
        const [a, b] = key.split('~');
        if (!(a in p) || !(b in p)) continue;
        const tie = (h['0'] || 0) / 2;
        games.push({ a, b, wa: (h['1'] || 0) + tie, wb: (h['-1'] || 0) + tie });
    }
    for (let t = 0; t < iter; t++) {
        const next = {};
        for (const id of ids) {
            let w = 1, d = 2 / (p[id] + 1);
            for (const g of games) {
                const n = g.wa + g.wb;
                if (g.a === id) { w += g.wa; d += n / (p[id] + p[g.b]); }
                if (g.b === id) { w += g.wb; d += n / (p[id] + p[g.a]); }
            }
            next[id] = w / d;
        }
        Object.assign(p, next);
    }
    return p;
}

/** items: [{id, mean}]. Rounded mean first; Bradley–Terry breaks ties inside the top N. */
function rank(items, cmp, topN = 15) {
    const byMean = items.slice().sort((x, y) => (y.mean ?? -1) - (x.mean ?? -1));
    const top = byMean.slice(0, topN).filter(x => x.mean !== null);
    const bt = bradleyTerry(top.map(x => x.id), cmp);
    const key = x => Math.round(x.mean);
    top.sort((x, y) => key(y) - key(x) || bt[y.id] - bt[x.id] || y.mean - x.mean);
    return top.concat(byMean.filter(x => !top.includes(x)));
}

/** list: [{id, r}] own rounded Rating, best first. compared: pairKey → own count. */
function nextPairs(list, compared, limit = 5) {
    const cand = [];
    list.forEach((x, i) => list.slice(i + 1).forEach(y => {
        if (x.r != null && y.r != null && Math.abs(x.r - y.r) <= 1) {
            cand.push({ pair: [x.id, y.id], n: compared[pairKey(x.id, y.id)] || 0, eq: x.r === y.r ? 0 : 1 });
        }
    }));
    cand.sort((a, b) => a.n - b.n || a.eq - b.eq);
    return cand.slice(0, limit).map(c => c.pair);
}

if (typeof module !== 'undefined') {
    module.exports = {
        parseHist, parseIndHist, parseInd, formatInd, ratingStats,
        pooledAxis, groupInd, isFit, stateGrid, iconSvg,
        cellAt, dragInd, growLo, growHi, visibleSigns, pairKey, bradleyTerry, rank, nextPairs,
    };
}
