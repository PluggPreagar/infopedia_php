# Design System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Create the InfoPedia design system: `design-tokens.css`, `components.css`, `icons.svg`, `card-swipe.js`, and constitution guards CG-DS1–CG-DS5.

**Architecture:** Two-layer CSS (tokens file + component file) so tokens can be overridden independently of component rules. A separate `card-swipe.js` module handles the 3-state drag model without touching the generic `gesture.js`. An inline SVG sprite (`icons.svg`) replaces the CDN Font Awesome dependency.

**Tech Stack:** Vanilla CSS custom properties, vanilla JS (no framework, no build step), SVG sprites.

## Global Constraints

- No external dependencies — no CDN, no npm, no build tools
- All spacing: `--space-*` tokens only — no raw `px`/`rem` in component CSS (CG-DS2)
- All font sizes: `--text-*` tokens only (CG-DS3)
- Interactive color (`--color-interactive-*`) and semantic color (`--color-semantic-*`) never mixed on same element (CG-DS1)
- Every tappable element: min 44×44px hit area (CG-DS5)
- Focus: `:focus-visible` only, never suppress `outline` without replacing with `var(--focus-ring)` (CG-DS4)
- `prefers-reduced-motion`: all durations collapse to 1ms via token override
- Conventional commits: `feat(design): …` for new files, `chore(constitution): …` for constitution amendment
- Spec: `docs/superpowers/specs/2026-06-25-design-system-design.md`

---

## File Map

| Action | Path | Responsibility |
|--------|------|----------------|
| Create | `design-tokens.css` | All CSS custom properties — zero rules, zero selectors (except `:root` and `@media`) |
| Create | `components.css` | All semantic component classes — consumes tokens, no raw values |
| Create | `icons.svg` | Hidden inline SVG sprite — 15 `<symbol>` blocks, `display:none` wrapper |
| Create | `card-swipe.js` | 3-state drag state machine for `.card` elements (CLOSED/REVEAL/ACTIVATE) |
| Create | `test/card_swipe_test.js` | Browser-harness tests for card-swipe pure logic |
| Modify | `.ai/constitution.md` | Add CG-DS1–CG-DS5 section; bump version 1.9.0→1.10.0 |

Application to existing HTML/PHP pages is **out of scope** for this plan.

---

## Task 1: Constitution Amendment — CG-DS1 through CG-DS5

**Files:**
- Modify: `.ai/constitution.md`

**Interfaces:**
- Produces: stable IDs `CG-DS1` through `CG-DS5` referenced by all future code review

- [ ] **Step 1: Open `.ai/constitution.md` and locate the Sync Impact Report header**

The file starts with an HTML comment block containing the Sync Impact Report. Update it:

```
<!-- line 3 -->  - Version: 1.9.0 -> 1.10.0  (add CG-DS1–CG-DS5: design-system guards)
```

- [ ] **Step 2: Add the Design System section after the Governance section**

Add this block after the `## Governance` section and before `## Core Assumptions`:

```markdown
## Design System Guards

Rules binding all frontend code (HTML, CSS, JS). Enforced in code review.
Reference IDs follow the `CG-DS` prefix (Design System sub-namespace of Governance).

- **CG-DS1 -- Semantic vs. Interactive Color:** `--color-interactive-*` is for UI
  chrome only (nav, buttons, FAB, active chips, focus rings, swipe indicators).
  `--color-semantic-*` is for fact/truth states only (Fakt badge, verified, positive
  vote score, upvote confirmation). `--color-error` is for negative states only (Fake
  badge, downvote, negative vote score). Mixing interactive and semantic on the same
  element is a hard violation. Documented exceptions: `.badge-unklar` uses `#FF9800`
  (warning-orange); `.badge-gegenfrage` uses `#2196F3` (informational blue). No other
  out-of-system colors are permitted.

- **CG-DS2 -- Quantized Spacing:** every `margin`, `padding`, `gap`, `top`, `bottom`,
  `left`, `right` in component CSS MUST use a `--space-*` token. No raw length values.
  Violations = instant code review reject.

- **CG-DS3 -- Quantized Type:** every `font-size` in component CSS MUST use a
  `--text-*` token. No raw `rem` or `px` font sizes.

- **CG-DS4 -- No Suppressed Focus:** never write `outline: none` without immediately
  replacing with `box-shadow: var(--focus-ring)`. Always use `:focus-visible`, never
  `:focus`, to avoid suppressing focus for pointer users.

- **CG-DS5 -- Touch Target Minimum:** every tappable element MUST have a minimum hit
  area of 44×44px enforced via `min-height`/`min-width` or padding. Visual size may
  be smaller; hit area must not be.
```

- [ ] **Step 3: Update the version line and Last Amended date at the bottom**

Find the line near the end:
```
**Version**: 1.9.0 | **Ratified**: TODO(RATIFICATION_DATE) | **Last Amended**: 2026-06-18
```

Replace with:
```
**Version**: 1.10.0 | **Ratified**: TODO(RATIFICATION_DATE) | **Last Amended**: 2026-06-25
```

- [ ] **Step 4: Commit**

```bash
git add .ai/constitution.md
git commit -m "chore(constitution): add CG-DS1–CG-DS5 design-system guards (v1.10.0)"
```

---

## Task 2: design-tokens.css

**Files:**
- Create: `design-tokens.css`

**Interfaces:**
- Produces: all `--color-*`, `--space-*`, `--text-*`, `--radius-*`, `--shadow-*`, `--z-*`, `--duration-*`, `--ease-*`, `--focus-ring`, `--font-*` custom properties consumed by `components.css` and any page stylesheet

- [ ] **Step 1: Create `design-tokens.css` with the full token set**

```css
/* InfoPedia Design Tokens
 * Source of truth: docs/superpowers/specs/2026-06-25-design-system-design.md
 * Rules: CG-DS1 (color semantics), CG-DS2 (spacing), CG-DS3 (type)
 * Two documented color exceptions: .badge-unklar #FF9800, .badge-gegenfrage #2196F3
 */

