// UI2610 frontend core — pure functions. Run: node test/ui2610_core_test.js
// See .ai/adr/ui2610-adr-3-indicator-model.md, .ai/ui2610/req.md
const C = require('../assets/ui2610-core.js');

let pass = 0, fail = 0;
function eq(msg, actual, expected) {
    const a = JSON.stringify(actual), e = JSON.stringify(expected);
    if (a === e) { pass++; } else { fail++; console.log(`  FAIL: ${msg}\n    expected ${e}\n    actual   ${a}`); }
}

// ── histograms ───────────────────────────────────────────────────────────────
eq('parseHist', C.parseHist('3=2;5=1'), { '3': 2, '5': 1 });
eq('parseHist empty', C.parseHist(''), {});
eq('parseHist neg keys', C.parseHist('-1=1;1=2'), { '-1': 1, '1': 2 });
eq('parseIndHist', C.parseIndHist('kP=0,0,1,0,1;kI=1,0,0,1,0;sP-=0,1,0,1;sP+=1,1,0,0;sI-=1,0,1,0;sI+=1,0,1,0'),
   { kP: [0,0,1,0,1], kI: [1,0,0,1,0], 'sP-': [0,1,0,1], 'sP+': [1,1,0,0], 'sI-': [1,0,1,0], 'sI+': [1,0,1,0] });
eq('parseInd', C.parseInd('2,3,1,1,0,2'), { kP: 2, kI: 3, sPm: 1, sPp: 1, sIm: 0, sIp: 2 });
eq('formatInd', C.formatInd({ kP: 2, kI: 3, sPm: 1, sPp: 1, sIm: 0, sIp: 2 }), '2,3,1,1,0,2');

// ── rating stats (own + others) ──────────────────────────────────────────────
eq('ratingStats', C.ratingStats('4', '3=2;5=1'), { n: 4, mean: 3.75 });
eq('ratingStats none', C.ratingStats(null, ''), { n: 0, mean: null });

// ── pooled σ (REQ-UI2610-28) ─────────────────────────────────────────────────
eq('pooled: two cores 1/3, σ 0 → core 2, σ 1/1',
   C.pooledAxis([0,1,0,1,0], [2,0,0,0], [2,0,0,0]), { k: 2, sm: 1, sp: 1 });
eq('pooled: all agree, σ 0', C.pooledAxis([0,0,3,0,0], [3,0,0,0], [3,0,0,0]), { k: 2, sm: 0, sp: 0 });
eq('pooled: all agree, own σ 2 kept', C.pooledAxis([0,0,3,0,0], [0,0,3,0], [0,0,3,0]), { k: 2, sm: 2, sp: 2 });
eq('pooled: outlier above → only σ+ wide', C.pooledAxis([0,3,0,0,1], [4,0,0,0], [4,0,0,0]), { k: 2, sm: 1, sp: 2 });
eq('pooled: clamp to 3', C.pooledAxis([1,0,0,0,1], [0,0,0,2], [0,0,0,2]), { k: 2, sm: 3, sp: 3 });
eq('pooled: empty → null', C.pooledAxis([0,0,0,0,0], [0,0,0,0], [0,0,0,0]), null);

// group indicator from own + others histogram
const own = C.parseInd('1,1,0,0,0,0');
const hist = C.parseIndHist('kP=0,0,0,1,0;kI=0,0,0,1,0;sP-=1,0,0,0;sP+=1,0,0,0;sI-=1,0,0,0;sI+=1,0,0,0');
eq('groupInd', C.groupInd(own, hist), { kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 1, sIp: 1 });
eq('groupInd own only', C.groupInd(own, null), own);
eq('groupInd none', C.groupInd(null, null), null);
eq('isFit equal', C.isFit(own, { ...own }), true);
eq('isFit differs', C.isFit(own, { ...own, sPp: 1 }), false);
eq('isFit null', C.isFit(null, own), false);

