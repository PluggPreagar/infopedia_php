# js_runner.js — TestRunner Extract & Fix

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the inline `TestRunner` class from `app.html` into `test/js_runner.js`, fixing 4 known bugs and improving naming/extensibility along the way.

**Architecture:** Generic `TestRunner` lives in `test/js_runner.js` with no app dependencies. `app.html` loads it via `<script src>`, instantiates it, and sets `tr.beforeBlock` for app-specific reset checks. All 62 call-sites in `app.html` are renamed to match the new API.

**Tech Stack:** Vanilla JS, no dependencies, browser-only (Promise chains, `setInterval`, `document.querySelector`).

## Global Constraints

- CP1: No classes imported from npm/Composer — plain browser JS only.
- No test framework dependency — `js_runner.js` is self-contained.
- Existing test behavior must be preserved: same console output, same pass/fail semantics.
- `app.html` is served directly by the browser (and by `php -S`). The `<script src="test/js_runner.js">` path must resolve relative to the server root.

---

## Bugs Being Fixed

| # | Location | Root cause |
|---|----------|------------|
| B1 | `assertTrue` / `assertFalse` | Missing `this.` → `ReferenceError` at runtime |
| B2 | `execute(fn)` | `typeof fn === "function"` check runs at chain-build time, not execution time |
| B3 | `waitFor` timeout | Sets `this.timeout = null` then checks `this.timeout === null` → always resolves silently |
| B4 | `isVisibleOrWaitFor` | Passes `() => this.isVisible(...)` as condition; `isVisible` appends to the chain AND returns `this` (truthy) → condition is always immediately true |

---

## File Map

| File | Action | Lines changed |
|------|--------|---------------|
| `test/js_runner.js` | **Create** | ~230 lines, full class |
| `app.html` | **Modify** | Add `<script src>` at line 747; remove class at 2357–2665; rename 62 call-sites; add `beforeBlock` |

---

## API Rename Table

| Old name | New name | Notes |
|----------|----------|-------|
| `isElementVisible(el)` | `_computedVisible(el)` | internal, prefixed `_` |
| `getElementWText(sel, txt)` | `_find(sel, txt)` | internal |
| *(new)* | `_visible(sel, txt)` | boolean predicate for `waitFor*` |
| *(new)* | `_waitForCondition(fn, ms, int, lbl)` | internal Promise helper, fixes B3 |
| `isVisible(sel, txt, msg)` | `assertVisible(sel, txt, label)` | B4 no longer calls this from waitFor |
| `isNotVisible(sel, txt, msg)` | `assertHidden(sel, txt, label)` | |
| `isAvailable(sel, txt, msg)` | `assertOption(sel, txt, label)` | SELECT option exists |
| `isNotAvailable(sel, txt, msg)` | `assertNoOption(sel, txt, label)` | SELECT option absent |
| `isVisibleOrWaitFor(sel, txt, msg, ms, int)` | `waitForVisible(sel, txt, label, ms, int)` | uses `_visible`, fixes B4 |
| `isNotVisibleOrWaitFor(sel, txt, msg, ms, int)` | `waitForHidden(sel, txt, label, ms, int)` | |
| `execute(fn, msg)` | `run(fn, label)` | check moved inside chain, fixes B2 |
| `start(name)` | `_logBlock(name)` | internal, called by `runBlocks` |
| `showSummary()` | `summary()` | |
| `blockSetupChecks(name)` | *removed* → `this.beforeBlock` hook | injectable |
| `check(cond, ok, fail, msg)` | `check(cond, passMsg, failMsg, label)` | param rename only, same behavior |

---

## Task 1: Create `test/js_runner.js`

**Files:**
- Create: `test/js_runner.js`

**Interfaces:**
- Produces: `window.TestRunner` class with methods listed in API Rename Table above.
- Constructor: `new TestRunner({ stopOnError = false, beforeBlock = null } = {})`

- [ ] **Step 1: Create `test/js_runner.js` with the complete class**

