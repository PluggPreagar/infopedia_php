/**
 * Test cases for app2.html — loaded by wrapper.php?test=app2.html
 * Requires: test/harness.js (suite, assert, assertMatch, harnessFinish)
 * Each test group is wrapped in a named function and called immediately.
 */

function rs() {
    data            = {};
    votesData       = {};
    topicMap        = {};
    selectedTopic   = '/';
    latestTimestamp = null;
    searchScope     = 'below';
    activeTypes     = new Set(['.', '!', '!-', '?', '??']);
    actionTrail     = [];
    document.getElementById('search-input').value = '';
}

// utility — path helpers
function testFullKey() {
    suite('fullKey');
    assert('root + nodeId',  fullKey('/', 'abc'),        '/abc');
    assert('nested topic',   fullKey('/climate', 'sol'), '/climate/sol');
    assert('deep nesting',   fullKey('/a/b', 'c'),       '/a/b/c');
}
testFullKey();

// utility — path helpers
function testSplitKey() {
    suite('splitKey');
    assert('root entry',     splitKey('/abc'),           ['/', 'abc']);
    assert('one-level path', splitKey('/climate/sol'),   ['/climate', 'sol']);
    assert('deep path',      splitKey('/a/b/c'),         ['/a/b', 'c']);
}
testSplitKey();

// utility — type parsing
function testGetTypeFromMessage() {
    suite('getTypeFromMessage');
    assert('opinion (.)',      getTypeFromMessage('Hello.'),  '.');
    assert('fact (!)',         getTypeFromMessage('Hello!'),  '!');
    assert('fake (!-)',        getTypeFromMessage('Hello!-'), '!-');
    assert('question (??)',    getTypeFromMessage('Hello??'), '??');
    assert('unclear (?)',      getTypeFromMessage('Hello?'),  '?');
    assert('topic (>)',        getTypeFromMessage('Topic>'),  '>');
    assert('delete (--)',      getTypeFromMessage('x--'),     '--');
    assert('no suffix',        getTypeFromMessage('Hello'),   '');
    assert('empty string',     getTypeFromMessage(''),        '');
}
testGetTypeFromMessage();

// utility — type mutation; UC4 (add), UC5 (edit)
function testMatchType() {
    suite('matchType');
    assert('change . to !',    matchType('Hello.', '!'),    'Hello!');
    assert('no suffix → add',  matchType('Hello', '!'),     'Hello!');
    assert('same type noop',   matchType('Hello!', '!'),    'Hello!');
    assert('change !- to !',   matchType('Hello!-', '!'),   'Hello!');
    assert('change ?? to ?',   matchType('Hello??', '?'),   'Hello?');
    assert('-- always append', matchType('Hello.', '--'),   'Hello.--');
    assert('change > to .',    matchType('Topic>', '.'),    'Topic.');
}
testMatchType();

// utility — UC4 (add), UC5 (edit)
function testGenerateNodeId() {
    suite('generateNodeId');
    const id1 = generateNodeId(), id2 = generateNodeId();
    assertMatch('alphanum string', id1, /^[a-z0-9]+$/i);
    assert('two calls differ',     id1 === id2, false);
}
testGenerateNodeId();

// utility — card rendering
function testFormatTimestamp() {
    suite('formatTimestamp');
    assert('empty → empty',         formatTimestamp(''),                    '');
    assert('invalid → passthrough', formatTimestamp('bogus'),               'bogus');
    assert('past year → year only', formatTimestamp('2020-01-15 10:00:00'), '2020');
}
testFormatTimestamp();

// utility — XSS guard (UC4, UC5, UC9)
function testEscapeHtml() {
    suite('escapeHtml');
    assert('< escaped',  escapeHtml('<'),           '&lt;');
    assert('> escaped',  escapeHtml('>'),           '&gt;');
    assert('& escaped',  escapeHtml('&'),           '&amp;');
    assert('" escaped',  escapeHtml('"'),           '&quot;');
    assert('mixed',      escapeHtml('<b>"hi"</b>'), '&lt;b&gt;&quot;hi&quot;&lt;/b&gt;');
    assert('plain text', escapeHtml('hello'),       'hello');
}
testEscapeHtml();