// ── asymmetric satellites (REQ-UI2610-22) ────────────────────────────────────
// Schema F reference: satellites(k,d) with d = step 1 → 1, step 2|3 → 2
eq('sat sym = Schema F (k2 d1)', C.satellites(2, 1, 1), [1, 3]);
eq('sat sym = Schema F (k0 d2): edge shift', C.satellites(0, 2, 2), [1, 2]);
eq('sat sym = Schema F (k4 d1)', C.satellites(4, 1, 1), [3, 2]);
eq('sat asym k2 −1 +2', C.satellites(2, 1, 2), [1, 4]);
eq('sat step 0 one side', C.satellites(2, 0, 2), [4]);
eq('sat both 0', C.satellites(2, 0, 0), []);
eq('stepDist', [0,1,2,3].map(C.stepDist), [0,1,2,2]);

// state grid: symmetric input equals Schema F stateGrid (F2)
const g = C.stateGrid({ kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 2, sIp: 2 });
eq('grid core', g[2][2], 'dunkel');
eq('grid P satellites σ1 → mittel', [g[2][1], g[2][3]], ['mittel', 'mittel']);
eq('grid I satellites σ2 → hell', [g[0][2], g[4][2]], ['hell', 'hell']);
const ge = C.stateGrid({ kP: 2, kI: 2, sPm: 3, sPp: 3, sIm: 3, sIp: 3 });
eq('grid entwertet all 3', ge[2][2], 'hell');
const ga = C.stateGrid({ kP: 2, kI: 2, sPm: 0, sPp: 1, sIm: 0, sIp: 0 });
eq('grid asym only right', [ga[2][1], ga[2][3]], [null, 'mittel']);

const gx = C.stateGrid({ kP: 0, kI: 2, sPm: 1, sPp: 3, sIm: 0, sIp: 0 });
eq('grid edge shift keeps side tone', [gx[2][1], gx[2][2]], ['mittel', 'hell']);
const svg = C.iconSvg({ kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 1, sIp: 1 }, { size: 32 });
eq('iconSvg is svg', svg.startsWith('<svg') && svg.endsWith('</svg>'), true);
eq('iconSvg fit class', C.iconSvg({ kP: 0, kI: 0, sPm: 0, sPp: 0, sIm: 0, sIp: 0 }, { fit: true }).includes('class="ind fit"'), true);

// ── editor gesture → value (REQ-UI2610-23) ───────────────────────────────────
// cell coords: col 0..4 = kP, row 0..4 top→bottom = kI 4..0
eq('cellAt', C.cellAt(0.5, 0.1), { kP: 2, kI: 4 });
eq('cellAt clamp', C.cellAt(1.2, -0.3), { kP: 4, kI: 4 });
eq('dragInd: tap only', C.dragInd({ kP: 2, kI: 2 }, null, null), { kP: 2, kI: 2, sPm: 0, sPp: 0, sIm: 0, sIp: 0 });
eq('dragInd: lower-left = symmetric', C.dragInd({ kP: 2, kI: 2 }, { kP: 1, kI: 0 }, null), { kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 2, sIp: 2 });
eq('dragInd: then upper-right = σ+', C.dragInd({ kP: 2, kI: 2 }, { kP: 1, kI: 0 }, { kP: 4, kI: 3 }), { kP: 2, kI: 2, sPm: 1, sPp: 2, sIm: 2, sIp: 1 });
eq('dragInd: clamp 3', C.dragInd({ kP: 4, kI: 4 }, { kP: 0, kI: 0 }, null), { kP: 4, kI: 4, sPm: 3, sPp: 3, sIm: 3, sIp: 3 });

eq('growLo keeps extent', C.growLo({ kP: 1, kI: 2 }, { kP: 2, kI: 2 }), { kP: 1, kI: 2 });
eq('growLo extends', C.growLo({ kP: 1, kI: 2 }, { kP: 0, kI: 3 }), { kP: 0, kI: 2 });
eq('growHi first', C.growHi(null, { kP: 3, kI: 3 }), { kP: 3, kI: 3 });

