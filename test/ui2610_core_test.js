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
eq('box: all 3 → core stays dunkel (ADR-5: black core)', C.stateGrid({ kP: 2, kI: 2, sPm: 3, sPp: 3, sIm: 3, sIp: 3 })[2][2], 'dunkel');
eq('box: symmetric is a subset (P −1 +1 == both sides)', T(C.stateGrid({ kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 0, sIp: 0 })), '...../...../.mdm./...../.....');

// ── Severity colouring (UI2610-ADR-5) ────────────────────────────────────────
eq('severity scale 9 steps green→red', [C.SEVERITY.length, C.SEVERITY[0], C.SEVERITY[8]], [9, '#2f9e5b', '#b02525']);
const wide = { kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 1, sIp: 1 };
eq('cellStyle: core = black', C.cellStyle(wide, 2, 2), { fill: '#14171A', opacity: 1 });
eq('cellStyle: ring 1 = score colour .75', C.cellStyle(wide, 3, 3), { fill: C.SEVERITY[6], opacity: 0.75 });
eq('cellStyle: ring 2 = score colour .5', C.cellStyle({ ...wide, sPp: 2 }, 4, 2), { fill: C.SEVERITY[6], opacity: 0.5 });
eq('cellStyle: outside range = null', C.cellStyle(wide, 0, 0), null);
const one = { kP: 3, kI: 1, sPm: 0, sPp: 0, sIm: 0, sIp: 0 };
eq('cellStyle: single point → faded own colour', C.cellStyle(one, 3, 1), { fill: C.SEVERITY[4], opacity: 0.5 });
eq('iconSvg: black core rect', C.iconSvg(wide).includes('fill="#14171A"'), true);
eq('iconSvg: single point has no black', C.iconSvg(one).includes('#14171A'), false);

// ── Magnitude area V2 (UI2610-ADR-6): grey rectangle from 0,0 to the core ──────
eq('magnitudeRect origin', C.magnitudeRect({ kP: 0, kI: 0 }), { x: 0, y: 4, w: 1, h: 1 });
eq('magnitudeRect mid', C.magnitudeRect({ kP: 2, kI: 1 }), { x: 0, y: 3, w: 3, h: 2 });
eq('magnitudeRect max', C.magnitudeRect({ kP: 4, kI: 4 }), { x: 0, y: 0, w: 5, h: 5 });
const svgM = C.iconSvg({ kP: 2, kI: 1, sPm: 0, sPp: 0, sIm: 0, sIp: 0 });
eq('iconSvg draws grey area first (below the box)', svgM.indexOf(`fill="${C.MAGNITUDE}"`) > 0 && svgM.indexOf(`fill="${C.MAGNITUDE}"`) < svgM.indexOf('fill="' + C.SEVERITY[3] + '"'), true);
eq('iconSvg grey area geometry (icon offset y+1)', svgM.includes(`<rect x="0" y="4" width="3" height="2" fill="${C.MAGNITUDE}"/>`), true);

// boxRect: range as one rectangle in grid coords (x = kP, y = row 0 top), clipped — editor group outline
eq('boxRect sym 1', C.boxRect({ kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 1, sIp: 1 }), { x: 1, y: 1, w: 3, h: 3 });
eq('boxRect asym', C.boxRect({ kP: 1, kI: 2, sPm: 0, sPp: 2, sIm: 1, sIp: 0 }), { x: 1, y: 2, w: 3, h: 2 });
eq('boxRect clipped', C.boxRect({ kP: 0, kI: 4, sPm: 3, sPp: 1, sIm: 1, sIp: 3 }), { x: 0, y: 0, w: 2, h: 2 });
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



// ── Topics (REQ-UI2610-31) ───────────────────────────────────────────────────
eq('slugify', [C.slugify('Klima'), C.slugify('Rente & Pflege 2030'), C.slugify('  Über-Größe  '), C.slugify('!!')], ['klima', 'rente-pflege-2030', 'ueber-groesse', '']);
const ent = {
    '/b/klima':      { message: 'Klima.', attrs: { kind: 'topic' } },
    '/b/klima/n1':   { message: 'Hitze.', attrs: {} },
    '/b/rente':      { message: 'Rente.', attrs: { kind: 'topic' } },
    '/b/loose':      { message: 'Altes Item.', attrs: {} },
    '/b/klima/x/y':  { message: 'tief.', attrs: { kind: 'topic' } },
    '/other/t':      { message: 'Anders.', attrs: { kind: 'topic' } },
};
eq('topicsOf: direct kind:topic children, by title', C.topicsOf(ent, '/b'), [{ key: '/b/klima', title: 'Klima' }, { key: '/b/rente', title: 'Rente' }]);
eq('topicsOf: root /', C.topicsOf({ '/k': { message: 'K.', attrs: { kind: 'topic' } } }, '/'), [{ key: '/k', title: 'K' }]);
eq('isTopicEntry', [C.isTopicEntry(ent['/b/klima']), C.isTopicEntry(ent['/b/loose'])], [true, false]);