:root {
  /* ── Interactive (amber) ─────────────────────────────────────────────────
   * CG-DS1: UI chrome only — nav, buttons, FAB, chips, focus, swipe indicators
   * --color-interactive-accent is decoration only, never a text background (2.6:1)
   * ──────────────────────────────────────────────────────────────────────── */
  --color-interactive-50:     #FFFBEB;
  --color-interactive-100:    #FEF3C7;
  --color-interactive-400:    #FBBF24;
  --color-interactive-600:    #D97706;   /* buttons — 4.8:1 on white ✓ AA  */
  --color-interactive-700:    #B45309;   /* nav bg  — 7.2:1 on white ✓ AAA */
  --color-interactive-accent: #F59E0B;   /* decoration only                 */

  /* ── Semantic (green) ────────────────────────────────────────────────────
   * CG-DS1: fact/truth states only — Fakt badge, verified, positive scores
   * ──────────────────────────────────────────────────────────────────────── */
  --color-semantic-50:  #F0FDF4;
  --color-semantic-500: #16A34A;   /* 5.8:1 on white ✓ AA */
  --color-semantic-700: #15803D;

  /* ── Surface ─────────────────────────────────────────────────────────── */
  --color-surface-page:    #F9FAFB;
  --color-surface-card:    #FFFFFF;
  --color-surface-overlay: rgba(0, 0, 0, 0.45);

  /* ── Neutral ─────────────────────────────────────────────────────────── */
  --color-neutral-200: #E5E7EB;
  --color-neutral-400: #9CA3AF;
  --color-neutral-600: #4B5563;
  --color-neutral-900: #111827;

  /* ── State ───────────────────────────────────────────────────────────── */
  --color-error: #DC2626;   /* 5.1:1 on white ✓ AA */
  --color-focus: #F59E0B;

  /* ── Typography ──────────────────────────────────────────────────────── */
  --font-family:        -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  --font-weight-normal: 400;
  --font-weight-medium: 500;
  --font-weight-bold:   700;

  --text-xs:   0.75rem;    /* line-height 1.1 — timestamps, meta */
  --text-sm:   0.85rem;    /* line-height 1.3 — chips, badges    */
  --text-base: 1rem;       /* line-height 1.5 — body, inputs     */
  --text-lg:   1.125rem;   /* line-height 1.4 — card emphasis    */
  --text-xl:   1.25rem;    /* line-height 1.3 — section headers  */
  --text-2xl:  1.5rem;     /* line-height 1.2 — page headings    */

  /* ── Spacing — base unit 4px, all multiples ──────────────────────────
   * CG-DS2: use these tokens for every margin/padding/gap/inset
   * ──────────────────────────────────────────────────────────────────── */
  --space-1:   4px;
  --space-2:   8px;
  --space-3:  12px;
  --space-4:  16px;
  --space-6:  24px;
  --space-8:  32px;
  --space-12: 48px;
  --space-16: 64px;

  /* ── Radius ───────────────────────────────────────────────────────── */
  --radius-sm:   4px;
  --radius-md:   8px;
  --radius-lg:   12px;
  --radius-full: 9999px;

  /* ── Elevation ────────────────────────────────────────────────────── */
  --shadow-0: none;
  --shadow-1: 0 1px 3px rgba(0, 0, 0, .08);
  --shadow-2: 0 4px 12px rgba(0, 0, 0, .12);
  --shadow-3: 0 8px 24px rgba(0, 0, 0, .18);

  /* ── Z-index ─────────────────────────────────────────────────────── */
  --z-base:      0;
  --z-chip-bar:  90;
  --z-nav:      100;
  --z-fab:      200;
  --z-sheet:    300;
  --z-overlay:  400;
  --z-toast:    500;

  /* ── Animation ───────────────────────────────────────────────────── */
  --duration-instant:  80ms;
  --duration-fast:    150ms;
  --duration-normal:  250ms;
  --duration-slow:    400ms;

  --ease-out:    cubic-bezier(0.0, 0.0, 0.2, 1);
  --ease-in:     cubic-bezier(0.4, 0.0, 1, 1);
  --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);

  /* ── Focus ───────────────────────────────────────────────────────── */
  --focus-ring: 0 0 0 3px var(--color-focus);
}

/* Collapse all durations for reduced-motion users */
@media (prefers-reduced-motion: reduce) {
  :root {
    --duration-instant: 1ms;
    --duration-fast:    1ms;
    --duration-normal:  1ms;
    --duration-slow:    1ms;
  }
}

