// card-swipe.js — 3-state swipe controller for .card elements
// States: CLOSED → REVEAL (≥ revealPx) → ACTIVATE (≥ activateRatio × cardSize)
// Fires CustomEvent 'cardswipe:action' { detail: { direction } } on pointer-up
// in ACTIVATE state. Snap-back happens in all cases. Dragging back below
// revealPx before release cancels the action (action fires on pointer-up
// position only, never on threshold crossing).
//
// Exposed pure functions for testing:
//   cardSwipeDirection(dx, dy, minPx) → 'left'|'right'|'up'|'down'|null
//   cardSwipeState(rawDist, cardSize, revealPx, activateRatio) → 'CLOSED'|'REVEAL'|'ACTIVATE'

function cardSwipeDirection(dx, dy, minPx) {
    const adx = Math.abs(dx), ady = Math.abs(dy);
    if (adx < minPx && ady < minPx) return null;
    if (adx >= ady) return dx > 0 ? 'right' : 'left';
    return dy > 0 ? 'down' : 'up';
}

function cardSwipeState(rawDist, cardSize, revealPx, activateRatio) {
    if (rawDist < revealPx) return 'CLOSED';
    if (rawDist < cardSize * activateRatio) return 'REVEAL';
    return 'ACTIVATE';
}

function initCardSwipe(root, opts) {
    opts = opts || {};
    const REVEAL_PX      = opts.revealPx      ?? 40;
    const ACTIVATE_RATIO = opts.activateRatio  ?? 0.5;
    const RUBBER_RATIO   = opts.rubberRatio    ?? 0.4;
    const SNAP_MS        = 250;

    const drags = new WeakMap();

    function snapBack(card) {
        card.style.transition = 'transform ' + SNAP_MS + 'ms cubic-bezier(0.34,1.56,0.64,1)';
        card.style.transform  = '';
        card.classList.remove(
            'swiping-left', 'swiping-right', 'swiping-up', 'swiping-down', 'swipe-activate'
        );
        setTimeout(function () { card.style.transition = ''; }, SNAP_MS);
    }

    function fire(card, direction) {
        card.dispatchEvent(new CustomEvent('cardswipe:action', {
            bubbles: true,
            detail: { direction: direction }
        }));
        if (navigator.vibrate) navigator.vibrate(30);
    }

    root.addEventListener('pointerdown', function (e) {
        const card = e.target.closest('.card');
        if (!card || drags.has(card)) return;
        drags.set(card, { x0: e.clientX, y0: e.clientY, dir: null, state: 'CLOSED' });
        card.setPointerCapture(e.pointerId);
    }, { passive: false });

    root.addEventListener('pointermove', function (e) {
        const card = e.target.closest('.card');
        if (!card) return;
        const drag = drags.get(card);
        if (!drag) return;

        const dx = e.clientX - drag.x0;
        const dy = e.clientY - drag.y0;

        if (!drag.dir) {
            drag.dir = cardSwipeDirection(dx, dy, REVEAL_PX);
            if (!drag.dir) return;
        }

        const horiz   = drag.dir === 'left' || drag.dir === 'right';
        const rawDist = horiz ? Math.abs(dx) : Math.abs(dy);
        const size    = horiz ? card.offsetWidth : card.offsetHeight;

        // rubber-band: beyond activate threshold, motion slows to RUBBER_RATIO
        const activateDist = size * ACTIVATE_RATIO;
        const dist = rawDist <= activateDist
            ? rawDist
            : activateDist + (rawDist - activateDist) * RUBBER_RATIO;

        const sign = (horiz ? dx : dy) > 0 ? 1 : -1;
        card.style.transform = horiz
            ? 'translateX(' + (sign * dist) + 'px)'
            : 'translateY(' + (sign * dist) + 'px)';

        const state = cardSwipeState(rawDist, size, REVEAL_PX, ACTIVATE_RATIO);
        drag.state  = state;

        card.classList.remove(
            'swiping-left', 'swiping-right', 'swiping-up', 'swiping-down', 'swipe-activate'
        );
        if (state !== 'CLOSED') {
            card.classList.add('swiping-' + drag.dir);
            if (state === 'ACTIVATE') card.classList.add('swipe-activate');
        }
    }, { passive: true });

    root.addEventListener('pointerup', function (e) {
        const card = e.target.closest('.card');
        if (!card) return;
        const drag = drags.get(card);
        if (!drag) return;
        drags.delete(card);

        if (drag.state === 'ACTIVATE') fire(card, drag.dir);
        snapBack(card);
    }, { passive: true });

    root.addEventListener('pointercancel', function (e) {
        const card = e.target.closest('.card');
        if (!card) return;
        drags.delete(card);
        snapBack(card);
    }, { passive: true });
}
