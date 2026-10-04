/**
 * Test cases for vote.html — loaded by wrapper.php?test=vote.html
 * Requires: test/harness.js (suite, assert, assertMatch, harnessFinish)
 * Pure helpers first, then DOM rendering, then optimistic vote/sign with the POST stubbed.
 */

const FIX = {
    '/frueher/a':  { message: 'Fakt A!',      timestamp: '2026-09-01 10:00:00', attrs: { src: 'https://www.dge.de/b21' } },
    '/frueher/b':  { message: 'Fakt B!',      timestamp: '2026-09-02 10:00:00', attrs: {} },
    '/frueher/c':  { message: 'Fake C!-',     timestamp: '2026-09-03 10:00:00', attrs: {} },
    '/frueher/q1': { message: 'Gegenfrage??', timestamp: '2026-09-04 10:00:00', attrs: {} },
    '/frueher/q2': { message: 'Unklar?',      timestamp: '2026-09-05 10:00:00', attrs: {} },
    '/frueher/m':  { message: 'Meinung.',     timestamp: '2026-09-06 10:00:00', attrs: {} },
    '/heute/z':    { message: 'Anderswo!',    timestamp: '2026-09-07 10:00:00', attrs: {} },
};

function testIsUnderTopic() {
    suite('isUnderTopic');
    assert('direct child',      isUnderTopic('/frueher/x',   '/frueher'),  true);
    assert('nested child',      isUnderTopic('/frueher/x/y', '/frueher'),  true);
    assert('folder itself',     isUnderTopic('/frueher',     '/frueher'),  true);
    assert('sibling prefix',    isUnderTopic('/frueherX/y',  '/frueher'),  false);
    assert('other folder',      isUnderTopic('/heute/x',     '/frueher'),  false);
    assert('root lists all',    isUnderTopic('/anything/x',  '/'),         true);
    assert('trailing slash ok', isUnderTopic('/frueher/x',   '/frueher/'), true);
}
testIsUnderTopic();

function testVoteListFor() {
    suite('voteListFor');
    const args = voteListFor(FIX, '/frueher', '!');
    assert('arguments: only ! under folder', args.map(i => i.key).sort(), ['/frueher/a', '/frueher/b']);
    assert('excludes !- (Fake)',    args.some(i => i.key === '/frueher/c'), false);
    assert('excludes . (Meinung)',  args.some(i => i.key === '/frueher/m'), false);
    assert('excludes other folder', args.some(i => i.key === '/heute/z'),   false);
    const qs = voteListFor(FIX, '/frueher', '??');
    assert('gegenfragen: only ??',  qs.map(i => i.key), ['/frueher/q1']);
    assert('excludes ? (Unklar)',   qs.some(i => i.key === '/frueher/q2'), false);
    assert('item shape',            Object.keys(args[0]).sort(), ['attrs', 'key', 'message', 'timestamp']);
    assert('missing attrs → {}',    voteListFor({ '/frueher/n': { message: 'X!' } }, '/frueher', '!')[0].attrs, {});
    assert('empty input',           voteListFor({}, '/frueher', '!'), []);
}
testVoteListFor();

function testSumVotes() {
    suite('sumVotes');
    assert('object sums',    sumVotes({ a: 1, b: 2, c: -1 }), 2);
    assert('number passes',  sumVotes(5), 5);
    assert('undefined → 0',  sumVotes(undefined), 0);
    assert('empty obj → 0',  sumVotes({}), 0);
}
testSumVotes();

function testScoreAndSort() {
    suite('scoreOf / signedOf / sortByScore');
    const vd = { '/frueher/a': { votes: 1, signed: 0 }, '/frueher/b': { votes: 5, signed: 2 } };
    assert('scoreOf known',    scoreOf(vd, '/frueher/b'),  5);
    assert('scoreOf missing',  scoreOf(vd, '/frueher/x'),  0);
    assert('signedOf known',   signedOf(vd, '/frueher/b'), 2);
    const list   = voteListFor(FIX, '/frueher', '!');
    const before = list.map(i => i.key);
    const sorted = sortByScore(list, vd);
    assert('highest first',    sorted.map(i => i.key), ['/frueher/b', '/frueher/a']);
    assert('does not mutate',  list.map(i => i.key), before);
    assert('tie → newest first', sortByScore(list, {}).map(i => i.key), ['/frueher/b', '/frueher/a']);
}
testScoreAndSort();