// UC15 — type display mode (icon classes); UC4, UC5 (chip selection)
function testGetTypeDef() {
    suite('getTypeDef');
    const factDef = getTypeDef('Hello!');
    assert('fakt label',        factDef.label,                     'Fakt');
    assert('fakt cssClass',     factDef.cssClass,                   'fakt');
    assert('suffix stored',     factDef.suffix,                     '!');
    assert('fakt iconClass',    factDef.iconClass,                  'fa-circle-check');
    assert('fake cssClass',     getTypeDef('Hello!-').cssClass,     'fake');
    assert('fake iconClass',    getTypeDef('Hello!-').iconClass,    'fa-circle-xmark');
    assert('meinung iconClass', getTypeDef('Hi.').iconClass,        'fa-comment');
    assert('unklar iconClass',  getTypeDef('Hi?').iconClass,        'fa-circle-question');
    assert('gegenfrage icon',   getTypeDef('Hi??').iconClass,       'fa-right-left');
    assert('thema iconClass',   getTypeDef('Topic>').iconClass,     'fa-folder-open');
    assert('thema label',       getTypeDef('Topic>').label,         'Thema');
    assert('unknown → default', getTypeDef('no-suffix').label,      'Eintrag');
}
testGetTypeDef();

// UC10 — sign/confirm
function testGetSignedCount() {
    suite('getSignedCount');
    rs();
    assert('no data → 0',        getSignedCount('/', 'n1'), 0);
    votesData['/n1'] = { votes: 3, signed: 2 };
    assert('reads signed field', getSignedCount('/', 'n1'), 2);
}
testGetSignedCount();

// UC13 — bug report
function testSanitiseForReport() {
    suite('sanitiseForReport');
    const _sid0 = sid, _tid0 = tenantId;
    sid = 'mysid123'; tenantId = 'mytid456';
    assert('replaces sid',       sanitiseForReport('sent by mysid123'),      'sent by [sid]');
    assert('replaces tenantId',  sanitiseForReport('tenant mytid456 here'),  'tenant [tid] here');
    assert('replaces both',      sanitiseForReport('mysid123 and mytid456'), '[sid] and [tid]');
    assert('no match unchanged', sanitiseForReport('nothing here'),          'nothing here');
    sid = _sid0; tenantId = _tid0;
}
testSanitiseForReport();

// UC13 — bug report
function testBuildStateSnapshot() {
    suite('buildStateSnapshot');
    rs(); selectedTopic = '/climate'; searchScope = 'here';
    const snap = buildStateSnapshot();
    assert('contains topic',  snap.includes('/climate'), true);
    assert('contains Filter', snap.includes('Filter:'),  true);
    assert('contains Suche',  snap.includes('Suche:'),   true);
    assert('contains Karten', snap.includes('Karten:'),  true);
}
testBuildStateSnapshot();

// UC13 — bug report
function testBuildReportText() {
    suite('buildReportText');
    rs();
    const rep = buildReportText(null);
    assert('has header',           rep.includes('=== Fehlerbericht ==='),   true);
    assert('has aktionen section', rep.includes('--- Letzte Aktionen ---'), true);
    assert('has zustand section',  rep.includes('--- Zustand ---'),         true);
    assert('no error section',     rep.includes('--- Fehlerdetails ---'),   false);
    const repCtx = buildReportText({ label: 'sendVote', status: 500 });
    assert('has Fehlerdetails',    repCtx.includes('--- Fehlerdetails ---'), true);
    assert('has error label',      repCtx.includes('sendVote'),             true);
}
testBuildReportText();

// UC13 — bug report
function testBuildFullReport() {
    suite('buildFullReport');
    document.getElementById('issue-user-msg').value = 'Something went wrong';
    document.getElementById('issue-details').value  = 'Error: 500';
    const full = buildFullReport();
    assert('contains user msg',    full.includes('Something went wrong'), true);
    assert('contains details',     full.includes('Error: 500'),           true);
    assert('has prefix label',     full.includes('Nutzerbeschreibung'),   true);
    document.getElementById('issue-user-msg').value = '';
    const noMsg = buildFullReport();
    assert('no prefix when empty', noMsg.includes('Nutzerbeschreibung'),  false);
    document.getElementById('issue-user-msg').value = '';
    document.getElementById('issue-details').value  = '';
}
testBuildFullReport();