// ── Trust filter (REQ-UI2610-6) ──────────────────────────────────────────────
eq('visibleSigns', C.visibleSigns('s1,s2,s3', 's1=Mart;s3=Anna', new Set(['s1', 's2']), 's9'),
   [{ sid: 's1', name: 'Mart' }, { sid: 's2', name: '#s2' }]);
eq('visibleSigns own always', C.visibleSigns('s9', 's9=Me', new Set(), 's9'), [{ sid: 's9', name: 'Me' }]);
eq('visibleSigns none', C.visibleSigns('', '', new Set(), 's9'), []);

// ── Bradley–Terry + pairs (REQ-UI2610-5, -7) ─────────────────────────────────
// cmp map: "a~b" → {'-1': n, '0': n, '1': n} incl. own
const bt = C.bradleyTerry(['a', 'b', 'c'], { 'a~b': { '1': 3 }, 'b~c': { '1': 3 }, 'a~c': { '1': 2, '0': 1 } });
eq('BT order', Object.keys(bt).sort((x, y) => bt[y] - bt[x]), ['a', 'b', 'c']);
eq('BT no data = equal', C.bradleyTerry(['a', 'b'], {}), { a: 1, b: 1 });
eq('pairKey canonical', [C.pairKey('b', 'a'), C.pairKey('a', 'b')], ['a~b', 'a~b']);

// ranking: mean rating, then BT inside top N
const items = [{ id: 'a', mean: 4 }, { id: 'b', mean: 4 }, { id: 'c', mean: 2 }, { id: 'd', mean: null }];
eq('rank: BT breaks tie', C.rank(items, { 'a~b': { '-1': 2 } }, 15).map(x => x.id), ['b', 'a', 'c', 'd']);
eq('nextPairs: adjacent, never compared first', C.nextPairs([{ id: 'a', r: 5 }, { id: 'b', r: 5 }, { id: 'c', r: 4 }, { id: 'd', r: 2 }], { 'a~b': 1 }, 2),
   [['a', 'c'], ['b', 'c']]);

// ── Golden: symmetric σ == Schema F (icon-schema-f-weiss-3ton.html, verbatim, F2) ─
function refSatellites(k, d) {
    const used = new Set([k]), out = [];
    [k - d, k + d].forEach(want => {
        let pos = want;
        if (pos < 0 || pos > 4 || used.has(pos)) {
            const dir = want < 0 ? 1 : -1, start = want < 0 ? 0 : 4;
            for (let c = start; c >= 0 && c <= 4; c += dir) if (!used.has(c)) { pos = c; break; }
        }
        if (pos < 0 || pos > 4 || used.has(pos)) { for (let c = 0; c < 5; c++) if (!used.has(c)) { pos = c; break; } }
        used.add(pos); out.push(pos);
    });
    return out;
}
function refStateGrid(kP, kI, stP, stI) {
    const dOf = s => (s === 1 ? 1 : 2);
    const g = Array.from({ length: 5 }, () => Array(5).fill(null));
    const row = r => 4 - r;
    const satTone = st => (st === 1 ? 'mittel' : 'hell');
    refSatellites(kP, dOf(stP)).forEach(c => g[row(kI)][c] = satTone(stP));
    refSatellites(kI, dOf(stI)).forEach(r => g[row(r)][kP] = satTone(stI));
    const entwertet = stP === 3 && stI === 3;
    g[row(kI)][kP] = entwertet ? 'hell' : 'dunkel';
    return g;
}
let goldenFail = 0;
for (let kP = 0; kP < 5; kP++) for (let kI = 0; kI < 5; kI++) for (let sP = 1; sP <= 3; sP++) for (let sI = 1; sI <= 3; sI++) {
    const a = JSON.stringify(C.stateGrid({ kP, kI, sPm: sP, sPp: sP, sIm: sI, sIp: sI }));
    if (a !== JSON.stringify(refStateGrid(kP, kI, sP, sI))) goldenFail++;
}
eq('golden: 225 symmetric grids == Schema F', goldenFail, 0);

console.log(`${fail === 0 ? 'OK' : 'FAIL'} — ${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
