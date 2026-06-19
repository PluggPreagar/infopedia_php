/**
 * Test cases for app2.html — loaded by wrapper.php?test=app2.html
 * Requires: test/harness.js (suite, assert, assertMatch, harnessFinish)
 * All app2.html functions are accessible by name (function declarations = window props).
 * State lets (data, votesData, …) are in the shared global lexical scope.
 */

// ── State reset ───────────────────────────────────────────────────────────────
function rs() {
    data          = {};
    votesData     = {};
    topicMap      = {};
    selectedTopic = '/';
    latestTimestamp = null;
    searchScope   = 'below';
    activeTypes   = new Set(['.', '!', '!-', '?', '??']);
    actionTrail   = [];
    document.getElementById('search-input').value = '';
}

// ── fullKey ───────────────────────────────────────────────────────────────────
suite('fullKey');
assert('root + nodeId',    fullKey('/', 'abc'),        '/abc');
assert('nested topic',     fullKey('/climate', 'sol'), '/climate/sol');
assert('deep nesting',     fullKey('/a/b', 'c'),       '/a/b/c');

// ── splitKey ──────────────────────────────────────────────────────────────────
suite('splitKey');
assert('root entry',       splitKey('/abc'),           ['/', 'abc']);
assert('one-level path',   splitKey('/climate/sol'),   ['/climate', 'sol']);
assert('deep path',        splitKey('/a/b/c'),         ['/a/b', 'c']);

// ── getTypeFromMessage ────────────────────────────────────────────────────────
suite('getTypeFromMessage');
assert('opinion (.)',      getTypeFromMessage('Hello.'),  '.');
assert('fact (!)',         getTypeFromMessage('Hello!'),  '!');
assert('fake (!-) ≠ (!)', getTypeFromMessage('Hello!-'), '!-');
assert('question (??)',    getTypeFromMessage('Hello??'), '??');
assert('unclear (?)',      getTypeFromMessage('Hello?'),  '?');
assert('topic (>)',        getTypeFromMessage('Topic>'),  '>');
assert('delete (--)',      getTypeFromMessage('x--'),     '--');
assert('no suffix',        getTypeFromMessage('Hello'),   '');
assert('empty string',     getTypeFromMessage(''),        '');

// ── matchType ─────────────────────────────────────────────────────────────────
suite('matchType');
assert('change . to !',    matchType('Hello.', '!'),    'Hello!');
assert('no suffix → add',  matchType('Hello', '!'),     'Hello!');
assert('same type noop',   matchType('Hello!', '!'),    'Hello!');
assert('change !- to !',   matchType('Hello!-', '!'),   'Hello!');
assert('change ?? to ?',   matchType('Hello??', '?'),   'Hello?');
assert('-- always append', matchType('Hello.', '--'),   'Hello.--');
assert('change > to .',    matchType('Topic>', '.'),    'Topic.');

// ── generateNodeId ────────────────────────────────────────────────────────────
suite('generateNodeId');
const id1 = generateNodeId(), id2 = generateNodeId();
assertMatch('alphanum string',   id1, /^[a-z0-9]+$/i);
assert('two calls differ',       id1 === id2, false);

// ── formatTimestamp ───────────────────────────────────────────────────────────
suite('formatTimestamp');
assert('empty → empty',          formatTimestamp(''),              '');
assert('invalid → passthrough',  formatTimestamp('bogus'),         'bogus');
assert('past year → year only',  formatTimestamp('2020-01-15 10:00:00'), '2020');

// ── escapeHtml ────────────────────────────────────────────────────────────────
suite('escapeHtml');
assert('< escaped',  escapeHtml('<'),              '&lt;');
assert('> escaped',  escapeHtml('>'),              '&gt;');
assert('& escaped',  escapeHtml('&'),              '&amp;');
assert('" escaped',  escapeHtml('"'),              '&quot;');
assert('mixed',      escapeHtml('<b>"hi"</b>'),    '&lt;b&gt;&quot;hi&quot;&lt;/b&gt;');
assert('plain text', escapeHtml('hello'),          'hello');

