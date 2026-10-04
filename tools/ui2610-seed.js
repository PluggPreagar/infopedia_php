#!/usr/bin/env node
// UI2610 seed: topic tree + arguments (with source + default indicator) → POST /entries.
// Usage: node tools/ui2610-seed.js <baseUrl> <tid> [topicBase=/ui2610] [--dry]
//   e.g. node tools/ui2610-seed.js https://fayf.info/dev ui2610play /ui2610
// Data: .ai/ui2610/seed/topics-de.json, .ai/ui2610/seed/arguments-de.json (ADR-7, REQ-UI2610-35/36)
// Append-only store: re-running writes newer rows for the same keys (latest wins), no duplicates.
const fs = require('fs');
const path = require('path');

const [baseUrl, tid, topicBase = '/ui2610', flag] = process.argv.slice(2);
const dry = flag === '--dry' || topicBase === '--dry';
const base = (topicBase === '--dry' ? '/ui2610' : topicBase).replace(/\/+$/, '');

const dir = path.join(__dirname, '..', '.ai', 'ui2610', 'seed');
const topics = JSON.parse(fs.readFileSync(path.join(dir, 'topics-de.json'), 'utf8')).topics;
const args = JSON.parse(fs.readFileSync(path.join(dir, 'arguments-de.json'), 'utf8'));

/** Pure: seed rows in posting order (topics parent-first, then arguments). */
function seedLines(base, topics, args) {
    const clean = s => String(s).replace(/\s*\|\s*/g, ' / ').trim();
    const end = s => (/[.!?>-]$/.test(s) ? s : s + '.');
    const lines = topics
        .slice().sort((a, b) => a.path.split('/').length - b.path.split('/').length)
        .map(t => [`${base}/${t.path}`, 'kind:topic', ...(t.see && t.see.length ? [`see:${t.see.join(',')}`] : []), end(clean(t.title))].join(' | '));
    args.forEach((a, i) => {
        const id = 's' + String(i + 1).padStart(2, '0');
        const ind = a.ind;
        lines.push([`${base}/${a.path}/${id}`, `src:[${clean(a.source.name).replace(/[\[\]]/g, '')}](${a.source.url})`,
            `ind_default:${[ind.kP, ind.kI, ind.sPm, ind.sPp, ind.sIm, ind.sIp].join(',')}`, end(clean(a.text))].join(' | '));
    });
    return lines;
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
    const lines = seedLines(base, topics, args);
    if (dry) { lines.forEach(l => console.log(l)); console.error(`${lines.length} lines (dry run)`); return; }
    let ok = 0;
    for (const l of lines) { if (await post(l)) ok++; await new Promise(r => setTimeout(r, 150)); }
    console.log(`${ok}/${lines.length} rows posted to ${baseUrl} tid=${tid} base=${base}`);
    process.exit(ok === lines.length ? 0 : 1);
})();

module.exports = { seedLines };