```javascript
/**
 * test/js_runner.js — lightweight browser GUI test runner
 *
 * Usage:
 *   const tr = new TestRunner({ stopOnError: true });
 *   tr.beforeBlock = (runner) => runner.assertEq('/', topicFilter.value, 'topic reset');
 *   tr.addBlock('my block', (runner) => {
 *       runner.assertVisible('td', 'hello').click('button', 'OK');
 *   });
 *   tr.runBlocks();
 */
class TestRunner {
    constructor({ stopOnError = false, beforeBlock = null } = {}) {
        this.stopOnError = stopOnError;
        this.beforeBlock = beforeBlock;
        this.chain  = Promise.resolve();
        this.blocks = {};
        this.errors = [];
        this.checkCount = 0;
    }

    // ── Core ─────────────────────────────────────────────────────────────────

    check(condition, passMsg = null, failMsg = null, label = null) {
        if (this.stopOnError && this.errors.length > 0) return false;
        this.checkCount += 1;
        const suffix = label ? ` — ${label}` : '';
        if (condition) {
            if (passMsg) console.log(`OK : ${passMsg}${suffix}`);
        } else {
            const msg = `FAIL: ${failMsg || passMsg || 'check failed'}${suffix}`;
            console.error(msg);
            this.errors.push(msg);
            if (this.stopOnError) throw new Error(msg);
        }
        return !!condition;
    }

    // ── Internal helpers (non-chaining) ──────────────────────────────────────

    _computedVisible(element) {
        while (element) {
            const s = window.getComputedStyle(element);
            if (s.display === 'none' || s.visibility === 'hidden' || s.opacity === '0') return false;
            element = element.parentElement;
        }
        return true;
    }

    _find(selector, text = null) {
        for (const el of document.querySelectorAll(selector)) {
            if (text === null || el.textContent.includes(text)) return el;
        }
        return null;
    }

    _visible(selector, text = null) {
        const el = this._find(selector, text);
        return !!el && this._computedVisible(el);
    }

    _waitForCondition(conditionFn, timeoutMs = 1000, intervalMs = 100, label = null) {
        const dbg = label ? ` — ${label}` : '';
        console.log(`waitFor${dbg}: polling up to ${timeoutMs}ms`);
        return new Promise(resolve => {
            const deadline = Date.now() + timeoutMs;
            const id = setInterval(() => {
                if (conditionFn()) {
                    clearInterval(id);
                    console.log(`waitFor${dbg}: condition met`);
                    resolve(true);
                } else if (Date.now() >= deadline) {
                    clearInterval(id);
                    this.check(false, null, `waitFor timed out after ${timeoutMs}ms`, label);
                    resolve(false);
                }
            }, intervalMs);
        });
    }

    // ── Assertions ────────────────────────────────────────────────────────────

    assertEq(expected, actual, label = null) {
        this.chain = this.chain.then(() => {
            const v = typeof actual === 'function' ? actual() : actual;
            this.check(expected === v,
                `assertEq: '${expected}' === '${v}'`,
                `assertEq: expected '${expected}' got '${v}'`,
                label);
        });
        return this;
    }

    assertTrue(actual, label = null)  { return this.assertEq(true,  actual, label); }  // B1 fix: this.
    assertFalse(actual, label = null) { return this.assertEq(false, actual, label); }  // B1 fix: this.

    assertVisible(selector, text = null, label = null) {
        this.chain = this.chain.then(() => {
            const el = this._find(selector, text) || this._find(selector);
            if (!this.check(el && this._computedVisible(el),
                    `visible: ${selector}${text ? ` "${text}"` : ''}`,
                    `not visible: ${selector}${text ? ` "${text}"` : ''}`, label)) return;
            if (!text) return;
            let content;
            if (el.tagName === 'SELECT') {
                const opt = el.options[el.selectedIndex];
                content = opt ? opt.text : '';
                this.check(content === text,
                    `selected: "${text}"`,
                    `selected "${content}" ≠ "${text}"`, label);
            } else {
                content = el.textContent;
                this.check(content.includes(text),
                    `contains: "${text}"`,
                    `"${text}" not in "${content.slice(0, 80)}"`, label);
            }
        });
        return this;
    }

    assertHidden(selector, text = null, label = null) {
        this.chain = this.chain.then(() => {
            const el = this._find(selector, text);
            this.check(!el || !this._computedVisible(el),
                `hidden: ${selector}`,
                `should be hidden: ${selector}`, label);
        });
        return this;
    }

    assertOption(selector, text, label = null) {
        this.chain = this.chain.then(() => {
            const el = document.querySelector(selector);
            const opts = el ? Array.from(el.options) : [];
            const found = opts.find(o => o.text === text || o.text.replace(/ /g, ' ') === text);
            this.check(!!found,
                `option "${text}" in ${selector}`,
                `option "${text}" not found in ${selector}`, label);
        });
        return this;
    }

    assertNoOption(selector, text, label = null) {
        this.chain = this.chain.then(() => {
            const el = document.querySelector(selector);
            const opts = el ? Array.from(el.options) : [];
            const found = opts.find(o => o.text === text || o.text.replace(/ /g, ' ') === text);
            this.check(!found,
                `no option "${text}" in ${selector}`,
                `option "${text}" unexpectedly present in ${selector}`, label);
        });
        return this;
    }

    // ── Actions ───────────────────────────────────────────────────────────────

    set(selector, value, label = null) {
        this.chain = this.chain.then(() => {
            const el = document.querySelector(selector);
            if (!this.check(el, `set ${selector}`, `not found: ${selector}`, label)) return;
            el.value = value;
            if (el.value !== value) {
                const opt = Array.from(el.options || []).find(o => o.text === value);
                if (this.check(opt, `option "${value}"`, `option "${value}" not found in ${selector}`, label)) {
                    el.value = opt.value;
                }
            }
            const evt = (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') ? 'input' : 'change';
            el.dispatchEvent(new Event(evt));
        });
        return this;
    }

    click(selector, text = null, label = null) {
        this.chain = this.chain.then(() => {
            const el = this._find(selector, text);
            if (this.check(el, `click ${selector}`, `not found: ${selector}`, label)) el.click();
        });
        return this;
    }

    run(fn, label = null) {  // B2 fix: check inside chain
        this.chain = this.chain.then(() => {
            if (this.check(typeof fn === 'function', 'run: fn ok', 'run: not a function', label)) fn();
        });
        return this;
    }

    wait(ms, label = null) {
        this.chain = this.chain.then(() => {
            console.log(`wait ${ms}ms${label ? ` — ${label}` : ''}`);
            return new Promise(resolve => setTimeout(resolve, ms));
        });
        return this;
    }

    waitFor(conditionFn, timeoutMs = 1000, intervalMs = 100, label = null) {  // B3 fix
        this.chain = this.chain.then(() =>
            this._waitForCondition(conditionFn, timeoutMs, intervalMs, label)
        );
        return this;
    }

    waitForVisible(selector, text = null, label = null, timeoutMs = 1000, intervalMs = 100) {  // B4 fix
        this.chain = this.chain.then(() =>
            this._waitForCondition(
                () => this._visible(selector, text),
                timeoutMs, intervalMs,
                label || `waitForVisible: ${selector}`
            )
        );
        return this;
    }

    waitForHidden(selector, text = null, label = null, timeoutMs = 1000, intervalMs = 100) {
        this.chain = this.chain.then(() =>
            this._waitForCondition(
                () => !this._visible(selector, text),
                timeoutMs, intervalMs,
                label || `waitForHidden: ${selector}`
            )
        );
        return this;
    }

    // ── Block management ──────────────────────────────────────────────────────

    addBlock(name, block) {
        if (typeof block !== 'function') {
            const msg = `addBlock "${name}": block must be a function`;
            console.error(msg); this.errors.push(msg); return this;
        }
        this.blocks[name] = block;
        return this;
    }

    clearBlocks() { this.blocks = {}; return this; }

    _logBlock(name) {
        this.chain = this.chain.then(() => {
            if (name === '_start') {
                console.log('─── TestRunner ─────────────────────────────────');
            } else if (name === '_end') {
                console.log('────────────────────────────────────────────────');
                this.summary();
            } else {
                console.log(`\n── ${name} ──`);
            }
        });
        return this;
    }

    runBlocks(stopOnError = null) {
        if (stopOnError !== null) this.stopOnError = stopOnError;
        const all = { _start: () => {}, ...this.blocks, _end: () => {} };
        Object.entries(all).forEach(([name, block]) => {
            this._logBlock(name);
            if (name !== '_start' && name !== '_end' && this.beforeBlock) {
                this.chain = this.chain.then(() => this.beforeBlock(this));
            }
            this.chain = this.chain.then(() => block(this));
        });
        this.chain = this.chain.catch(err => console.error('TestRunner fatal:', err));
        return this.chain;
    }

    summary() {
        console.log(`\nChecks: ${this.checkCount}`);
        if (this.errors.length === 0) {
            console.log(`✓ All ${this.checkCount} passed`);
        } else {
            console.error(`✗ ${this.errors.length} failed`);
            this.errors.forEach((e, i) => console.error(`  ${i + 1}. ${e}`));
        }
    }
}
```

