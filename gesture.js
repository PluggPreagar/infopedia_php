// gesture.js — unified gesture detector
// Fires CustomEvents (bubbling) on the touched element:
//   gesture:tap, gesture:doubletap, gesture:longpress,
//   gesture:swipe  (detail: { direction: "left"|"right", dx, dy })
// Knows nothing about app logic — wire handlers in the caller.
function initGestureDetector(root, opts = {}) {
    const LONG_MS     = opts.longMs     ?? 500;
    const DOUBLE_MS   = opts.doubleMs   ?? 350;
    const SWIPE_PX    = opts.swipePx    ?? 50;
    const SWIPE_RATIO = opts.swipeRatio ?? 1.5;
    const SWIPE_MS    = opts.swipeMs    ?? 700;
    const MOVE_THRESH = 8;

    let ptr = null;
    let lastTap = { el: null, time: 0 };

    function emit(el, name, extra) {
        el.dispatchEvent(new CustomEvent("gesture:" + name, {
            bubbles: true, composed: true,
            detail: { target: el, ...extra }
        }));
    }

    root.addEventListener("pointerdown", e => {
        if (ptr) return;
        ptr = {
            el: e.target, x0: e.clientX, y0: e.clientY, t0: Date.now(), moved: false,
            timer: setTimeout(() => { const el = ptr?.el; ptr = null; emit(el, "longpress"); }, LONG_MS),
        };
    }, { passive: true });

    root.addEventListener("pointermove", e => {
        if (!ptr || ptr.moved) return;
        if (Math.abs(e.clientX - ptr.x0) > MOVE_THRESH || Math.abs(e.clientY - ptr.y0) > MOVE_THRESH) {
            clearTimeout(ptr.timer); ptr.timer = null; ptr.moved = true;
        }
    }, { passive: true });

    root.addEventListener("pointerup", e => {
        if (!ptr) return;
        const { el, x0, y0, t0, moved } = ptr;
        const dx = e.clientX - x0, dy = e.clientY - y0, dt = Date.now() - t0;
        clearTimeout(ptr.timer); ptr = null;

        if (Math.abs(dx) >= SWIPE_PX && Math.abs(dx) / (Math.abs(dy) || 1) >= SWIPE_RATIO && dt < SWIPE_MS) {
            emit(el, "swipe", { dx, dy, direction: dx > 0 ? "right" : "left" });
            return;
        }
        if (moved) return;

        const now = Date.now();
        if (lastTap.el === el && now - lastTap.time < DOUBLE_MS) {
            lastTap = { el: null, time: 0 };
            emit(el, "doubletap");
        } else {
            lastTap = { el, time: now };
            emit(el, "tap");
        }
    }, { passive: true });

    root.addEventListener("pointercancel", () => { clearTimeout(ptr?.timer); ptr = null; }, { passive: true });
}