// ── getTypeDef ────────────────────────────────────────────────────────────────
suite('getTypeDef');
const factDef = getTypeDef('Hello!');
assert('fakt label',         factDef.label,             'Fakt');
assert('fakt cssClass',      factDef.cssClass,           'fakt');
assert('suffix stored',      factDef.suffix,             '!');
assert('fake cssClass',      getTypeDef('Hello!-').cssClass, 'fake');
assert('thema label',        getTypeDef('Topic>').label,     'Thema');
assert('unknown → default',  getTypeDef('no-suffix').label,  'Eintrag');

// ── getSignedCount ────────────────────────────────────────────────────────────
suite('getSignedCount');
rs();
assert('no data → 0',        getSignedCount('/', 'n1'), 0);
votesData['/n1'] = { votes: 3, signed: 2 };
assert('reads signed field', getSignedCount('/', 'n1'), 2);

// ── sanitiseForReport ─────────────────────────────────────────────────────────
suite('sanitiseForReport');
const _sid0 = sid, _tid0 = tenantId;
sid = 'mysid123'; tenantId = 'mytid456';
assert('replaces sid',      sanitiseForReport('sent by mysid123'),       'sent by [sid]');
assert('replaces tenantId', sanitiseForReport('tenant mytid456 here'),   'tenant [tid] here');
assert('replaces both',     sanitiseForReport('mysid123 and mytid456'),  '[sid] and [tid]');
assert('no match unchanged',sanitiseForReport('nothing here'),           'nothing here');
sid = _sid0; tenantId = _tid0;

// ── buildStateSnapshot ────────────────────────────────────────────────────────
suite('buildStateSnapshot');
rs(); selectedTopic = '/climate'; searchScope = 'here';
const snap = buildStateSnapshot();
assert('contains Thema',    snap.includes('/climate'), true);
assert('contains Filter:',  snap.includes('Filter:'),  true);
assert('contains Suche:',   snap.includes('Suche:'),   true);
assert('contains Karten:',  snap.includes('Karten:'),  true);

// ── buildReportText ───────────────────────────────────────────────────────────
suite('buildReportText');
rs();
const rep = buildReportText(null);
assert('has header',           rep.includes('=== Fehlerbericht ==='),    true);
assert('has aktionen section', rep.includes('--- Letzte Aktionen ---'),  true);
assert('has zustand section',  rep.includes('--- Zustand ---'),          true);
assert('no error section',     rep.includes('--- Fehlerdetails ---'),    false);

const repCtx = buildReportText({ label: 'sendVote', status: 500 });
assert('has Fehlerdetails', repCtx.includes('--- Fehlerdetails ---'), true);
assert('has error label',   repCtx.includes('sendVote'),              true);

// ── buildFullReport ───────────────────────────────────────────────────────────
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

// ── loadSettings / saveSettings ───────────────────────────────────────────────
suite('loadSettings / saveSettings');
localStorage.removeItem('fayf_settings');
assert('missing key → {}',   loadSettings(), {});
saveSettings({ tenantId: 'demo', sid: 'abc' });
assert('saved and loaded',   loadSettings(), { tenantId: 'demo', sid: 'abc' });
saveSettings({ sid: 'xyz' });
assert('patch merges',       loadSettings().tenantId, 'demo');
assert('patch updates',      loadSettings().sid,      'xyz');
localStorage.removeItem('fayf_settings');