- [ ] **Step 2: Verify the file exists**

```bash
wc -l test/js_runner.js
```

Expected: ~235 lines.

- [ ] **Step 3: Commit**

```bash
git add test/js_runner.js
git commit -m "feat(test): add js_runner.js — generic TestRunner with bug fixes and clean naming"
```

---

## Task 2: Wire `app.html` to use `js_runner.js`

**Files:**
- Modify: `app.html`
  - Line 747: add `<script src="test/js_runner.js"></script>`
  - Lines 2357–2665: remove the inline `TestRunner` class
  - Line 2668: update constructor call
  - Lines 2587–2592: remove `blockSetupChecks`, add `tr.beforeBlock`
  - Lines 2680–2892: rename 62 call-sites (see rename table)

**Interfaces:**
- Consumes: `TestRunner` from Task 1.
- The app's `topicFilter`, `typeFilter`, `add-edit-section` are captured in the `beforeBlock` closure.

### Step 2a — Add the script tag

- [ ] **Step 2a.1: Insert `<script src="test/js_runner.js"></script>` on line 747**

In `app.html`, between the `</html>` closing tag (line 743) and the inline `<script>` opening (line 748), add:

```html
<script src="test/js_runner.js"></script>
```

This places it before the app's inline script, making `TestRunner` available when `const tr = new TestRunner()` executes.