// ── Nested topics, sources, default indicator (REQ-UI2610-34..36) ───────────
eq('crumbPath base', C.crumbPath('/b', '/b'), ['/b']);
eq('crumbPath 3 levels', C.crumbPath('/b', '/b/soziales/rente/rentenniveau'), ['/b', '/b/soziales', '/b/soziales/rente', '/b/soziales/rente/rentenniveau']);
eq('crumbPath root /', C.crumbPath('/', '/k/x'), ['/', '/k', '/k/x']);
eq('crumbPath outside base', C.crumbPath('/b', '/other/t'), ['/other/t']);
eq('topicsOf nested children', C.topicsOf({ '/b/s': { message: 'S.', attrs: { kind: 'topic' } }, '/b/s/r': { message: 'Rente.', attrs: { kind: 'topic' } }, '/b/s/r/x': { message: 'X.', attrs: {} } }, '/b/s'), [{ key: '/b/s/r', title: 'Rente' }]);
eq('seeKeys relative to base', C.seeKeys('soziales/armut,gesundheit/beitraege', '/b'), ['/b/soziales/armut', '/b/gesundheit/beitraege']);
eq('seeKeys empty', C.seeKeys('', '/b'), []);
eq('parseSrc md link', C.parseSrc('[Destatis, 30.09.2026](https://www.destatis.de/x.html)'), { name: 'Destatis, 30.09.2026', url: 'https://www.destatis.de/x.html' });
eq('parseSrc bare url', C.parseSrc('https://a.de/b'), { name: 'a.de', url: 'https://a.de/b' });
eq('parseSrc non-http rejected', C.parseSrc('[x](javascript:alert(1))'), null);
eq('parseSrc empty', C.parseSrc(''), null);
const def = C.parseInd('3,2,1,1,1,1');
eq('effectiveInd own wins', C.effectiveInd('1,1,0,0,0,0', '3,2,1,1,1,1'), { ind: C.parseInd('1,1,0,0,0,0'), isDefault: false });
eq('effectiveInd default when none', C.effectiveInd(undefined, '3,2,1,1,1,1'), { ind: def, isDefault: true });
eq('effectiveInd invalid default ignored', C.effectiveInd(undefined, '9,9'), { ind: null, isDefault: false });

// ── Seed lines (REQ-UI2610-35) ───────────────────────────────────────────────
const { seedLines } = require('../tools/ui2610-seed.js');
const sl = seedLines('/b', [{ path: 's/r', title: 'Rente' }, { path: 's', title: 'Soziales', see: ['g/x', 'w'] }],
    [{ path: 's/r', text: 'Rentenniveau sinkt | stark', source: { name: 'DRV [2026]', url: 'https://x.de/a' }, ind: { kP: 3, kI: 2, sPm: 1, sPp: 0, sIm: 1, sIp: 1 } }]);
eq('seed: parent topic first, see attr', sl[0], '/b/s | kind:topic | see:g/x,w | Soziales.');
eq('seed: child topic', sl[1], '/b/s/r | kind:topic | Rente.');
eq('seed: argument row', sl[2], '/b/s/r/s01 | src:[DRV 2026](https://x.de/a) | ind_default:3,2,1,0,1,1 | Rentenniveau sinkt / stark.');
const { argLines } = require('../tools/ui2610-seed.js');
eq('seed: bt21 row with prefix + quote', argLines('/b', [{ path: 's/r', text: 'Option: Haltelinie verlängern.', source: { name: 'Plenarprotokoll 21/48 · A (SPD)', url: 'https://dserver.bundestag.de/btp/21/21048.pdf' }, quote: 'Wir halten | das Niveau', ind: { kP: 2, kI: 2, sPm: 1, sPp: 1, sIm: 1, sIp: 1 } }], 'b')[0],
   '/b/s/r/b001 | src:[Plenarprotokoll 21/48 · A (SPD)](https://dserver.bundestag.de/btp/21/21048.pdf) | ind_default:2,2,1,1,1,1 | quote:Wir halten / das Niveau | Option: Haltelinie verlängern.');
const pe = require('../assets/ui2610-core.js');
eq('seed src parses back', pe.parseSrc('[DRV 2026](https://x.de/a)'), { name: 'DRV 2026', url: 'https://x.de/a' });

// ── Topic cards: subtree counts (REQ-UI2610-37) ──────────────────────────────
const tree = {
    '/b/s':        { message: 'Soziales.', attrs: { kind: 'topic' } },
    '/b/s/r':      { message: 'Rente.', attrs: { kind: 'topic' } },
    '/b/s/r/n':    { message: 'Niveau.', attrs: { kind: 'topic' } },
    '/b/s/r/n/a1': { message: 'Arg 1.', attrs: {} },
    '/b/s/r/a2':   { message: 'Arg 2.', attrs: {} },
    '/b/s/p':      { message: 'Pflege.', attrs: { kind: 'topic' } },
    '/b/w':        { message: 'Welt.', attrs: { kind: 'topic' } },
    '/b/s/~cmp/x': { message: 'Vergleich.', attrs: {} },
};
eq('subtree: topics + items, any depth', C.subtreeItems(tree, '/b/s'), ['/b/s/r/a2', '/b/s/r/n/a1']);
eq('subtree: topic count', C.subtreeTopicCount(tree, '/b/s'), 3);
eq('subtree: leaf topic', [C.subtreeItems(tree, '/b/s/p'), C.subtreeTopicCount(tree, '/b/s/p')], [[], 0]);

console.log(`${fail === 0 ? 'OK' : 'FAIL'} — ${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