function testNormaliseSourceMd() {
    suite('normaliseSourceMd');
    assert('bare url → [host](url)',  normaliseSourceMd('https://www.example.org/a'), '[example.org](https://www.example.org/a)');
    assert('markdown passes through', normaliseSourceMd('[DGE](https://www.dge.de/b21)'), '[DGE](https://www.dge.de/b21)');
    assert('two links pass through',  normaliseSourceMd('[A](https://a.org) [B](https://b.org)'), '[A](https://a.org) [B](https://b.org)');
    assert('empty → ""',              normaliseSourceMd(''), '');
    assert('undefined → ""',          normaliseSourceMd(undefined), '');
    assert('plain text stays text',   normaliseSourceMd('Studie 2021'), 'Studie 2021');
}
testNormaliseSourceMd();

function testRenderSourceHtml() {
    suite('renderSourceHtml');
    const two = renderSourceHtml('[A](https://a.org) [B](https://b.org)');
    assertMatch('first link opens new tab', two, /<a target="_blank" rel="noopener" href="https:\/\/a\.org">A<\/a>/);
    assertMatch('second link',              two, /href="https:\/\/b\.org">B<\/a>/);
    assert('no <p> wrapper',                two.startsWith('<p>'), false);
    const evil = renderSourceHtml(normaliseSourceMd('javascript:alert(1)'));
    assertMatch('javascript: neutralised',  evil, /href="#"/);
    assert('no javascript href',            evil.includes('href="javascript'), false);
    assert('empty → ""',                    renderSourceHtml(''), '');
}
testRenderSourceHtml();

function testModeHelpers() {
    suite('modeFromParam / paramFromMode');
    assert('fakt → !',            modeFromParam('fakt'),       '!');
    assert('gegenfrage → ??',     modeFromParam('gegenfrage'), '??');
    assert('case-insensitive',    modeFromParam('Fakt'),       '!');
    assert('unknown → ""',        modeFromParam('meinung'),    '');
    assert('null → ""',           modeFromParam(null),         '');
    assert('! → fakt',            paramFromMode('!'),          'fakt');
    assert('?? → gegenfrage',     paramFromMode('??'),         'gegenfrage');
    assert('unknown suffix → ""', paramFromMode('.'),          '');
}
testModeHelpers();

function testPageTitle() {
    suite('pageTitle');
    assert('configured title wins',   pageTitle('Früher war alles besser !?', '/frueher'), 'Früher war alles besser !?');
    assert('fallback from topic',     pageTitle('', '/frueher'),        'Abstimmen · frueher');
    assert('fallback trims',          pageTitle('   ', '/frueher'),     'Abstimmen · frueher');
    assert('undefined → fallback',    pageTitle(undefined, '/frueher'), 'Abstimmen · frueher');
    assert('root topic → Alles',      pageTitle('', '/'),               'Abstimmen · Alles');
}
testPageTitle();

function testStripTypeSuffix() {
    suite('stripTypeSuffix');
    assert('! stripped',          stripTypeSuffix('Fakt A!'),      'Fakt A');
    assert('?? stripped',         stripTypeSuffix('Warum??'),      'Warum');
    assert('only the suffix',     stripTypeSuffix('Ist das so?!'), 'Ist das so?');
    assert('!- stripped',         stripTypeSuffix('Falsch!-'),     'Falsch');
    assert('no suffix untouched', stripTypeSuffix('Ohne Ende'),    'Ohne Ende');
    assert('empty',               stripTypeSuffix(''),             '');
}
testStripTypeSuffix();

