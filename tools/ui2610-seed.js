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

const mmss = sec => `${Math.floor(sec / 60)}:${String(Math.floor(sec % 60)).padStart(2, '0')}`;
/** Pure: one progress line — count, percent, ok/fail, elapsed, estimated time left, current key. */
function progressLine(done, total, startMs, nowMs, ok, fail, key) {
    const el = (nowMs - startMs) / 1000;
    const left = done ? el / done * (total - done) : 0;
    const w = String(total).length;
    return `[${String(done).padStart(w)}/${total}] ${String(Math.round(done / total * 100)).padStart(3)}% · ok ${ok} · fail ${fail} · ${mmss(el)} elapsed · ~${mmss(left)} left · ${key}`;
}

async function post(line) {
    for (let attempt = 0; attempt < 5; attempt++) {
        let res;
        try {
            res = await fetch(`${baseUrl.replace(/\/+$/, '')}/entries?` + new URLSearchParams({ sid: 'seed-ui2610', tid }),
                { method: 'POST', body: new URLSearchParams({ entry: line }) });
        } catch (err) {   // network error (offline, DNS, reset): wait and retry, then count as failed
            if (attempt === 4) { console.error(`\nFAIL network: ${err.cause ? err.cause.code || err.cause.message : err.message}\n   ${line.split(' | ')[0]}`); return false; }
            await new Promise(r => setTimeout(r, 2000 * (attempt + 1)));
            continue;
        }
        if (res.status === 201) return true;
        if (res.status === 429 || res.status >= 500) { await new Promise(r => setTimeout(r, 5000 * (attempt + 1))); continue; }
        console.error('\nFAIL', res.status, (await res.text()).slice(0, 200), '\n  ', line.split(' | ')[0]);
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
    const tty = process.stdout.isTTY, start = Date.now(), failed = [];
    console.log(`seeding ${lines.length} rows → ${baseUrl} tid=${tid} base=${base} (${topics.length} topics, ${args.length} web, ${bt21.length} Bundestag)`);
    let ok = 0, streak = 0;
    for (let i = 0; i < lines.length; i++) {
        const l = lines[i], key = l.split(' | ')[0];
        if (await post(l)) { ok++; streak = 0; } else { failed.push(key); streak++; }
        if (streak >= 5) { console.error(`\nstopping: 5 failures in a row (server unreachable or rejecting). ${ok} rows posted; re-run is safe.`); process.exit(1); }
        const msg = progressLine(i + 1, lines.length, start, Date.now(), ok, failed.length, key);
        if (tty) process.stdout.write('\r\x1b[K' + msg.slice(0, (process.stdout.columns || 120) - 1));   // one live line
        else if ((i + 1) % 25 === 0 || i + 1 === lines.length) console.log(msg);                          // log every 25 rows
        await new Promise(r => setTimeout(r, 150));
    }
    if (tty) process.stdout.write('\n');
    console.log(`${ok}/${lines.length} rows posted to ${baseUrl} tid=${tid} base=${base} in ${mmss((Date.now() - start) / 1000)}`);
    if (failed.length) console.log('failed keys (re-run is safe, latest wins):\n  ' + failed.join('\n  '));
    process.exit(ok === lines.length ? 0 : 1);
})();

module.exports = { seedLines, topicLines, argLines, progressLine };