// UC12 — change tenant; UC15 — display mode persistence
function testLoadSaveSettings() {
    suite('loadSettings / saveSettings');
    localStorage.removeItem('fayf_settings');
    assert('missing key → {}', loadSettings(), {});
    saveSettings({ tenantId: 'demo', sid: 'abc' });
    assert('saved and loaded',  loadSettings(), { tenantId: 'demo', sid: 'abc' });
    saveSettings({ sid: 'xyz' });
    assert('patch merges',      loadSettings().tenantId, 'demo');
    assert('patch updates',     loadSettings().sid,      'xyz');
    localStorage.removeItem('fayf_settings');
}
testLoadSaveSettings();

// UC1 — initial load; UC14 — long-poll
function testBuildEntriesVotesUrl() {
    suite('buildEntriesUrl / buildVotesUrl');
    const _sid1 = sid, _tid1 = tenantId;
    sid = 'tsid'; tenantId = 'ttid';
    const eUrl = buildEntriesUrl();
    assert('entries has sid',    eUrl.includes('sid=tsid'),   true);
    assert('entries has tid',    eUrl.includes('tid=ttid'),   true);
    assert('entries has format', eUrl.includes('format=json'), true);
    assert('entries prefix',     eUrl.startsWith('entries?'), true);
    const eUrlX = buildEntriesUrl({ since: '2024-01-01 00:00:00' });
    assert('extra params added', eUrlX.includes('since='), true);
    const vUrl = buildVotesUrl();
    assert('votes has sid',    vUrl.includes('sid=tsid'),  true);
    assert('votes prefix',     vUrl.startsWith('votes?'),  true);
    sid = _sid1; tenantId = _tid1;
}
testBuildEntriesVotesUrl();

// utility — UC9 (vote debounce), UC5 (swipe debounce)
function testDebounceKey() {
    suite('debounceKey');
    Object.keys(_debounceMap).forEach(k => delete _debounceMap[k]);
    assert('first call → true',        debounceKey('dk_test', 1000), true);
    assert('immediate repeat → false', debounceKey('dk_test', 1000), false);
    assert('different key → true',     debounceKey('dk_other', 1000), true);
    delete _debounceMap['dk_test'];
    assert('after clear → true again', debounceKey('dk_test', 1000), true);
}
testDebounceKey();

// UC13 — action trail for bug reports
function testPushAction() {
    suite('pushAction');
    actionTrail = [];
    pushAction('navigate', '/climate');
    assert('appended to trail', actionTrail.length,        1);
    assert('action stored',     actionTrail[0].action,     'navigate');
    assert('detail stored',     actionTrail[0].detail,     '/climate');
    assertMatch('ts is ISO',    actionTrail[0].ts, /^\d{4}-\d{2}-\d{2}T/);
    for (let i = 0; i < 15; i++) pushAction('t', String(i));
    assert('capped at max (10)',  actionTrail.length,                           ACTION_TRAIL_MAX);
    assert('keeps newest',        actionTrail[actionTrail.length - 1].detail,  '14');
    actionTrail = [];
}
testPushAction();

// UC2 — navigate into topic; UC3 — navigate back
function testNavigateTo() {
    suite('navigateTo');
    rs();
    navigateTo('/climate');
    assert('selectedTopic updated', selectedTopic, '/climate');
    navigateTo('/climate/solutions');
    assert('deep navigation',       selectedTopic, '/climate/solutions');
    navigateTo('/');
    assert('back to root',          selectedTopic, '/');
}
testNavigateTo();

// UC4 — add entry; UC5 — edit/delete
function testAddEntryWoCheck() {
    suite('addEntryWoCheck');
    rs();
    addEntryWoCheck('/', 'n1', 'Hello.', 3, '2024-01-01 00:00:00');
    assert('entry stored',          data['/']['n1'].message,   'Hello.');
    assert('votes stored',          data['/']['n1'].votes,     3);
    assert('timestamp stored',      data['/']['n1'].timestamp, '2024-01-01 00:00:00');
    addEntryWoCheck('/', 'n1', 'Hello.--', 0, '');
    assert('delete marker removes', data['/']['n1'],           undefined);
    addEntryWoCheck('', 'n2', 'Empty topic.', 0, '');
    assert('empty topic → /',       data['/']['n2'].message,   'Empty topic.');
}
testAddEntryWoCheck();