### Step 2b — Remove the inline `TestRunner` class

- [ ] **Step 2b.1: Delete lines 2357–2665 in `app.html`**

Remove everything from `    class TestRunner {` through the closing `    }` (inclusive). After removal, the line `    // Example usage:` followed by `    const tr = new TestRunner();` should immediately follow the preceding code.

Verify after deletion:
```bash
grep -n "class TestRunner" app.html
```
Expected: no output.

### Step 2c — Update the constructor and add `beforeBlock`

- [ ] **Step 2c.1: Update the `tr` instantiation and inject `beforeBlock`**

Find (around what was line 2667–2668, now shifted earlier):
```javascript
    const tr = new TestRunner();
```

Replace with:
```javascript
    const tr = new TestRunner({ stopOnError: true });
    tr.beforeBlock = (runner) => runner
        .assertEq('/', topicFilter.value, 'topic filter reset to /')
        .assertHidden('#topicFilter', 'alle Basis-Themen', 'topic filter hidden')
        .assertHidden('#typeFilter', null, 'type filter hidden')
        .assertHidden('#add-edit-section', null, 'add/edit section hidden');
```

Note: `topicFilter`, `typeFilter` are in-scope variables in the app's inline script — the closure captures them correctly.

### Step 2d — Rename call-sites

- [ ] **Step 2d.1: Rename `.isNotVisible(` → `.assertHidden(`**

```bash
# Preview first:
grep -n "\.isNotVisible(" app.html | head -20
# Then apply:
sed -i 's/\.isNotVisible(/.assertHidden(/g' app.html
```

Expected count before: ~20 occurrences.

- [ ] **Step 2d.2: Rename `.isVisible(` → `.assertVisible(`**

```bash
grep -n "\.isVisible(" app.html | head -20
sed -i 's/\.isVisible(/.assertVisible(/g' app.html
```

- [ ] **Step 2d.3: Rename `.isVisibleOrWaitFor(` → `.waitForVisible(`**

```bash
grep -n "\.isVisibleOrWaitFor(" app.html
sed -i 's/\.isVisibleOrWaitFor(/.waitForVisible(/g' app.html
```

Verify: `.waitForVisible("td", "neuer Fakt!", "new fact is accepted")` — note the argument order is `(selector, text, label)`. This matches the new signature exactly.

- [ ] **Step 2d.4: Rename `.isNotVisibleOrWaitFor(` → `.waitForHidden(`**

```bash
grep -n "\.isNotVisibleOrWaitFor(" app.html
sed -i 's/\.isNotVisibleOrWaitFor(/.waitForHidden(/g' app.html
```

- [ ] **Step 2d.5: Rename `.isAvailable(` → `.assertOption(`**

```bash
grep -n "\.isAvailable(" app.html
sed -i 's/\.isAvailable(/.assertOption(/g' app.html
```

- [ ] **Step 2d.6: Rename `.isNotAvailable(` → `.assertNoOption(`**

```bash
grep -n "\.isNotAvailable(" app.html
sed -i 's/\.isNotAvailable(/.assertNoOption(/g' app.html
```