function testParseSourceMd() {
    suite('parseSourceMd');
    assert('markdown link',      parseSourceMd('[DGE](https://www.dge.de/b21)'), { url: 'https://www.dge.de/b21', name: 'DGE' });
    assert('bare url',           parseSourceMd('https://www.dge.de/b21'),        { url: 'https://www.dge.de/b21', name: '' });
    assert('two links verbatim', parseSourceMd('[A](https://a.org) [B](https://b.org)'), { url: '[A](https://a.org) [B](https://b.org)', name: '' });
    assert('name trimmed',       parseSourceMd('[ DGE ](https://x.org)'),        { url: 'https://x.org', name: 'DGE' });
    assert('empty',              parseSourceMd(''),                              { url: '', name: '' });
    assert('undefined',          parseSourceMd(undefined),                       { url: '', name: '' });
}
testParseSourceMd();

function testBuildSourceMd() {
    suite('buildSourceMd');
    assert('url + name',                 buildSourceMd('https://x.org/a', 'X'),  '[X](https://x.org/a)');
    assert('url only',                   buildSourceMd('https://x.org/a', ''),   'https://x.org/a');
    assert('empty url → ""',             buildSourceMd('', 'X'),                 '');
    assert('| encoded in url',           buildSourceMd('https://x.org/a|b', ''), 'https://x.org/a%7Cb');
    assert('brackets dropped from name', buildSourceMd('https://x.org', 'A[1]'), '[A1](https://x.org)');
    assert('markdown passes verbatim',   buildSourceMd('[A](https://a.org) [B](https://b.org)', 'ignored'), '[A](https://a.org) [B](https://b.org)');
    const p = parseSourceMd('[DGE](https://www.dge.de/b21)');
    assert('round-trip',                 buildSourceMd(p.url, p.name), '[DGE](https://www.dge.de/b21)');
}
testBuildSourceMd();

function testValidateSheetInput() {
    suite('validateSheetInput');
    assert('ok',                    validateSheetInput('Drei', 'https://x.org', 'X'), '');
    assert('ok without source',     validateSheetInput('Drei', '', ''), '');
    assert('too short',             validateSheetInput('ab', '', ''), 'Zu kurz (mind. 3 Zeichen).');
    assert('whitespace only',       validateSheetInput('   ', '', ''), 'Zu kurz (mind. 3 Zeichen).');
    assert('| in text',             validateSheetInput('a | b', '', ''), 'Das Zeichen | ist nicht erlaubt.');
    assert('| in name',             validateSheetInput('Drei', 'https://x.org', 'a|b'), 'Das Zeichen | ist nicht erlaubt.');
    assert('url without scheme',    validateSheetInput('Drei', 'dge.de', ''), 'Quelle: bitte eine vollständige Adresse eingeben (https://…).');
    assert('markdown src accepted', validateSheetInput('Drei', '[A](https://a.org) [B](https://b.org)', ''), '');
    assert('| in markdown url refused', validateSheetInput('Drei', '[A](https://a.org/x|y)', ''), 'Das Zeichen | ist nicht erlaubt.');
}
testValidateSheetInput();

function testBuildEntryLine() {
    suite('buildEntryLine');
    assert('add with src',       buildEntryLine('/frueher/n1', 'Neu', '!', {}, 'https://x.org', 'X'), '/frueher/n1 | src:[X](https://x.org) | Neu!');
    assert('add without src',    buildEntryLine('/frueher/n1', 'Neu', '!', {}, '', ''),               '/frueher/n1 | Neu!');
    assert('gegenfrage suffix',  buildEntryLine('/frueher/n1', 'Warum', '??', {}, '', ''),            '/frueher/n1 | Warum??');
    assert('other attrs kept, src rebuilt',
           buildEntryLine('/frueher/a', 'Fakt A', '!', { author: 'mh', src: 'old' }, 'https://new.org', ''),
           '/frueher/a | author:mh | src:https://new.org | Fakt A!');
    assert('src dropped when url emptied', buildEntryLine('/frueher/a', 'Fakt A', '!', { src: 'https://old.org' }, '', ''), '/frueher/a | Fakt A!');
    assert('text trimmed',       buildEntryLine('/k', '  Neu  ', '!', {}, '', ''), '/k | Neu!');
    assert('typed suffix kept once', buildEntryLine('/k', 'Neu!', '!', {}, '', ''), '/k | Neu!');
}
testBuildEntryLine();