// ── buildEntriesUrl / buildVotesUrl ──────────────────────────────────────────
suite('buildEntriesUrl / buildVotesUrl');
const _sid1 = sid, _tid1 = tenantId;
sid = 'tsid'; tenantId = 'ttid';
const eUrl = buildEntriesUrl();
assert('entries has sid',    eUrl.includes('sid=tsid'),  true);
assert('entries has tid',    eUrl.includes('tid=ttid'),  true);
assert('entries has format', eUrl.includes('format=json'), true);
assert('entries prefix',     eUrl.startsWith('entries?'), true);
const eUrlX = buildEntriesUrl({ since: '2024-01-01 00:00:00' });
assert('extra params added', eUrlX.includes('since='), true);
const vUrl = buildVotesUrl();
assert('votes has sid',      vUrl.includes('sid=tsid'),  true);
assert('votes prefix',       vUrl.startsWith('votes?'),  true);
sid = _sid1; tenantId = _tid1;

// ── debounceKey ───────────────────────────────────────────────────────────────
suite('debounceKey');
Object.keys(_debounceMap).forEach(k => delete _debounceMap[k]);
assert('first call → true',       debounceKey('dk_test', 1000), true);
assert('immediate repeat → false', debounceKey('dk_test', 1000), false);
assert('different key → true',    debounceKey('dk_other', 1000), true);
delete _debounceMap['dk_test'];
assert('after clear → true again', debounceKey('dk_test', 1000), true);

// ── pushAction ────────────────────────────────────────────────────────────────
suite('pushAction');
actionTrail = [];
pushAction('navigate', '/climate');
assert('appended to trail',  actionTrail.length,         1);
assert('action stored',      actionTrail[0].action,      'navigate');
assert('detail stored',      actionTrail[0].detail,      '/climate');
assertMatch('ts is ISO',     actionTrail[0].ts, /^\d{4}-\d{2}-\d{2}T/);

for (let i = 0; i < 15; i++) pushAction('t', String(i));
assert('capped at max (10)', actionTrail.length,                           ACTION_TRAIL_MAX);
assert('keeps newest',       actionTrail[actionTrail.length - 1].detail,  '14');
actionTrail = [];

// ── navigateTo ────────────────────────────────────────────────────────────────
suite('navigateTo');
rs();
navigateTo('/climate');
assert('selectedTopic updated', selectedTopic, '/climate');
navigateTo('/climate/solutions');
assert('deep navigation',       selectedTopic, '/climate/solutions');
navigateTo('/');
assert('back to root',          selectedTopic, '/');

// ── addEntryWoCheck ───────────────────────────────────────────────────────────
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

// ── checkData / addEntry ──────────────────────────────────────────────────────
suite('checkData — stub creation');
rs();
addEntry('/climate/solutions', 'n1', 'Solar panels.', 0, '');
assert('stub /climate created',     !!data['/']['climate'],              true);
assert('stub ends with >',          data['/']['climate'].message.endsWith('>'), true);
assert('deep entry stored',         !!data['/climate/solutions']['n1'], true);

// ── initializeTopicMap ────────────────────────────────────────────────────────
suite('initializeTopicMap');
rs();
addEntryWoCheck('/', 'climate', 'Climate Change>', 0, '');
initializeTopicMap();
assert('topic name registered', topicMap['/climate'], 'Climate Change');
addEntryWoCheck('/', 'other', 'No suffix.', 0, '');
initializeTopicMap();
assert('non-topic ignored',     topicMap['/other'],   undefined);

// ── addData ───────────────────────────────────────────────────────────────────
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

// ── addVotesData ──────────────────────────────────────────────────────────────
suite('addVotesData');
rs();
addEntryWoCheck('/', 'n1', 'Hello!', 0, '');
addVotesData({ '/n1': { votes: 7, attrs: { signed_count: 3 } } });
assert('votes in votesData',   votesData['/n1'].votes,  7);
assert('signed stored',        votesData['/n1'].signed, 3);
assert('votes synced to data', data['/']['n1'].votes,   7);

// ── addVoteByGui ──────────────────────────────────────────────────────────────
suite('addVoteByGui');
rs();
addEntryWoCheck('/', 'n1', 'Hello!', 2, '');
addVoteByGui('/', 'n1', 1);
assert('entry votes +1', data['/']['n1'].votes,  3);
assert('votesData +1',   votesData['/n1'].votes, 3);
addVoteByGui('/', 'n1', -1);
assert('downvote -1',    data['/']['n1'].votes,  2);