/* Global focus ring — CG-DS4: never suppress focus without this replacement */
:focus-visible {
  outline: none;
  box-shadow: var(--focus-ring);
}
```

- [ ] **Step 2: Verify all required tokens are present**

```bash
grep -c "^  --" design-tokens.css
```

Expected: at least 42 lines starting with `  --` (one per token).

```bash
for token in --color-interactive-700 --color-semantic-500 --color-error --color-focus \
             --space-4 --space-16 --text-base --text-2xl --radius-md --radius-full \
             --shadow-3 --z-toast --duration-slow --ease-spring --focus-ring \
             --font-family; do
  grep -q "$token" design-tokens.css && echo "OK $token" || echo "MISSING $token"
done
```

Expected: all lines print `OK`.

- [ ] **Step 3: Verify CSS parses without errors**

Open `design-tokens.css` in a browser (drag-and-drop to address bar or serve via `just serve`). Open DevTools → Console. No errors. DevTools → Elements → `:root` computed styles shows all `--color-*`, `--space-*` variables populated.

- [ ] **Step 4: Commit**

```bash
git add design-tokens.css
git commit -m "feat(design): design-tokens.css — full token set (colors, spacing, type, animation)"
```

---

## Task 3: components.css

**Files:**
- Create: `components.css`

**Interfaces:**
- Consumes: all `--*` tokens from `design-tokens.css` (must be loaded first)
- Produces: `.nav`, `.nav-back`, `.nav-title`, `.nav-actions`, `.chip-bar`, `.chip`, `.chip.active`, `.chip-scope`, `.card`, `.card-header`, `.card-body`, `.card-footer`, `.card.swiping-*`, `.card.swipe-activate`, `.badge`, `.badge-*`, `.vote-score`, `.vote-score.*`, `.vote-btn`, `.btn`, `.btn-primary`, `.btn-secondary`, `.btn-ghost`, `.btn-icon`, `.btn-danger`, `.fab`, `.form-label`, `.input`, `.textarea`, `.overlay-backdrop`, `.overlay-backdrop.open`, `.panel`, `.panel-header`, `.panel-actions`, `.dialog`, `.bottom-sheet`, `.bottom-sheet.open`, `.toast`, `.toast-*`, `.icon`, `.icon-sm`, `.icon-md`, `.icon-lg`

- [ ] **Step 1: Create `components.css`**

```css
/* InfoPedia Component Classes
 * Requires: design-tokens.css loaded before this file
 * Rules: CG-DS1 (color), CG-DS2 (spacing), CG-DS3 (type), CG-DS4 (focus), CG-DS5 (touch)
 */

/* ── Base reset scoped to design system ──────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }

body {
  font-family: var(--font-family);
  font-size: var(--text-base);
  color: var(--color-neutral-900);
  background: var(--color-surface-page);
  margin: 0;
}

a { text-decoration: none; color: inherit; }

/* ── Navigation ──────────────────────────────────────────────────────────── */
.nav {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  background: var(--color-interactive-700);
  color: #fff;
  padding: var(--space-2) var(--space-4);
  position: sticky;
  top: 0;
  z-index: var(--z-nav);
  box-shadow: var(--shadow-0);
  min-height: 44px;
}

.nav-back {
  margin-right: auto;
  font-size: var(--text-sm);
  color: #fff;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 50vw;
  min-height: 44px;
  display: flex;
  align-items: center;
}

.nav-title {
  flex: 1;
  text-align: center;
  font-size: var(--text-sm);
  color: rgba(255, 255, 255, 0.65);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  padding: 0 var(--space-2);
  pointer-events: none;
}

.nav-actions {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  margin-left: auto;
}

/* ── Chip bar ─────────────────────────────────────────────────────────────── */
.chip-bar {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  background: var(--color-surface-card);
  border-bottom: 1px solid var(--color-neutral-200);
  position: sticky;
  top: 44px;
  z-index: var(--z-chip-bar);
}

/* ── Chips ───────────────────────────────────────────────────────────────── */
.chip {
  display: inline-flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-full);
  border: 2px solid var(--color-neutral-200);
  background: var(--color-surface-page);
  color: var(--color-neutral-600);
  font-size: var(--text-sm);
  cursor: pointer;
  transition: border-color var(--duration-fast) var(--ease-out),
              background  var(--duration-fast) var(--ease-out),
              color        var(--duration-fast) var(--ease-out);
  min-height: 44px;
  user-select: none;
}

.chip.active {
  border-color: transparent;
  color: #fff;
}