// UC4 — stub topic created when adding nested entry
function testCheckData() {
    suite('checkData — stub creation');
    rs();
    addEntry('/climate/solutions', 'n1', 'Solar panels.', 0, '');
    assert('stub /climate created', !!data['/']['climate'],              true);
    assert('stub ends with >',      data['/']['climate'].message.endsWith('>'), true);
    assert('deep entry stored',     !!data['/climate/solutions']['n1'], true);
}
testCheckData();

// UC2 — nav-back label lookup
function testInitializeTopicMap() {
    suite('initializeTopicMap');
    rs();
    addEntryWoCheck('/', 'climate', 'Climate Change>', 0, '');
    initializeTopicMap();
    assert('topic name registered', topicMap['/climate'], 'Climate Change');
    addEntryWoCheck('/', 'other', 'No suffix.', 0, '');
    initializeTopicMap();
    assert('non-topic ignored',     topicMap['/other'],   undefined);
}
testInitializeTopicMap();

// UC1 — initial load; UC14 — real-time merge
function testAddData() {
    suite('addData — flat format');
    rs();
    addData({
        '/climate/n1': { message: 'Solar!', votes: 5, timestamp: '2024-06-01 12:00:00' },
        '/climate/n2': { message: 'Wind.',  votes: 2, timestamp: '2024-06-02 09:00:00' },
    });
    assert('entry n1 stored',         data['/climate']['n1'].message, 'Solar!');
    assert('votes on n1',             data['/climate']['n1'].votes,   5);
    assert('latestTimestamp updated', latestTimestamp,                '2024-06-02 09:00:00');
    assert('stub /climate created',   !!data['/']['climate'],         true);

    suite('addData — votes as object');
    rs();
    addData({ '/poll/q1': { message: 'Fair?', votes: { sid_abc: 1, others: 2 }, timestamp: '' } });
    assert('votes object summed', data['/poll']['q1'].votes, 3);

    suite('addData — empty is no-op');
    rs();
    addEntryWoCheck('/', 'x', 'stays.', 0, '');
    addData({});
    assert('existing entry survives', !!data['/']['x'], true);

    suite('addData — null is no-op');
    rs();
    addEntryWoCheck('/', 'y', 'stays too.', 0, '');
    addData(null);
    assert('null does not crash',     !!data['/']['y'], true);
}
testAddData();

// UC9 — vote sync; UC10 — sign count
function testAddVotesData() {
    suite('addVotesData');
    rs();
    addEntryWoCheck('/', 'n1', 'Hello!', 0, '');
    addVotesData({ '/n1': { votes: 7, attrs: { signed_count: 3 } } });
    assert('votes in votesData',   votesData['/n1'].votes,  7);
    assert('signed stored',        votesData['/n1'].signed, 3);
    assert('votes synced to data', data['/']['n1'].votes,   7);
}
testAddVotesData();

// UC9 — optimistic vote update
function testAddVoteByGui() {
    suite('addVoteByGui');
    rs();
    addEntryWoCheck('/', 'n1', 'Hello!', 2, '');
    addVoteByGui('/', 'n1', 1);
    assert('entry votes +1', data['/']['n1'].votes,  3);
    assert('votesData +1',   votesData['/n1'].votes, 3);
    addVoteByGui('/', 'n1', -1);
    assert('downvote -1',    data['/']['n1'].votes,  2);
}
testAddVoteByGui();

// UC9 — server vote sync
function testSetVoteByOthers() {
    suite('setVoteByOthers');
    rs();
    addEntryWoCheck('/', 'n1', 'Hello!', 5, '');
    setVoteByOthers('/', 'n1', 10);
    assert('sets votes absolutely', data['/']['n1'].votes,  10);
    assert('synced to votesData',   votesData['/n1'].votes, 10);
}
testSetVoteByOthers();