// ── setVoteByOthers ───────────────────────────────────────────────────────────
suite('setVoteByOthers');
rs();
addEntryWoCheck('/', 'n1', 'Hello!', 5, '');
setVoteByOthers('/', 'n1', 10);
assert('sets votes absolutely', data['/']['n1'].votes,  10);
assert('synced to votesData',   votesData['/n1'].votes, 10);

// ── getFilteredEntries ────────────────────────────────────────────────────────
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
assert('fact shown',              ents.some(e => e.nodeId === 'fact'),    true);
assert('opinion filtered out',    ents.some(e => e.nodeId === 'opinion'), false);
assert('topic (>) always shown',  ents.some(e => e.nodeId === 'topic'),   true);

suite('getFilteredEntries — sorted by votes desc');
rs();
addEntryWoCheck('/', 'lo', 'Low.',  1, '');
addEntryWoCheck('/', 'hi', 'High!', 9, '');
addEntryWoCheck('/', 'mi', 'Mid?',  5, '');
ents = getFilteredEntries();
assert('highest votes first', ents.map(e => e.nodeId), ['hi', 'mi', 'lo']);

// ── REQUIRE_TOPIC_FOR_ENTRY flag ──────────────────────────────────────────────
suite('REQUIRE_TOPIC_FOR_ENTRY flag');
function wouldBlock(flag, topic) { return flag && topic === '/'; }
assert('flag off → root ok',     wouldBlock(false, '/'),  false);
assert('flag off → topic ok',    wouldBlock(false, '/x'), false);
assert('flag on → root blocked', wouldBlock(true,  '/'),  true);
assert('flag on → topic ok',     wouldBlock(true,  '/x'), false);

// ── openBottomSheet — edit mode ───────────────────────────────────────────────
suite('openBottomSheet — edit mode');
rs();
addEntryWoCheck('/', 'n1', 'Solar!', 5, '');
const _editE = data['/']['n1'];
openBottomSheet(_editE);
assert('textarea pre-filled',       document.getElementById('bs-textarea').value,           'Solar');
assert('active chip matches type',  document.querySelector('.bs-type-chip.active').dataset.type, '!');
assert('heading says Bearbeiten',   document.querySelector('#bottom-sheet h3').textContent,  'Eintrag bearbeiten');
closeBottomSheet();
assert('heading reset after close', document.querySelector('#bottom-sheet h3').textContent,  'Neuer Eintrag');

// ── openBottomSheet — new mode ────────────────────────────────────────────────
suite('openBottomSheet — new mode');
rs();
openBottomSheet();
assert('textarea empty',           document.getElementById('bs-textarea').value,           '');
assert('heading says Neuer',       document.querySelector('#bottom-sheet h3').textContent,  'Neuer Eintrag');
assert('default chip is Meinung',  document.querySelector('.bs-type-chip.active').dataset.type, '.');
closeBottomSheet();

// ── openBottomSheet — edit strips suffix from textarea ────────────────────────
suite('openBottomSheet — suffix stripping');
rs();
addEntryWoCheck('/', 'n2', 'Some opinion.', 0, '');
openBottomSheet(data['/']['n2']);
assert('dot suffix stripped',      document.getElementById('bs-textarea').value, 'Some opinion');
closeBottomSheet();

suite('openBottomSheet — fake entry');
rs();
addEntryWoCheck('/', 'n3', 'Wrong claim!-', 0, '');
openBottomSheet(data['/']['n3']);
assert('text stripped',            document.getElementById('bs-textarea').value, 'Wrong claim');
assert('chip is fake',             document.querySelector('.bs-type-chip.active').dataset.type, '!-');
closeBottomSheet();

// ── Done ─────────────────────────────────────────────────────────────────────
harnessFinish();
