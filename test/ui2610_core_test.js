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

// ── Box indicator (UI2610-ADR-4): every cell in the σ range is filled ───────
// grid rows: row 0 = kI 4 (top). g[row][kP]
const T = (g) => g.map(r => r.map(t => (t ? t[0] : '.')).join('')).join('/');   // d/m/h/. per cell
eq('box: σ 0 = core only', T(C.stateGrid({ kP: 2, kI: 2, sPm: 0, sPp: 0, sIm: 0, sIp: 0 })), '...../...../..d../...../.....');
eq('box: σ 1 all sides = 3×3', T(C.stateGrid({ kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 1, sIp: 1 })), '...../.mmm./.mdm./.mmm./.....');
eq('box: σ 2 all sides = 5×5, ring 2 light', T(C.stateGrid({ kP: 2, kI: 2, sPm: 2, sPp: 2, sIm: 2, sIp: 2 })), 'hhhhh/hmmmh/hmdmh/hmmmh/hhhhh');
eq('box: asym P −0 +2, I −1 +0', T(C.stateGrid({ kP: 1, kI: 2, sPm: 0, sPp: 2, sIm: 1, sIp: 0 })), '...../...../.dmh./.mmh./.....');
eq('box: clipped at edge', T(C.stateGrid({ kP: 0, kI: 4, sPm: 3, sPp: 1, sIm: 1, sIp: 3 })), 'dm.../mm.../...../...../.....');
eq('box: step 3 reaches 3 cells', T(C.stateGrid({ kP: 0, kI: 0, sPm: 0, sPp: 3, sIm: 0, sIp: 0 })), '...../...../...../...../dmhh.');
eq('box: entwertet all 3 → core light', C.stateGrid({ kP: 2, kI: 2, sPm: 3, sPp: 3, sIm: 3, sIp: 3 })[2][2], 'hell');
eq('box: symmetric is a subset (P −1 +1 == both sides)', T(C.stateGrid({ kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 0, sIp: 0 })), '...../...../.mdm./...../.....');

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



console.log(`${fail === 0 ? 'OK' : 'FAIL'} — ${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
