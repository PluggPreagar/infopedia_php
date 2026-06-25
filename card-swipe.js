// card-swipe.js — 3-state swipe controller for .card elements
// States: CLOSED → REVEAL (≥ revealPx) → ACTIVATE (≥ activateRatio × cardSize)
// Fires CustomEvent 'cardswipe:action' { detail: { direction } } on pointer-up
// in ACTIVATE state. Snap-back happens in all cases. Dragging back below
// revealPx before release cancels the action (action fires on pointer-up
// position only, never on threshold crossing).
//
// Options (all optional):
//   revealPx      — px before direction is locked and tray starts showing (default: 40)
//   activateRatio — fraction of card size to reach ACTIVATE (default: 0.5)
//   rubberRatio   — slowdown factor beyond ACTIVATE threshold (default: 0.4)
//   showTray      — inject tray icons and animate them on drag (default: true)
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

var _TRAY_DEFS = [
    { dir: 'right', cls: 'card-tray-right', icon: 'icon-thumb-up'   },
    { dir: 'left',  cls: 'card-tray-left',  icon: 'icon-thumb-down' },
    { dir: 'up',    cls: 'card-tray-up',    icon: 'icon-arrow-up'  },
    { dir: 'down',  cls: 'card-tray-down',  icon: 'icon-arrow-down'},
];

// neutral start color (--color-neutral-400 #9CA3AF) for the drag-color gradient
var _SI_NEUTRAL = 'rgb(156,163,175)';

function _lerpRgb(from, to, t) {
    var a = from.match(/\d+/g).map(Number);
    var b = to.match(/\d+/g).map(Number);
    return 'rgb(' + a.map(function(v, i) {
        return Math.round(v + (b[i] - v) * t);
    }).join(',') + ')';
}

// V8g compound path: rounded-rect → circle. rc=0 → sharp rect, rc=16 → circle.
function _si_sp(rc) {
    if (rc < 0.02) return 'M 38 6 L 38 38 L 6 38 L 6 6 Z';
    var s = (38 - rc).toFixed(2), e = (6 + rc).toFixed(2), r = rc.toFixed(2);
    return 'M ' + s + ' 6 A ' + r + ' ' + r + ' 0 0 1 38 ' + e
         + ' L 38 ' + s + ' A ' + r + ' ' + r + ' 0 0 1 ' + s + ' 38'
         + ' L ' + e + ' 38 A ' + r + ' ' + r + ' 0 0 1 6 ' + s
         + ' L 6 ' + e + ' A ' + r + ' ' + r + ' 0 0 1 ' + e + ' 6 Z';
}

var _SI_D0 = 'M 38 6 L 38 38 L 6 38 L 6 6 Z'; // p=0 path (sharp rect)

function _makeTrayContent(iconId) {
    // V8g: dim full outline + two bright arcs from top-mid and bottom-mid, each growing CW.
    // Initial state p=0: bright arc length=0 (dasharray "0 64"), dashoffset=16.
    return '<svg viewBox="0 0 44 44" width="44" height="44">'
        + '<path class="si-base" d="' + _SI_D0 + '"'
        +   ' fill="none" stroke="currentColor" stroke-width="2" opacity=".22"/>'
        + '<path class="si-bright" d="' + _SI_D0 + '"'
        +   ' fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"'
        +   ' stroke-dasharray="0 64" stroke-dashoffset="16"/>'
        + '<use href="#' + iconId + '" x="10" y="10" width="24" height="24"'
        +   ' class="si-icon" style="fill:none;stroke:currentColor;stroke-width:1.5" opacity="0.15"/>'
        + '</svg>';
}

