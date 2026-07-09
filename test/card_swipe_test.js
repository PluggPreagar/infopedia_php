/**
 * Tests for card-swipe.js pure logic
 * Loaded by wrapper.php: open wrapper.php?test=card_swipe.html in a browser
 * Requires: test/harness.js (suite, assert, harnessFinish)
 */

// ── cardSwipeDirection ────────────────────────────────────────────────────
function testCardSwipeDirection() {
    suite('cardSwipeDirection');

    assert('right swipe',      cardSwipeDirection(80, 10, 40),   'right');
    assert('left swipe',       cardSwipeDirection(-80, 5, 40),   'left');
    assert('down swipe',       cardSwipeDirection(5, 80, 40),    'down');
    assert('up swipe',         cardSwipeDirection(-5, -80, 40),  'up');
    assert('below threshold',  cardSwipeDirection(20, 10, 40),   null);
    assert('diagonal — horiz wins when dx > dy',
                               cardSwipeDirection(70, 40, 40),   'right');
    assert('diagonal — vert wins when dy > dx',
                               cardSwipeDirection(30, 80, 40),   'down');
    assert('exact threshold',  cardSwipeDirection(40, 5, 40),    'right');
    assert('just below',       cardSwipeDirection(39, 5, 40),    null);
}

// ── cardSwipeState ────────────────────────────────────────────────────────
function testCardSwipeState() {
    suite('cardSwipeState');

    // card 320px wide, revealPx=40, activateRatio=0.5 → activate at 160px
    assert('CLOSED below reveal',  cardSwipeState(30,  320, 40, 0.5), 'CLOSED');
    assert('CLOSED at 39px',       cardSwipeState(39,  320, 40, 0.5), 'CLOSED');
    assert('REVEAL at 40px',       cardSwipeState(40,  320, 40, 0.5), 'REVEAL');
    assert('REVEAL at 159px',      cardSwipeState(159, 320, 40, 0.5), 'REVEAL');
    assert('ACTIVATE at 160px',    cardSwipeState(160, 320, 40, 0.5), 'ACTIVATE');
    assert('ACTIVATE beyond 160',  cardSwipeState(220, 320, 40, 0.5), 'ACTIVATE');
    assert('CLOSED — zero',        cardSwipeState(0,   320, 40, 0.5), 'CLOSED');
}

testCardSwipeDirection();
testCardSwipeState();
harnessFinish();