function testRender() {
    suite('render (DOM)');
    entries = FIX; topicRoot = '/frueher'; activeTab = '!';
    votesData = { '/frueher/a': { votes: 1, signed: 0 }, '/frueher/b': { votes: 5, signed: 2 } };
    render();
    const cards = [...document.querySelectorAll('#vote-list .card')];
    assert('two argument cards',    cards.length, 2);
    assert('sorted by score',       cards.map(c => c.dataset.fullKey), ['/frueher/b', '/frueher/a']);
    assert('fakt badge',            cards[1].querySelector('.badge').className, 'badge badge-fakt');
    assert('bewiesen at 2 signs',   cards[0].querySelector('.badge').textContent, 'Bewiesen ✓');
    assert('source link shown',     !!cards[1].querySelector('.card-source a'), true);
    assert('source label = host',   cards[1].querySelector('.card-source a').textContent, 'dge.de');
    assert('no source when absent', !!cards[0].querySelector('.card-source'), false);
    assert('score text',            cards[0].querySelector('.vote-score').textContent, '+5');
    assert('suffix stripped',       cards[1].querySelector('.card-text').textContent, 'Fakt A');

    activeTab = '??'; render();
    const q = [...document.querySelectorAll('#vote-list .card')];
    assert('one gegenfrage card',   q.length, 1);
    assert('gegenfrage badge',      q[0].querySelector('.badge').className, 'badge badge-gegenfrage');
    assert('gegenfrage label',      q[0].querySelector('.badge').textContent, 'Gegenfrage');

    entries = {}; render();
    assert('empty state',           !!document.getElementById('vote-empty'), true);
}
testRender();

function testOptimisticVote() {
    suite('handleVote / handleSign (optimistic, POST stubbed)');
    const realPost = postVoteAttr;
    postVoteAttr = async () => true;                       // no network during tests
    try {
        entries = FIX; topicRoot = '/frueher'; activeTab = '!'; votesData = {};
        render();
        for (const k of Object.keys(_debounceMap)) delete _debounceMap[k];
        handleVote('/frueher/a', 1);
        assert('score +1 in state', scoreOf(votesData, '/frueher/a'), 1);
        assert('score +1 in DOM',   document.querySelector('.card[data-full-key="/frueher/a"] .vote-score').textContent, '+1');
        handleVote('/frueher/a', 1);
        assert('debounced repeat',  scoreOf(votesData, '/frueher/a'), 1);
        handleVote('/frueher/b', -1);
        assert('downvote',          scoreOf(votesData, '/frueher/b'), -1);
        assert('neg class',         document.querySelector('.card[data-full-key="/frueher/b"] .vote-score').className, 'vote-score neg');
        handleSign('/frueher/b');
        assert('sign +1',           signedOf(votesData, '/frueher/b'), 1);
        assert('sign count in DOM', document.querySelector('.card[data-full-key="/frueher/b"] .sign-count').textContent, '✓ 1');
        delete _debounceMap['sign_/frueher/b'];
        handleSign('/frueher/b');
        assert('sign +2',           signedOf(votesData, '/frueher/b'), 2);
        assert('badge flips to Bewiesen in place', document.querySelector('.card[data-full-key="/frueher/b"] .badge').textContent, 'Bewiesen ✓');
        assert('sign count verified class',        document.querySelector('.card[data-full-key="/frueher/b"] .sign-count').className, 'sign-count verified');
    } finally {
        postVoteAttr = realPost;
    }
}
testOptimisticVote();