function initCardSwipe(root, opts) {
    opts = opts || {};
    const REVEAL_PX      = opts.revealPx      != null ? opts.revealPx      : 40;
    const ACTIVATE_RATIO = opts.activateRatio  != null ? opts.activateRatio : 0.5;
    const RUBBER_RATIO   = opts.rubberRatio    != null ? opts.rubberRatio   : 0.4;
    const SHOW_TRAY      = opts.showTray       !== false;
    const SNAP_MS        = 250;

    // Wrap each .card in .card-wrap and inject tray icon elements
    if (SHOW_TRAY) {
        var cards = root.querySelectorAll('.card');
        for (var i = 0; i < cards.length; i++) {
            var c = cards[i];
            if (c.parentElement.classList.contains('card-wrap')) continue;
            var wrap = document.createElement('div');
            wrap.className = 'card-wrap';
            c.parentNode.insertBefore(wrap, c);
            wrap.appendChild(c);
            _TRAY_DEFS.forEach(function(d) {
                var tray = document.createElement('div');
                tray.className = 'card-swipe-tray ' + d.cls;
                tray.setAttribute('aria-hidden', 'true');
                tray.innerHTML = _makeTrayContent(d.icon);
                wrap.insertBefore(tray, c);
                tray._siTargetColor = getComputedStyle(tray).color;
            });
        }
    }

    const drags = new WeakMap();

    function trayFor(card, dir) {
        var wrap = card.parentElement;
        if (!wrap || !wrap.classList.contains('card-wrap')) return null;
        return wrap.querySelector('.card-tray-' + dir);
    }

    function snapBack(card) {
        card.style.transition = 'transform ' + SNAP_MS + 'ms cubic-bezier(0.34,1.56,0.64,1)';
        card.style.transform  = '';
        card.classList.remove(
            'swiping-left', 'swiping-right', 'swiping-up', 'swiping-down', 'swipe-activate'
        );

        if (SHOW_TRAY) {
            var wrap = card.parentElement;
            if (wrap && wrap.classList.contains('card-wrap')) {
                var trays = wrap.querySelectorAll('.card-swipe-tray');
                trays.forEach(function(t) {
                    t.style.transition = 'opacity ' + SNAP_MS + 'ms, transform ' + SNAP_MS + 'ms';
                    t.style.opacity   = '0';
                    t.style.transform = 'scale(1)';
                    var rbase = t.querySelector('.si-base');
                    var rbright = t.querySelector('.si-bright');
                    if (rbase)   rbase.setAttribute('d', _SI_D0);
                    if (rbright) {
                        rbright.setAttribute('d', _SI_D0);
                        rbright.setAttribute('stroke-dasharray', '0 64');
                        rbright.setAttribute('stroke-dashoffset', '16');
                    }
                    var rico = t.querySelector('.si-icon');
                    if (rico) rico.setAttribute('opacity', '0.15');
                    setTimeout(function() {
                        t.style.transition = '';
                        t.style.opacity    = '';
                        t.style.transform  = '';
                        t.style.color      = '';
                    }, SNAP_MS);
                });
            }
        }

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

        // Animate tray icon — fade in proportional to drag, scale at ACTIVATE
        if (SHOW_TRAY) {
            _TRAY_DEFS.forEach(function(d) {
                var t = trayFor(card, d.dir);
                if (!t) return;
                if (d.dir === drag.dir) {
                    t.style.opacity   = String(Math.min(1, rawDist / REVEAL_PX));
                    t.style.transform = state === 'ACTIVATE' ? 'scale(1.3)' : 'scale(1)';
                    var prog  = Math.min(1, rawDist / activateDist);
                    t.style.color = _lerpRgb(_SI_NEUTRAL, t._siTargetColor || getComputedStyle(t).color, prog);
                    var rc_v  = 16 * prog;
                    var al_v  = Math.PI * rc_v / 2;
                    var sl_v  = Math.max(0, 32 - 2 * rc_v);
                    var per_v = al_v + sl_v;
                    var spath = _si_sp(rc_v);
                    var sbase = t.querySelector('.si-base');
                    var sbright = t.querySelector('.si-bright');
                    if (sbase) sbase.setAttribute('d', spath);
                    if (sbright) {
                        sbright.setAttribute('d', spath);
                        sbright.setAttribute('stroke-dasharray',
                            (2 * per_v * prog).toFixed(2) + ' ' + (2 * per_v * (1 - prog)).toFixed(2));
                        sbright.setAttribute('stroke-dashoffset', (sl_v / 2).toFixed(2));
                    }
                    var sico  = t.querySelector('.si-icon');
                    if (sico) sico.setAttribute('opacity', (0.12 + 0.88 * prog).toFixed(2));
                } else {
                    t.style.opacity   = '0';
                    t.style.transform = 'scale(1)';
                }
            });
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
