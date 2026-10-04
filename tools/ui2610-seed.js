#!/usr/bin/env node
// UI2610 seed: topic tree + arguments (with source + default indicator) → POST /entries.
// Usage: node tools/ui2610-seed.js <baseUrl> <tid> [topicBase=/ui2610] [--dry]
//   e.g. node tools/ui2610-seed.js https://fayf.info/dev ui2610play /ui2610
// Data: .ai/ui2610/seed/topics-de.json, arguments-de.json (web, s01…), arguments-bt21.json (Bundestag, b001…) — ADR-8, REQ-UI2610-35/36/39
// Append-only store: re-running writes newer rows for the same keys (latest wins), no duplicates.
const fs = require('fs');
const path = require('path');

const [baseUrl, tid, topicBase = '/ui2610', flag] = process.argv.slice(2);
const dry = flag === '--dry' || topicBase === '--dry';
const base = (topicBase === '--dry' ? '/ui2610' : topicBase).replace(/\/+$/, '');

const dir = path.join(__dirname, '..', '.ai', 'ui2610', 'seed');
const topics = JSON.parse(fs.readFileSync(path.join(dir, 'topics-de.json'), 'utf8')).topics;
const args = JSON.parse(fs.readFileSync(path.join(dir, 'arguments-de.json'), 'utf8'));
const bt21File = path.join(dir, 'arguments-bt21.json');   // 800 entries from Bundestag protocols (21st term), ids b001…
const bt21 = fs.existsSync(bt21File) ? JSON.parse(fs.readFileSync(bt21File, 'utf8')) : [];

const clean = s => String(s).replace(/\s*\|\s*/g, ' / ').trim();
const end = s => (/[.!?>-]$/.test(s) ? s : s + '.');

/** Pure: topic rows, parents first. */
function topicLines(base, topics) {
    return topics
        .slice().sort((a, b) => a.path.split('/').length - b.path.split('/').length)
        .map(t => [`${base}/${t.path}`, 'kind:topic', ...(t.see && t.see.length ? [`see:${t.see.join(',')}`] : []), end(clean(t.title))].join(' | '));
}
/** Pure: argument rows; ids <prefix><n> (zero-padded, stable). Optional verbatim `quote:`. */
function argLines(base, args, prefix = 's') {
    const width = prefix === 's' ? 2 : 3;   // fixed per set: s01… (web) stays stable, b001… (Bundestag)
    return args.map((a, i) => {
        const ind = a.ind;
        return [`${base}/${a.path}/${prefix}${String(i + 1).padStart(width, '0')}`,
            `src:[${clean(a.source.name).replace(/[\[\]]/g, '')}](${a.source.url})`,
            `ind_default:${[ind.kP, ind.kI, ind.sPm, ind.sPp, ind.sIm, ind.sIp].join(',')}`,
            ...(a.quote ? [`quote:${clean(a.quote).replace(/\s+/g, ' ')}`] : []),
            end(clean(a.text))].join(' | ');
    });
}
/** Pure: seed rows in posting order (topics parent-first, then arguments). */
function seedLines(base, topics, args) {
    return topicLines(base, topics).concat(argLines(base, args, 's'));
}

async function post(line) {
    for (let attempt = 0; attempt < 5; attempt++) {
        const res = await fetch(`${baseUrl.replace(/\/+$/, '')}/entries?` + new URLSearchParams({ sid: 'seed-ui2610', tid }),
            { method: 'POST', body: new URLSearchParams({ entry: line }) });
        if (res.status === 201) return true;
        if (res.status === 429) { await new Promise(r => setTimeout(r, 5000 * (attempt + 1))); continue; }
        console.error('FAIL', res.status, (await res.text()).slice(0, 200), '\n  ', line);
        return false;
    }
    return false;
}

if (require.main === module) (async () => {
    if (!baseUrl || !/^[a-zA-Z0-9_-]{1,30}$/.test(tid || '')) {
        console.error('usage: node tools/ui2610-seed.js <baseUrl> <tid> [topicBase=/ui2610] [--dry]');
        process.exit(2);
    }
    const lines = seedLines(base, topics, args).concat(argLines(base, bt21, 'b'));
    if (dry) { lines.forEach(l => console.log(l)); console.error(`${lines.length} lines (dry run)`); return; }
    let ok = 0;
    for (const l of lines) { if (await post(l)) ok++; await new Promise(r => setTimeout(r, 150)); }
    console.log(`${ok}/${lines.length} rows posted to ${baseUrl} tid=${tid} base=${base}`);
    process.exit(ok === lines.length ? 0 : 1);
})();

module.exports = { seedLines, topicLines, argLines };