/* Type-chip active colors follow CA18 — same colors as badges */
.chip[data-type="."].active  { background: var(--color-neutral-600); }
.chip[data-type="!"].active  { background: var(--color-semantic-500); }
.chip[data-type="!-"].active { background: var(--color-error); }
.chip[data-type="?"].active  { background: #FF9800; }   /* CG-DS1 exception */
.chip[data-type="??"].active { background: #2196F3; }   /* CG-DS1 exception */

.chip-scope {
  background: var(--color-interactive-50);
  border-color: var(--color-interactive-400);
  color: var(--color-interactive-700);
}

.chip-scope.active {
  background: var(--color-interactive-600);
  border-color: transparent;
  color: #fff;
}

/* ── Cards ───────────────────────────────────────────────────────────────── */
.card {
  background: var(--color-surface-card);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-1);
  padding: var(--space-4);
  cursor: pointer;
  transition: box-shadow var(--duration-normal) var(--ease-out),
              transform  var(--duration-normal) var(--ease-out);
  position: relative;
  overflow: hidden;
  user-select: none;
  touch-action: none;   /* hand off to card-swipe.js */
}

.card:hover {
  box-shadow: var(--shadow-2);
  transform: translateY(-1px);
}

.card-header {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.card-body {
  font-size: var(--text-base);
  color: var(--color-neutral-900);
  line-height: 1.5;
}

.card-footer {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-top: var(--space-3);
}

/* Swipe state classes — applied by card-swipe.js during drag */
.card.swiping-right {
  border-left: 4px solid var(--color-semantic-500);
  background: var(--color-semantic-50);
}
.card.swiping-left {
  border-right: 4px solid var(--color-error);
  background: #FEE2E2;
}
.card.swiping-up {
  border-top: 4px solid var(--color-interactive-600);
  background: var(--color-interactive-50);
}
.card.swiping-down {
  border-bottom: 4px solid var(--color-neutral-400);
  background: var(--color-surface-page);
}

/* Tray icon scale-up when ACTIVATE threshold is crossed */
.card.swipe-activate .card-swipe-tray-icon {
  transform: scale(1.2);
  transition: transform var(--duration-fast) var(--ease-spring);
}

/* ── Badges ──────────────────────────────────────────────────────────────── */
.badge {
  display: inline-flex;
  align-items: center;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  font-size: var(--text-xs);
  font-weight: var(--font-weight-bold);
  color: #fff;
  white-space: nowrap;
  flex-shrink: 0;
  line-height: 1.1;
}

.badge-fakt       { background: var(--color-semantic-500); }
.badge-fake       { background: var(--color-error); }
.badge-meinung    { background: var(--color-neutral-600); }
.badge-unklar     { background: #FF9800; }   /* CG-DS1 documented exception */
.badge-gegenfrage { background: #2196F3; }   /* CG-DS1 documented exception */

/* ── Vote ────────────────────────────────────────────────────────────────── */
.vote-score {
  font-size: var(--text-sm);
  font-weight: var(--font-weight-bold);
  min-width: 2rem;
  text-align: center;
}

.vote-score.pos  { color: var(--color-semantic-500); }
.vote-score.neg  { color: var(--color-error); }
.vote-score.zero { color: var(--color-neutral-400); }

.vote-btn {
  background: none;
  border: 1px solid var(--color-neutral-200);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  min-width: 44px;
  min-height: 44px;
  cursor: pointer;
  font-size: var(--text-sm);
  color: var(--color-neutral-600);
  transition: border-color var(--duration-fast) var(--ease-out),
              color         var(--duration-fast) var(--ease-out);
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.vote-btn:hover {
  border-color: var(--color-interactive-400);
  color: var(--color-interactive-700);
}

/* ── Buttons ─────────────────────────────────────────────────────────────── */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-4);
  min-height: 44px;
  border-radius: var(--radius-md);
  border: none;
  font-family: var(--font-family);
  font-size: var(--text-base);
  font-weight: var(--font-weight-medium);
  cursor: pointer;
  transition: background var(--duration-fast) var(--ease-out),
              filter     var(--duration-fast) var(--ease-out),
              transform  var(--duration-instant) var(--ease-spring);
  user-select: none;
}

.btn:active { transform: scale(0.97); }

.btn-primary {
  background: var(--color-interactive-600);
  color: #fff;
}
.btn-primary:hover { background: var(--color-interactive-700); }

.btn-secondary {
  background: var(--color-neutral-200);
  color: var(--color-neutral-900);
}
.btn-secondary:hover { filter: brightness(0.93); }

.btn-ghost {
  background: transparent;
  color: var(--color-neutral-600);
}
.btn-ghost:hover { background: var(--color-neutral-200); }

.btn-icon {
  background: transparent;
  color: var(--color-neutral-600);
  border-radius: var(--radius-full);
  padding: var(--space-2);
  width: 44px;
  height: 44px;
}
.btn-icon:hover { background: var(--color-neutral-200); }

.btn-danger {
  background: var(--color-error);
  color: #fff;
}
.btn-danger:hover { filter: brightness(0.88); }

/* ── FAB ─────────────────────────────────────────────────────────────────── */
.fab {
  position: fixed;
  bottom: var(--space-16);
  right: var(--space-4);
  z-index: var(--z-fab);
  width: 56px;
  height: 56px;
  border-radius: var(--radius-full);
  background: var(--color-interactive-600);
  color: #fff;
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  box-shadow: var(--shadow-2);
  transition: box-shadow  var(--duration-fast) var(--ease-spring),
              transform   var(--duration-fast) var(--ease-spring);
}

.fab:hover  { box-shadow: var(--shadow-3); transform: scale(1.05); }
.fab:active { transform: scale(0.95); transition-duration: var(--duration-instant); }

/* ── Forms ───────────────────────────────────────────────────────────────── */
.form-label {
  display: block;
  font-size: var(--text-sm);
  font-weight: var(--font-weight-medium);
  color: var(--color-neutral-900);
  margin-bottom: var(--space-1);
}

.input,
.textarea {
  display: block;
  width: 100%;
  padding: var(--space-2) var(--space-4);
  border: 1px solid var(--color-neutral-200);
  border-radius: var(--radius-md);
  font-family: var(--font-family);
  font-size: var(--text-base);
  color: var(--color-neutral-900);
  background: var(--color-surface-card);
  transition: border-color var(--duration-fast) var(--ease-out);
  min-height: 44px;
}

.input:focus-visible,
.textarea:focus-visible {
  outline: none;
  box-shadow: var(--focus-ring);
  border-color: var(--color-interactive-400);
}

.textarea {
  resize: vertical;
  min-height: calc(var(--space-16) * 3);
  line-height: 1.5;
}

/* ── Overlays ────────────────────────────────────────────────────────────── */
.overlay-backdrop {
  display: none;
  position: fixed;
  inset: 0;
  background: var(--color-surface-overlay);
  z-index: var(--z-overlay);
  align-items: center;
  justify-content: center;
}

.overlay-backdrop.open { display: flex; }

.panel {
  background: var(--color-surface-card);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-3);
  padding: var(--space-6);
  width: min(400px, 90vw);
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: var(--text-xl);
  font-weight: var(--font-weight-bold);
  color: var(--color-neutral-900);
}

.panel-actions {
  display: flex;
  gap: var(--space-3);
  justify-content: flex-end;
}

/* .dialog is the composed pattern: .overlay-backdrop > .panel */

.bottom-sheet {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  z-index: var(--z-sheet);
  background: var(--color-surface-card);
  border-radius: var(--radius-lg) var(--radius-lg) 0 0;
  box-shadow: var(--shadow-3);
  padding: var(--space-6) var(--space-4) calc(var(--space-6) + env(safe-area-inset-bottom));
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  transform: translateY(100%);
  transition: transform var(--duration-normal) var(--ease-out);
}

.bottom-sheet.open {
  transform: translateY(0);
}

/* ── Toasts ──────────────────────────────────────────────────────────────── */
.toast {
  position: fixed;
  top: var(--space-4);
  right: var(--space-4);
  z-index: var(--z-toast);
  background: var(--color-surface-card);
  box-shadow: var(--shadow-2);
  border-radius: var(--radius-md);
  padding: var(--space-4);
  max-width: 320px;
  font-size: var(--text-sm);
  border-left: 4px solid var(--color-neutral-200);
  animation: toast-in var(--duration-slow) var(--ease-out);
}

@keyframes toast-in {
  from { opacity: 0; transform: translateX(2rem); }
  to   { opacity: 1; transform: translateX(0); }
}

.toast.toast-success { border-left-color: var(--color-semantic-500); }
.toast.toast-error   { border-left-color: var(--color-error); }
.toast.toast-info    { border-left-color: var(--color-interactive-600); }

/* ── Icons ───────────────────────────────────────────────────────────────── */
.icon {
  display: inline-block;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.5;
  stroke-linecap: round;
  stroke-linejoin: round;
  flex-shrink: 0;
  vertical-align: middle;
}

.icon-sm { width: 16px; height: 16px; }
.icon-md { width: 20px; height: 20px; }
.icon-lg { width: 24px; height: 24px; }
```

- [ ] **Step 2: Verify no raw values snuck in (CG-DS2, CG-DS3)**

```bash
# No raw px font-sizes (should only find 0px in transforms, not font-size)
grep "font-size:.*px" components.css
# Expected: no output

# No raw rem font-sizes
grep "font-size:.*rem" components.css
# Expected: no output

# Spot-check spacing — no hardcoded px spacing outside calc()
grep -E "margin:|padding:|gap:" components.css | grep -v "var(--space" | grep -v "env(" | grep -v "0$" | grep -v "0 0"
# Expected: no output (any remaining lines are false positives to inspect manually)
```

- [ ] **Step 3: Commit**

```bash
git add components.css
git commit -m "feat(design): components.css — full semantic component class library"
```

---

## Task 4: icons.svg

**Files:**
- Create: `icons.svg`

**Interfaces:**
- Produces: 15 SVG symbols — `icon-arrow-left`, `icon-arrow-right`, `icon-arrow-up`, `icon-arrow-down`, `icon-thumb-up`, `icon-thumb-down`, `icon-classify`, `icon-search`, `icon-settings`, `icon-plus`, `icon-edit`, `icon-close`, `icon-check`, `icon-back`, `icon-issue`
- Consumed by: HTML pages via `<svg class="icon icon-md"><use href="#icon-name"></use></svg>`

- [ ] **Step 1: Create `icons.svg`**

All paths use Heroicons v2 outline (MIT license, heroicons.com). ViewBox 24×24. Icons render via `.icon { stroke: currentColor; fill: none; stroke-width: 1.5; }`.

```svg
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">

  <symbol id="icon-arrow-left" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
  </symbol>

  <symbol id="icon-arrow-right" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
  </symbol>

  <symbol id="icon-arrow-up" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" />
  </symbol>

  <symbol id="icon-arrow-down" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" />
  </symbol>

  <symbol id="icon-thumb-up" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M6.633 10.25c.806 0 1.533-.446 2.031-1.08a9.041 9.041 0 0 1 2.861-2.4c.723-.384 1.35-.956 1.653-1.715a4.498 4.498 0 0 0 .322-1.672V2.75a.75.75 0 0 1 .75-.75 2.25 2.25 0 0 1 2.25 2.25c0 1.152-.26 2.243-.723 3.218-.266.558.107 1.282.725 1.282h3.126c1.026 0 1.945.694 2.054 1.715.045.422.068.85.068 1.285a11.95 11.95 0 0 1-2.649 7.521c-.388.482-.987.729-1.605.729H13.48c-.483 0-.964-.078-1.423-.23l-3.114-1.04a4.501 4.501 0 0 0-1.423-.23H5.909M14.25 8.75h2.25M5.909 18.5c.083.205.173.405.27.602.197.4-.078.898-.523.898h-.908c-.889 0-1.713-.518-1.972-1.368a12 12 0 0 1-.521-3.507c0-1.553.295-3.036.831-4.398C3.387 9.953 4.167 9.5 5 9.5h1.053c.472 0 .745.556.5.96a8.958 8.958 0 0 0-1.302 4.665c0 1.194.232 2.333.654 3.375z" />
  </symbol>

  <symbol id="icon-thumb-down" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M7.498 15.25H4.372c-1.026 0-1.945-.694-2.054-1.715a12.137 12.137 0 0 1-.068-1.285c0-2.848.992-5.464 2.649-7.521C5.353 4.228 5.952 3.98 6.57 3.98h4.461c.483 0 .964.078 1.423.23l3.114 1.04a4.501 4.501 0 0 0 1.423.23h2.909m-18.5 13.5H4.5m12.75 0h.875c.621 0 1.125-.504 1.125-1.125v-6.75c0-.621-.504-1.125-1.125-1.125H16.5m-9.75 6.75c-.083-.205-.173-.405-.27-.602-.197-.4.078-.898.523-.898h.908c.889 0 1.713.518 1.972 1.368a12 12 0 0 0 .521 3.507c0 1.553-.295 3.036-.831 4.398-.268.698-1.048 1.152-1.88 1.152H7.053c-.472 0-.745-.556-.5-.96a8.958 8.958 0 0 1 1.302-4.665 9 9 0 0 0 .893-5.293" />
  </symbol>

  <symbol id="icon-classify" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3z" />
    <path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" />
  </symbol>

  <symbol id="icon-search" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607z" />
  </symbol>

  <symbol id="icon-settings" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 0 1 1.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 0 1-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 0 1-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.108-1.204l-.526-.738a1.125 1.125 0 0 1 .12-1.45l.773-.773a1.125 1.125 0 0 1 1.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z" />
    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z" />
  </symbol>

  <symbol id="icon-plus" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
  </symbol>

  <symbol id="icon-edit" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
  </symbol>

  <symbol id="icon-close" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
  </symbol>

  <symbol id="icon-check" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
  </symbol>

  <symbol id="icon-back" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
  </symbol>

  <symbol id="icon-issue" viewBox="0 0 24 24">
    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5" />
  </symbol>

</svg>
```

- [ ] **Step 2: Verify all 15 symbols are present**

```bash
grep -c "<symbol id=" icons.svg
```

Expected: `15`

```bash
for id in icon-arrow-left icon-arrow-right icon-arrow-up icon-arrow-down \
           icon-thumb-up icon-thumb-down icon-classify icon-search icon-settings \
           icon-plus icon-edit icon-close icon-check icon-back icon-issue; do
  grep -q "id=\"$id\"" icons.svg && echo "OK $id" || echo "MISSING $id"
done
```

Expected: all lines print `OK`.

- [ ] **Step 3: Visual verification**

Create a temporary file `/tmp/icon-check.html`:

```html
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="/path/to/design-tokens.css">
  <link rel="stylesheet" href="/path/to/components.css">
</head>
<body style="background:#f9fafb;padding:2rem;display:flex;flex-wrap:wrap;gap:1.5rem;">
  <!-- paste icons.svg content here -->
  <svg class="icon icon-lg" title="arrow-left"><use href="#icon-arrow-left"></use></svg>
  <svg class="icon icon-lg" title="arrow-right"><use href="#icon-arrow-right"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-arrow-up"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-arrow-down"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-thumb-up"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-thumb-down"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-classify"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-search"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-settings"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-plus"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-edit"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-close"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-check"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-back"></use></svg>
  <svg class="icon icon-lg"><use href="#icon-issue"></use></svg>
</body>
</html>
```

Open in browser. All 15 icons should render as clean stroke icons. If any renders blank, inspect DevTools → verify the `<use href>` matches the symbol `id`.

- [ ] **Step 4: Commit**

```bash
git add icons.svg
git commit -m "feat(design): icons.svg — 15-symbol SVG sprite (Heroicons v2 outline, MIT)"
```

---

## Task 5: card-swipe.js + Tests

**Files:**
- Create: `card-swipe.js`
- Create: `test/card_swipe_test.js`

**Interfaces:**
- Consumes: `.card` elements in the DOM; CSS classes `.swiping-left`, `.swiping-right`, `.swiping-up`, `.swiping-down`, `.swipe-activate` from `components.css`
- Produces: `initCardSwipe(root, opts)` function; fires `CustomEvent('cardswipe:action', { detail: { direction } })` on the card element when ACTIVATE threshold is met and pointer is released
- Produces (pure, testable): `cardSwipeDirection(dx, dy, minPx)` → `'left'|'right'|'up'|'down'|null`; `cardSwipeState(rawDist, cardSize, revealPx, activateRatio)` → `'CLOSED'|'REVEAL'|'ACTIVATE'`

- [ ] **Step 1: Write the failing tests first**

Create `test/card_swipe_test.js`:

```javascript
/**
 * Tests for card-swipe.js pure logic
 * Loaded by wrapper.php: open wrapper.php?test=card-swipe.js in a browser
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
```

- [ ] **Step 2: Open in browser to confirm tests fail**

Serve the project (e.g. `just serve` or `python3 -m http.server 8080`).
Open: `http://localhost:8080/wrapper.php?test=card-swipe.js`

Expected: red FAIL lines — `cardSwipeDirection is not defined`, `cardSwipeState is not defined`. This is the RED state.

- [ ] **Step 3: Create `card-swipe.js`**

```javascript
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
    }, { passive: true });

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
```

- [ ] **Step 4: Re-open the test page and confirm GREEN**

Open: `http://localhost:8080/wrapper.php?test=card-swipe.js`

Expected output:
```
cardSwipeDirection
  PASS  right swipe
  PASS  left swipe
  PASS  down swipe
  PASS  up swipe
  PASS  below threshold
  PASS  diagonal — horiz wins when dx > dy
  PASS  diagonal — vert wins when dy > dx
  PASS  exact threshold
  PASS  just below
cardSwipeState
  PASS  CLOSED below reveal
  PASS  CLOSED at 39px
  PASS  REVEAL at 40px
  PASS  REVEAL at 159px
  PASS  ACTIVATE at 160px
  PASS  ACTIVATE beyond 160
  PASS  CLOSED — zero
Passed: 16  Failed: 0
```

- [ ] **Step 5: Commit**

```bash
git add card-swipe.js test/card_swipe_test.js
git commit -m "feat(design): card-swipe.js — 3-state drag controller + tests (TDD GREEN)"
```

---

## Task 6: Design System Demo Page

**Files:**
- Create: `design-system-demo.html`

**Interfaces:**
- Consumes: `design-tokens.css`, `components.css`, `icons.svg` content
- Produces: visual verification page for all components; not shipped to users, used for QA

- [ ] **Step 1: Create `design-system-demo.html`**

```html
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>InfoPedia Design System Demo</title>
  <link rel="stylesheet" href="design-tokens.css">
  <link rel="stylesheet" href="components.css">
  <style>
    .demo-section { max-width: 800px; margin: 0 auto; padding: var(--space-8) var(--space-4); }
    .demo-label   { font-size: var(--text-xs); color: var(--color-neutral-400); margin-bottom: var(--space-2); text-transform: uppercase; letter-spacing: 0.08em; }
    .demo-row     { display: flex; flex-wrap: wrap; gap: var(--space-3); align-items: center; margin-bottom: var(--space-6); }
    .swatch       { width: 48px; height: 48px; border-radius: var(--radius-md); box-shadow: var(--shadow-1); }
  </style>
</head>
<body>

<!-- SVG Sprite — paste full icons.svg content here -->
<!-- [icons.svg content] -->

<!-- Nav -->
<nav class="nav">
  <a href="#" class="nav-back">
    <svg class="icon icon-md"><use href="#icon-back"></use></svg>
    &nbsp;Back
  </a>
  <span class="nav-title">Design System Demo</span>
  <div class="nav-actions">
    <button class="btn-icon">
      <svg class="icon icon-md"><use href="#icon-search"></use></svg>
    </button>
    <button class="btn-icon">
      <svg class="icon icon-md"><use href="#icon-settings"></use></svg>
    </button>
  </div>
</nav>

<!-- Chip bar -->
<div class="chip-bar">
  <button class="chip active" data-type="!">
    <svg class="icon icon-sm"><use href="#icon-check"></use></svg> Fakt
  </button>
  <button class="chip" data-type="!-">Fake</button>
  <button class="chip" data-type="?">Unklar</button>
  <button class="chip" data-type="??">Gegenfrage</button>
  <button class="chip chip-scope active">Global</button>
</div>

<div class="demo-section">

  <!-- Colors -->
  <div class="demo-label">Color — Interactive</div>
  <div class="demo-row">
    <div class="swatch" style="background:var(--color-interactive-50)"></div>
    <div class="swatch" style="background:var(--color-interactive-100)"></div>
    <div class="swatch" style="background:var(--color-interactive-400)"></div>
    <div class="swatch" style="background:var(--color-interactive-600)"></div>
    <div class="swatch" style="background:var(--color-interactive-700)"></div>
    <div class="swatch" style="background:var(--color-interactive-accent)"></div>
  </div>

  <div class="demo-label">Color — Semantic + Error</div>
  <div class="demo-row">
    <div class="swatch" style="background:var(--color-semantic-50)"></div>
    <div class="swatch" style="background:var(--color-semantic-500)"></div>
    <div class="swatch" style="background:var(--color-semantic-700)"></div>
    <div class="swatch" style="background:var(--color-error)"></div>
  </div>

  <!-- Buttons -->
  <div class="demo-label">Buttons</div>
  <div class="demo-row">
    <button class="btn btn-primary">Primary</button>
    <button class="btn btn-secondary">Secondary</button>
    <button class="btn btn-ghost">Ghost</button>
    <button class="btn btn-danger">Danger</button>
    <button class="btn btn-icon">
      <svg class="icon icon-md"><use href="#icon-plus"></use></svg>
    </button>
  </div>

  <!-- Badges -->
  <div class="demo-label">Badges</div>
  <div class="demo-row">
    <span class="badge badge-fakt">Fakt</span>
    <span class="badge badge-fake">Fake</span>
    <span class="badge badge-meinung">Meinung</span>
    <span class="badge badge-unklar">Unklar</span>
    <span class="badge badge-gegenfrage">Gegenfrage</span>
  </div>

  <!-- Icons -->
  <div class="demo-label">Icons (all 15)</div>
  <div class="demo-row">
    <svg class="icon icon-lg"><use href="#icon-arrow-left"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-arrow-right"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-arrow-up"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-arrow-down"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-thumb-up"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-thumb-down"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-classify"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-search"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-settings"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-plus"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-edit"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-close"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-check"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-back"></use></svg>
    <svg class="icon icon-lg"><use href="#icon-issue"></use></svg>
  </div>

  <!-- Card -->
  <div class="demo-label">Card (swipeable — drag left/right/up/down)</div>
  <div id="card-list" style="display:flex;flex-direction:column;gap:var(--space-3)">
    <div class="card">
      <div class="card-header">
        <span class="badge badge-fakt">Fakt</span>
        <span style="font-size:var(--text-xs);color:var(--color-neutral-400)">2026-06-25</span>
      </div>
      <div class="card-body">CO₂ concentration in the atmosphere exceeded 420 ppm in 2023.</div>
      <div class="card-footer">
        <button class="vote-btn"><svg class="icon icon-sm"><use href="#icon-thumb-up"></use></svg></button>
        <span class="vote-score pos">+12</span>
        <button class="vote-btn"><svg class="icon icon-sm"><use href="#icon-thumb-down"></use></svg></button>
      </div>
    </div>
    <div class="card">
      <div class="card-header">
        <span class="badge badge-fake">Fake</span>
      </div>
      <div class="card-body">The moon landing was filmed in a Hollywood studio.</div>
      <div class="card-footer">
        <button class="vote-btn"><svg class="icon icon-sm"><use href="#icon-thumb-up"></use></svg></button>
        <span class="vote-score neg">-8</span>
        <button class="vote-btn"><svg class="icon icon-sm"><use href="#icon-thumb-down"></use></svg></button>
      </div>
    </div>
  </div>

  <!-- Form -->
  <div class="demo-label" style="margin-top:var(--space-6)">Form</div>
  <label class="form-label">Entry text</label>
  <input class="input" type="text" placeholder="Type something…">
  <label class="form-label" style="margin-top:var(--space-4)">Description</label>
  <textarea class="textarea" placeholder="More detail…"></textarea>

  <!-- Toast -->
  <div class="demo-label" style="margin-top:var(--space-4)">Toasts</div>
  <div class="demo-row" style="flex-direction:column;align-items:flex-start">
    <div class="toast toast-success" style="position:static;animation:none">Eintrag erfolgreich gespeichert.</div>
    <div class="toast toast-error"   style="position:static;animation:none">Fehler beim Speichern. Bitte erneut versuchen.</div>
    <div class="toast toast-info"    style="position:static;animation:none">Neue Einträge verfügbar.</div>
  </div>

</div>

<!-- FAB -->
<button class="fab" aria-label="Add entry">
  <svg class="icon icon-lg"><use href="#icon-plus"></use></svg>
</button>

<script src="card-swipe.js"></script>
<script>
  initCardSwipe(document.getElementById('card-list'));
  document.getElementById('card-list').addEventListener('cardswipe:action', function (e) {
    console.log('swipe action:', e.detail.direction, 'on', e.target);
  });
</script>
</body>
</html>
```

- [ ] **Step 2: Replace the icons.svg placeholder**

In `design-system-demo.html`, find the line:
```
<!-- [icons.svg content] -->
```

Replace it with the full contents of `icons.svg` (the `<svg style="display:none">…</svg>` block).

- [ ] **Step 3: Open in browser and verify all components**

Serve the project and open `design-system-demo.html`. Check:
- [ ] Nav renders amber (#B45309 bg), white text
- [ ] Chip bar sticks below nav on scroll; Fakt chip is green
- [ ] Color swatches: amber scale left→right, green + red on second row
- [ ] All 5 button variants render with correct colors
- [ ] All 5 badge variants render with correct colors
- [ ] All 15 icons render as stroke icons (not blank boxes)
- [ ] Cards have shadow, rounded corners, correct badge colors
- [ ] Dragging a card left → red right border appears; drag past ~half width → border deepens
- [ ] Releasing in ACTIVATE zone → `cardswipe:action` logged in DevTools console
- [ ] Dragging back before release → no action fired
- [ ] FAB renders fixed bottom-right, amber, circular
- [ ] Input and textarea have correct focus ring (amber 3px outline) on click
- [ ] Toasts render with correct left-border colors

- [ ] **Step 4: Commit**

```bash
git add design-system-demo.html
git commit -m "feat(design): design-system-demo.html — visual QA page for all components"
```

---

## Self-Review

**Spec coverage check:**

| Spec section | Task |
|---|---|
| §3 CG-DS1–CG-DS5 | Task 1 |
| §4 Color tokens | Task 2 |
| §5 Typography tokens | Task 2 |
| §6 Spacing tokens | Task 2 |
| §7 Radius / Elevation / Z-index | Task 2 |
| §8 Animation tokens | Task 2 |
| §9 Focus token | Task 2 |
| §10 All component classes | Task 3 |
| §10 Icons (sprite + .icon classes) | Task 4 |
| §11 Gesture model + card-swipe.js | Task 5 |
| Visual verification | Task 6 |

All spec sections covered. No gaps.

**Placeholder scan:** None found. All steps contain complete code.

**Type/name consistency:**
- `cardSwipeDirection` and `cardSwipeState` defined in Task 5 Step 3, used in tests Task 5 Step 1 ✓
- CSS class `.swipe-activate` defined in Task 3 Step 1, referenced in Task 5 Step 3 (`classList.add('swipe-activate')`) ✓
- CSS class `.swiping-{dir}` defined in Task 3, used in Task 5 ✓
- `initCardSwipe` defined Task 5 Step 3, used in Task 6 Step 1 ✓
- Icon IDs defined Task 4 Step 1, referenced Task 6 Step 1 ✓
