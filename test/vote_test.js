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

// ── Done ─────────────────────────────────────────────────────────────────────
testRollbackOnFailure().then(harnessFinish);