- [ ] **Step 2d.7: Rename `.execute(` → `.run(`**

```bash
grep -n "\.execute(" app.html
sed -i 's/\.execute(/.run(/g' app.html
```

Expected count: 11 occurrences.

- [ ] **Step 2d.8: Verify no old method names remain**

```bash
grep -n "\.isVisible\|\.isNotVisible\|\.isAvailable\|\.isNotAvailable\|\.isVisibleOrWaitFor\|\.isNotVisibleOrWaitFor\|\.execute(" app.html
```

Expected: no output.

- [ ] **Step 2d.9: Verify `runner.check(` calls still present (used in Block 5)**

```bash
grep -n "runner\.check(" app.html
```

Expected: 2 lines around the `unitTestPassed`/`unitTestFailed` loop:
```javascript
runner.check( true, `Unit Test Passed[${i}]: ${unitTestPassed[i]}` );
runner.check( false, `Unit Test Failed[${i}]: ${unitTestFailed[i]}` );
```

These call `check()` with `(condition, passMsg, failMsg=null, label=null)` — compatible with the new signature (passMsg used as fallback failMsg). No changes needed.

- [ ] **Step 2d.10: Commit**

```bash
git add app.html
git commit -m "refactor(app): switch from inline TestRunner to test/js_runner.js"
```

---

## Task 3: Browser Verify

- [ ] **Step 3.1: Start dev server**

```bash
php -S localhost:8080 router.php &
```

- [ ] **Step 3.2: Open app in browser, check console**

Open `http://localhost:8080/app.html` in the browser (or visit via IP if on LAN).

Expected console output:
```
─── TestRunner ─────────────────────────────────

── Block 0 init ──
wait 500ms
OK : assertEq: 'MOCK' === 'MOCK' — tenantId should be MOCK
...
✓ All N passed
```

No `ReferenceError`, no `FAIL:` lines.

- [ ] **Step 3.3: Verify bug fixes specifically**

Open DevTools → Console. Confirm:

1. **B1 fix**: `assertTrue`/`assertFalse` used anywhere? Search: `grep -n "assertTrue\|assertFalse" app.html` — not used in current test blocks, so B1 is fixed structurally; add a throwaway inline test if you want to confirm the `this.` is wired.
2. **B2 fix**: `.run(() => addEntry(...))` — check that the lambda IS called (look for the addEntry side-effects in the UI, e.g. "CoronaTST >" appearing).
3. **B3 fix**: In a slow network test or by temporarily increasing `timeoutMs`, confirm that a failed `waitFor` now prints `FAIL: waitFor timed out after Nms` instead of silently passing.
4. **B4 fix**: `.waitForVisible("td", "neuer Fakt!")` now uses `_visible()` (a plain boolean predicate). It should wait correctly and then pass when the entry appears.

- [ ] **Step 3.4: Commit verification note and kill server**

```bash
kill %1  # or kill $(lsof -t -i:8080)
git tag verified/js-runner-2026-06-18
```

---

## Self-Review Checklist

**Spec coverage:**
- [x] B1 assertTrue/assertFalse — fixed in Task 1 `assertTrue`/`assertFalse` body
- [x] B2 execute eager check — fixed in Task 1 `run()` body
- [x] B3 waitFor silent timeout — fixed in Task 1 `_waitForCondition` (records failure, not silent resolve)
- [x] B4 isVisibleOrWaitFor re-entrancy — fixed in Task 1 `waitForVisible` using `_visible()` not `assertVisible()`
- [x] blockSetupChecks extracted — Task 2c injects `tr.beforeBlock`
- [x] Naming improved — API rename table + all 62 call-sites in Task 2d
- [x] app.html wired — Task 2a (script tag), 2b (class removed), 2c (constructor), 2d (renames)

**Placeholder scan:** None found.

**Type consistency:**
- `beforeBlock(runner)` — `runner` is `this` (the `TestRunner` instance); closure captures app vars ✓
- `_waitForCondition` returns `Promise<boolean>` — awaited in `waitFor`, `waitForVisible`, `waitForHidden` ✓
- `run(fn, label)` — old call sites used `.execute(() => ...)` with trailing label — same positional args ✓
- `waitForVisible(sel, txt, lbl, ms, int)` — old call was `.isVisibleOrWaitFor(sel, txt, msg, ms, int)` — same positional order ✓