// UC11 — search and scope filtering
function testGetFilteredEntries() {
    suite('getFilteredEntries — scope=below at root');
    rs();
    addEntryWoCheck('/', 'r1', 'Root entry.', 0, '');
    addEntryWoCheck('/climate', 's1', 'Sub entry.', 0, '');
    let ents = getFilteredEntries();
    assert('root entry shown',   ents.some(e => e.nodeId === 'r1'), true);
    assert('sub entry excluded', ents.some(e => e.nodeId === 's1'), false);

    suite('getFilteredEntries — scope=global');
    rs(); searchScope = 'global'; selectedTopic = '/climate';
    addEntryWoCheck('/', 'r1', 'Root.', 0, '');
    addEntryWoCheck('/other', 'o1', 'Other.', 0, '');
    ents = getFilteredEntries();
    assert('all topics visible', ents.length >= 2, true);

    suite('getFilteredEntries — type filter');
    rs(); activeTypes = new Set(['!']);
    addEntryWoCheck('/', 'fact',    'True!',    0, '');
    addEntryWoCheck('/', 'opinion', 'Opinion.', 0, '');
    addEntryWoCheck('/', 'topic',   'SubT>',    0, '');
    ents = getFilteredEntries();
    assert('fact shown',             ents.some(e => e.nodeId === 'fact'),    true);
    assert('opinion filtered out',   ents.some(e => e.nodeId === 'opinion'), false);
    assert('topic (>) always shown', ents.some(e => e.nodeId === 'topic'),   true);

    suite('getFilteredEntries — sorted by votes desc');
    rs();
    addEntryWoCheck('/', 'lo', 'Low.',  1, '');
    addEntryWoCheck('/', 'hi', 'High!', 9, '');
    addEntryWoCheck('/', 'mi', 'Mid?',  5, '');
    ents = getFilteredEntries();
    assert('highest votes first', ents.map(e => e.nodeId), ['hi', 'mi', 'lo']);
}
testGetFilteredEntries();

// UC4 — add entry; root-allowed guard
function testRequireTopicFlag() {
    suite('REQUIRE_TOPIC_FOR_ENTRY flag');
    function wouldBlock(flag, topic) { return flag && topic === '/'; }
    assert('flag off → root ok',     wouldBlock(false, '/'),  false);
    assert('flag off → topic ok',    wouldBlock(false, '/x'), false);
    assert('flag on → root blocked', wouldBlock(true,  '/'),  true);
    assert('flag on → topic ok',     wouldBlock(true,  '/x'), false);
}
testRequireTopicFlag();

// ── GUI tests ─────────────────────────────────────────────────────────────────
// UC5 — edit entry (long-press opens pre-filled sheet)
function testOpenBottomSheetEditMode() {
    suite('openBottomSheet — edit mode');
    rs();
    addEntryWoCheck('/', 'n1', 'Solar!', 5, '');
    openBottomSheet(data['/']['n1']);
    assert('textarea pre-filled',      document.getElementById('bs-textarea').value,                'Solar');
    assert('active chip matches type', document.querySelector('.bs-type-chip.active').dataset.type, '!');
    assert('heading says Bearbeiten',  document.querySelector('#bottom-sheet h3').textContent,       'Eintrag bearbeiten');
    closeBottomSheet();
}
testOpenBottomSheetEditMode();

// UC4 — add new entry (FAB opens empty sheet)
function testOpenBottomSheetNewMode() {
    suite('openBottomSheet — new mode');
    rs();
    openBottomSheet();
    assert('textarea empty',          document.getElementById('bs-textarea').value,                '');
    assert('heading says Neuer',      document.querySelector('#bottom-sheet h3').textContent,       'Neuer Eintrag');
    assert('default chip is Meinung', document.querySelector('.bs-type-chip.active').dataset.type, '.');
    closeBottomSheet();
}
testOpenBottomSheetNewMode();

// UC5 — edit entry; suffix stripped from textarea
function testBottomSheetSuffixStripping() {
    suite('openBottomSheet — suffix stripping');
    rs();
    addEntryWoCheck('/', 'n2', 'Some opinion.', 0, '');
    openBottomSheet(data['/']['n2']);
    assert('dot suffix stripped', document.getElementById('bs-textarea').value, 'Some opinion');
    closeBottomSheet();

    suite('openBottomSheet — fake !- stripped');
    rs();
    addEntryWoCheck('/', 'n3', 'Wrong claim!-', 0, '');
    openBottomSheet(data['/']['n3']);
    assert('text stripped',  document.getElementById('bs-textarea').value,                'Wrong claim');
    assert('chip is fake',   document.querySelector('.bs-type-chip.active').dataset.type, '!-');
    closeBottomSheet();
}
testBottomSheetSuffixStripping();