async function testRollbackOnFailure() {
    suite('rollback when POST fails');
    const realPost = postVoteAttr;
    postVoteAttr = async () => false;                      // simulate 429 / network error
    try {
        entries = FIX; topicRoot = '/frueher'; activeTab = '!'; votesData = {};
        render();
        for (const k of Object.keys(_debounceMap)) delete _debounceMap[k];
        handleVote('/frueher/a', 1);
        assert('optimistic +1 first',  scoreOf(votesData, '/frueher/a'), 1);
        await new Promise(r => setTimeout(r, 0));          // let the failed save settle
        assert('score rolled back in state', scoreOf(votesData, '/frueher/a'), 0);
        assert('score rolled back in DOM',   document.querySelector('.card[data-full-key="/frueher/a"] .vote-score').textContent, '0');
        handleSign('/frueher/b');
        assert('optimistic sign first', signedOf(votesData, '/frueher/b'), 1);
        await new Promise(r => setTimeout(r, 0));
        assert('sign rolled back',      signedOf(votesData, '/frueher/b'), 0);
        assert('sign DOM rolled back',  document.querySelector('.card[data-full-key="/frueher/b"] .sign-count').textContent, '');
    } finally {
        postVoteAttr = realPost;
    }
}

function testSheetOpen() {
    suite('sheet — FAB opens add mode, pencil opens edit mode (AVC6.1 / AVC7.1)');
    entries = JSON.parse(JSON.stringify(FIX)); topicRoot = '/frueher'; activeTab = '!'; votesData = {};
    render();
    try {
        document.getElementById('fab').click();
        assert('backdrop open',              isSheetOpen(), true);
        assert('heading add',                document.getElementById('sheet-heading').textContent, 'Neuer Eintrag');
        assert('badge label from TYPE_DEFS', document.getElementById('sheet-badge').textContent, TYPE_DEFS['!'].label);
        assert('badge class',                document.getElementById('sheet-badge').className, 'badge badge-fakt');
        assert('text empty',                 document.getElementById('sheet-text').value, '');
        assert('url empty',                  document.getElementById('sheet-src-url').value, '');
        assert('name empty',                 document.getElementById('sheet-src-name').value, '');
        assert('submit label add',           document.getElementById('sheet-submit').textContent, 'Hinzufügen');
        closeSheet();
        assert('closed',                     isSheetOpen(), false);

        const pencil = document.querySelector('.card[data-full-key="/frueher/a"] .edit-btn');
        assert('pencil ≥ 44px (CG-DS5)',     pencil.offsetHeight >= 44 && pencil.offsetWidth >= 44, true);
        pencil.click();
        assert('heading edit',               document.getElementById('sheet-heading').textContent, 'Eintrag bearbeiten');
        assert('text pre-filled, no suffix', document.getElementById('sheet-text').value, 'Fakt A');
        assert('url pre-filled',             document.getElementById('sheet-src-url').value, 'https://www.dge.de/b21');
        assert('name empty for bare url',    document.getElementById('sheet-src-name').value, '');
        assert('submit label edit',          document.getElementById('sheet-submit').textContent, 'Speichern');
        closeSheet();

        entries['/frueher/a'].attrs.src = '[DGE](https://www.dge.de/b21)';
        openSheet('/frueher/a');
        assert('name from markdown',         document.getElementById('sheet-src-name').value, 'DGE');
        assert('url from markdown',          document.getElementById('sheet-src-url').value, 'https://www.dge.de/b21');
        openSheet('/frueher/does-not-exist');
        assert('unknown key ignored',        document.getElementById('sheet-heading').textContent, 'Eintrag bearbeiten');
    } finally { closeSheet(); }
}
testSheetOpen();