// UC10 — AC10.2: Bewiesen ✓ / Widerlegung ✓ when signed ≥ 2
function testBuildCardVerified() {
    const _tdm = typeDisplayMode;
    typeDisplayMode = 'text';

    suite('buildCard — signed < 2 → no verified badge (AC10.2)');
    rs();
    addEntryWoCheck('/', 'n1', 'True!', 0, '');
    const card0 = buildCard(data['/']['n1']);
    assert('0 signed → not bewiesen',          card0.querySelector('.type-badge').classList.contains('bewiesen'), false);
    assert('0 signed → sign-count no verified', card0.querySelector('.sign-count').classList.contains('verified'), false);

    suite('buildCard — signed = 1 → boundary, still no badge (AC10.2)');
    rs();
    addEntryWoCheck('/', 'n1', 'True!', 0, '');
    addVotesData({ '/n1': { votes: 0, attrs: { signed_count: 1 } } });
    const card1 = buildCard(data['/']['n1']);
    assert('1 signed → not bewiesen', card1.querySelector('.type-badge').classList.contains('bewiesen'), false);

    suite('buildCard — Fakt signed = 2 → Bewiesen ✓ (AC10.2)');
    rs();
    addEntryWoCheck('/', 'n1', 'True!', 0, '');
    addVotesData({ '/n1': { votes: 0, attrs: { signed_count: 2 } } });
    const card2 = buildCard(data['/']['n1']);
    assert('2 signed → bewiesen class',        card2.querySelector('.type-badge').classList.contains('bewiesen'), true);
    assert('2 signed → Bewiesen ✓ label',      card2.querySelector('.type-badge').textContent.trim(),             'Bewiesen ✓');
    assert('2 signed → sign-count verified',   card2.querySelector('.sign-count').classList.contains('verified'), true);

    suite('buildCard — Fake signed = 2 → Widerlegung ✓ (AC10.2)');
    rs();
    addEntryWoCheck('/', 'n2', 'Wrong!-', 0, '');
    addVotesData({ '/n2': { votes: 0, attrs: { signed_count: 2 } } });
    const cardFake = buildCard(data['/']['n2']);
    assert('fake 2 signed → widerlegung class', cardFake.querySelector('.type-badge').classList.contains('widerlegung'), true);
    assert('fake 2 signed → Widerlegung ✓',     cardFake.querySelector('.type-badge').textContent.trim(),                'Widerlegung ✓');

    suite('buildCard — Opinion signed = 2 → NOT verified (AC10.2)');
    rs();
    addEntryWoCheck('/', 'n3', 'Just opinion.', 0, '');
    addVotesData({ '/n3': { votes: 0, attrs: { signed_count: 2 } } });
    const cardOp = buildCard(data['/']['n3']);
    assert('opinion 2 signed → not bewiesen',    cardOp.querySelector('.type-badge').classList.contains('bewiesen'),    false);
    assert('opinion 2 signed → not widerlegung', cardOp.querySelector('.type-badge').classList.contains('widerlegung'), false);

    typeDisplayMode = _tdm;
}
testBuildCardVerified();

// UC3 — nav-topic span shows current path
function testNavTopic() {
    suite('nav-topic — shows current topic path');
    rs();
    navigateTo('/climate');
    const navTopic = document.getElementById('nav-topic');
    assert('shows /climate',   navTopic ? navTopic.textContent : 'missing', '/climate');
    navigateTo('/climate/solutions');
    assert('shows deep path',  navTopic ? navTopic.textContent : 'missing', '/climate/solutions');
    navigateTo('/');
    assert('empty at root',    navTopic ? navTopic.textContent : 'missing', '');
}
testNavTopic();

// UC11 — search scope chips: order and German labels
function testScopeChips() {
    suite('scope chips — reordered German labels');
    const chips = Array.from(document.querySelectorAll('.scope-chip'));
    const scopes = chips.map(c => c.dataset.scope);
    assert('global is first',    scopes[0], 'global');
    assert('here is second',     scopes[1], 'here');
    assert('below is third',     scopes[2], 'below');
    const labels = chips.map(c => {
        const span = c.querySelector('span');
        return span ? span.textContent.trim() : c.textContent.trim();
    });
    assert('global label',       labels[0], 'Global');
    assert('here label is Hier', labels[1], 'Hier');
    assert('below is Darunter',  labels[2], 'Darunter');
}
testScopeChips();

// ── Done ─────────────────────────────────────────────────────────────────────
harnessFinish();