async function testSheetSubmit() {
    suite('sheet — submit (postEntryLine stubbed)');
    const realPost = postEntryLine;
    let sent = [];
    postEntryLine = async line => { sent.push(line); return { ok: true, timestamp: '2026-09-26 12:00:00' }; };
    try {
        entries = JSON.parse(JSON.stringify(FIX)); topicRoot = '/frueher'; activeTab = '!'; votesData = {};
        render();
        const before = Object.keys(entries).length;

        openSheet();                                                   // AVC6.3 — too short
        document.getElementById('sheet-text').value = 'ab';
        assert('short → false',         await submitSheet(), false);
        assert('short → sheet open',    isSheetOpen(), true);
        assert('short → nothing sent',  sent.length, 0);
        assert('short → no entry',      Object.keys(entries).length, before);

        document.getElementById('sheet-text').value     = 'Neuer Fakt';   // AVC6.2 / AVC6.4
        document.getElementById('sheet-src-url').value  = 'https://www.dge.de/x';
        document.getElementById('sheet-src-name').value = 'DGE';
        assert('add → true',            await submitSheet(), true);
        assert('add → sheet closed',    isSheetOpen(), false);
        assert('add → one entry more',  Object.keys(entries).length, before + 1);
        const newKey = Object.keys(entries).find(k => entries[k].message === 'Neuer Fakt!');
        assert('add → key under topic', !!newKey && newKey.startsWith('/frueher/'), true);
        assert('add → src stored',      entries[newKey].attrs.src, '[DGE](https://www.dge.de/x)');
        assert('add → server timestamp', entries[newKey].timestamp, '2026-09-26 12:00:00');
        assert('add → line sent',       sent[0], `${newKey} | src:[DGE](https://www.dge.de/x) | Neuer Fakt!`);
        assert('add → card rendered',   !!document.querySelector(`.card[data-full-key="${CSS.escape(newKey)}"]`), true);
        assert('add → card source',     document.querySelector(`.card[data-full-key="${CSS.escape(newKey)}"] .card-source a`).textContent, 'DGE');

        sent = [];                                                     // AVC7.3 / AVC7.4 — edit round-trip
        entries['/frueher/a'].attrs = { author: 'mh', src: 'https://www.dge.de/b21' };
        render();
        openSheet('/frueher/a');
        document.getElementById('sheet-text').value     = 'Fakt A neu';
        document.getElementById('sheet-src-name').value = 'DGE';
        assert('edit → true',           await submitSheet(), true);
        assert('edit → line keeps author, rebuilds src', sent[0], '/frueher/a | author:mh | src:[DGE](https://www.dge.de/b21) | Fakt A neu!');
        assert('edit → message',        entries['/frueher/a'].message, 'Fakt A neu!');
        assert('edit → attrs',          entries['/frueher/a'].attrs, { author: 'mh', src: '[DGE](https://www.dge.de/b21)' });
        assert('edit → no new key',     Object.keys(entries).length, before + 1);
        assert('edit → card text',      document.querySelector('.card[data-full-key="/frueher/a"] .card-text').textContent, 'Fakt A neu');

        postEntryLine = async () => ({ ok: false });                    // AVC6.5 / AVC7.5 — failure
        openSheet('/frueher/a');
        document.getElementById('sheet-text').value = 'Verworfen';
        assert('fail → false',          await submitSheet(), false);
        assert('fail → sheet open',     isSheetOpen(), true);
        assert('fail → text kept',      document.getElementById('sheet-text').value, 'Verworfen');
        assert('fail → entry unchanged', entries['/frueher/a'].message, 'Fakt A neu!');
        closeSheet();

        postEntryLine = async line => { sent.push(line); return { ok: true, timestamp: '' }; };   // AVC8.x — mode
        applyMode('??');
        assert('mode → tab active',     document.querySelector('#tab-bar .chip.active').dataset.tab, '??');
        assert('mode → url param',      new URLSearchParams(location.search).get('type'), 'gegenfrage');
        assert('mode → only ?? cards',  [...document.querySelectorAll('#vote-list .card')].map(c => c.dataset.fullKey), ['/frueher/q1']);
        openSheet();
        assert('mode → badge',          document.getElementById('sheet-badge').textContent, TYPE_DEFS['??'].label);
        document.getElementById('sheet-text').value = 'Neue Frage';
        assert('mode → submit ok',      await submitSheet(), true);
        assert('mode → ?? entry',       Object.values(entries).some(e => e.message === 'Neue Frage??'), true);
        assert('mode → listed',         document.querySelectorAll('#vote-list .card').length, 2);
    } finally {
        postEntryLine = realPost;
        closeSheet();
        applyMode('!');
    }
}

// ── Done ─────────────────────────────────────────────────────────────────────
testSheetSubmit().then(testRollbackOnFailure).then(harnessFinish);
