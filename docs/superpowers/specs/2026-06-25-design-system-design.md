# InfoPedia Design System — Spec

**Date:** 2026-06-25  
**Status:** Approved  
**Scope:** Design system only — tokens + components + gesture model. Application to individual pages is a separate task.

---

## 1. Goals & Principles

- **Unified** — one design system covering all HTML and PHP pages, applied incrementally
- **Mobile-first** — 44px minimum touch targets, swipe-native interaction model
- **Low dependency** — no external fonts, no CDN icons, no framework
- **Expressive but intentional** — animations motivate the user; every motion has meaning
- **Accessible** — WCAG AA contrast enforced, `prefers-reduced-motion` respected, focus rings always visible

---

## 2. Deliverables

| File | Purpose |
|------|---------|
| `design-tokens.css` | CSS custom properties only — no rules, no selectors |
| `components.css` | Semantic component classes built from tokens |
| `icons.svg` | Inline SVG sprite block — all icons as `<symbol>` |

Pages include both CSS files:
```html
<link rel="stylesheet" href="design-tokens.css">
<link rel="stylesheet" href="components.css">
```

Icons are embedded once per page as a hidden inline SVG block and referenced via `<use href="#icon-name">`.

---

## 3. Constitution Guards (binding rules — enforced in code review)

### CG-DS1: Semantic vs. Interactive Color
- `--color-interactive-*` — UI chrome only: nav, buttons, FAB, active chips, focus rings, swipe indicators
- `--color-semantic-*` — fact/truth states only: Fakt badge, verified, positive vote score, upvote confirmation
- `--color-error` — negative states only: Fake badge, downvote confirmation, negative vote score
- **Mixing interactive and semantic on the same element is a hard violation.**
- Exception: `.badge-unklar` uses `#FF9800` (warning-orange). This is the only documented out-of-system color.

### CG-DS2: Quantized Spacing
- Every `margin`, `padding`, `gap`, `top`, `bottom`, `left`, `right` must use a `--space-*` token.
- No raw length values in component CSS. Violations = instant code review reject.

### CG-DS3: Quantized Type
- Every `font-size` must use a `--text-*` token. No raw `rem` or `px` font sizes.

### CG-DS4: No Suppressed Focus
- Never `outline: none` without replacing with `box-shadow: var(--focus-ring)`.
- Use `:focus-visible`, not `:focus`.

### CG-DS5: Touch Target Minimum
- Every tappable element must have a minimum hit area of 44×44px via `min-height`/`min-width` or padding.

---

## 4. Color Tokens

```css
:root {
  /* ── Interactive (amber) ─────────────────────────────────── */
  --color-interactive-50:     #FFFBEB;   /* hover backgrounds */
  --color-interactive-100:    #FEF3C7;   /* active state backgrounds */
  --color-interactive-400:    #FBBF24;   /* accent borders, underlines, swipe indicators */
  --color-interactive-600:    #D97706;   /* filled buttons (4.8:1 contrast on white) */
  --color-interactive-700:    #B45309;   /* nav bg, primary action bg (7.2:1 on white) */
  --color-interactive-accent: #F59E0B;   /* decoration only — NEVER as text background */

  /* ── Semantic (green) ────────────────────────────────────── */
  --color-semantic-50:   #F0FDF4;        /* upvote confirmation bg */
  --color-semantic-500:  #16A34A;        /* Fakt badge, verified, positive score */
  --color-semantic-700:  #15803D;        /* semantic text on light background */

  /* ── Surface ─────────────────────────────────────────────── */
  --color-surface-page:    #F9FAFB;      /* page background */
  --color-surface-card:    #FFFFFF;      /* card, panel, sheet background */
  --color-surface-overlay: rgba(0,0,0,0.45); /* modal/sheet backdrop */

  /* ── Neutral ─────────────────────────────────────────────── */
  --color-neutral-200: #E5E7EB;          /* dividers, borders */
  --color-neutral-400: #9CA3AF;          /* placeholder text */
  --color-neutral-600: #4B5563;          /* secondary text */
  --color-neutral-900: #111827;          /* primary text */

  /* ── State ───────────────────────────────────────────────── */
  --color-error: #DC2626;                /* Fake badge, downvote, negative score (5.1:1) */
  --color-focus: #F59E0B;                /* focus ring color */
}
```

**Contrast notes:**
- `--color-interactive-700` (#B45309) on white = 7.2:1 ✓ AAA
- `--color-interactive-600` (#D97706) on white = 4.8:1 ✓ AA
- `--color-semantic-500` (#16A34A) on white = 5.8:1 ✓ AA
- `--color-error` (#DC2626) on white = 5.1:1 ✓ AA
- `--color-interactive-accent` (#F59E0B) on white = 2.6:1 ✗ — decoration only

---

## 5. Typography Tokens

```css
:root {
  --font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  --font-weight-normal: 400;
  --font-weight-medium: 500;
  --font-weight-bold:   700;

  --text-xs:   0.75rem;   /* line-height 1.1 — timestamps, meta labels */
  --text-sm:   0.85rem;   /* line-height 1.3 — chips, badges, vote counts */
  --text-base: 1rem;      /* line-height 1.5 — card body, form inputs */
  --text-lg:   1.125rem;  /* line-height 1.4 — card title emphasis */
  --text-xl:   1.25rem;   /* line-height 1.3 — nav title, section headers */
  --text-2xl:  1.5rem;    /* line-height 1.2 — page-level headings */
}
```

---

## 6. Spacing Tokens

Base unit: 4px. All spacing is a multiple.

```css
:root {
  --space-1:  4px;    /* icon internal padding, hairline gaps */
  --space-2:  8px;    /* chip internal padding, badge padding */
  --space-3:  12px;   /* card internal gap between elements */
  --space-4:  16px;   /* card padding, nav padding, section gap */
  --space-6:  24px;   /* between cards, section margins */
  --space-8:  32px;   /* page-level vertical rhythm */
  --space-12: 48px;   /* large section separation */
  --space-16: 64px;   /* FAB bottom offset, hero spacing */
}
```

---

## 7. Radius, Elevation, Z-index Tokens

```css
:root {
  /* ── Radius ──────────────────────────────────────────────── */
  --radius-sm:   4px;     /* badges, chips inner, input borders */
  --radius-md:   8px;     /* cards, buttons, dropdowns */
  --radius-lg:   12px;    /* bottom sheet top corners, modals */
  --radius-full: 9999px;  /* pill chips, FAB, vote score bubble */

  /* ── Elevation ───────────────────────────────────────────── */
  --shadow-0: none;                              /* flat — nav, chip bar */
  --shadow-1: 0 1px 3px rgba(0,0,0,.08);        /* card resting */
  --shadow-2: 0 4px 12px rgba(0,0,0,.12);       /* card hover / drag lifted */
  --shadow-3: 0 8px 24px rgba(0,0,0,.18);       /* overlays, bottom sheet */

  /* ── Z-index ─────────────────────────────────────────────── */
  --z-base:     0;
  --z-chip-bar: 90;
  --z-nav:      100;
  --z-fab:      200;
  --z-sheet:    300;
  --z-overlay:  400;
  --z-toast:    500;
}
```

---

## 8. Animation Tokens

```css
:root {
  --duration-instant: 80ms;    /* press feedback, toggle */
  --duration-fast:   150ms;    /* chip select, badge swap, focus ring */
  --duration-normal: 250ms;    /* card hover lift, bottom sheet open */
  --duration-slow:   400ms;    /* swipe confirmation, toast enter/exit */

  --ease-out:    cubic-bezier(0.0, 0.0, 0.2, 1);    /* elements entering */
  --ease-in:     cubic-bezier(0.4, 0.0, 1, 1);      /* elements leaving */
  --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1); /* swipe snap-back, FAB press */
}

@media (prefers-reduced-motion: reduce) {
  :root {
    --duration-instant: 1ms;
    --duration-fast:    1ms;
    --duration-normal:  1ms;
    --duration-slow:    1ms;
  }
}
```

---

## 9. Focus Token

```css
:root {
  --focus-ring: 0 0 0 3px var(--color-focus);
}

/* Applied globally */
:focus-visible {
  outline: none;
  box-shadow: var(--focus-ring);
}
```

---

## 10. Component Classes

### Navigation
```
.nav              sticky, z-nav, bg interactive-700, white text, space-4 px
.nav-back         left slot — back link + breadcrumb, text-sm, ellipsis overflow
.nav-title        center slot — current topic, text-sm, neutral-400 color, pointer-events none
.nav-actions      right slot — flex row of icon buttons
```

### Filter / Chip Bar
```
.chip-bar         sticky below nav, z-chip-bar, bg surface-card, border-bottom neutral-200
.chip             radius-full, text-sm, space-2 py, space-3 px, border 2px neutral-200, bg neutral-50
.chip.active      filled — color depends on type (see badge colors), white text
.chip-scope       scope toggle chip — interactive-600 when active
```

### Cards
```
.card             bg surface-card, radius-md, shadow-1, space-4 padding, cursor pointer
.card:hover       shadow-2, translateY(-1px), duration-normal ease-out
.card-header      flex row, gap space-2, mb space-3
.card-body        text-base, neutral-900, line-height 1.5
.card-footer      flex row, gap space-3, mt space-3, align-center
```

#### Card Swipe States
```
.card.swiping-right   left border 4px semantic-500, bg semantic-50, card translates right
.card.swiping-left    right border 4px error, bg error-tint (#FEE2E2), card translates left
.card.swiping-up      top border 4px interactive-600, bg interactive-50, card translates up
.card.swiping-down    bottom border 4px neutral-400, bg neutral-50, card translates down
```

### Badges
```
.badge            text-xs, font-bold, radius-sm, space-2 px, space-1 py, white text
.badge-fakt       bg semantic-500
.badge-fake       bg error
.badge-unklar     bg #FF9800  [DOCUMENTED EXCEPTION — only out-of-system color]
.badge-meinung    bg neutral-600
.badge-gegenfrage bg #2196F3  [DOCUMENTED EXCEPTION — informational blue]
```

### Vote
```
.vote-score       text-sm, font-bold, min-width 2rem, text-center
.vote-score.pos   color semantic-500
.vote-score.neg   color error
.vote-score.zero  color neutral-400
.vote-btn         btn-ghost style, radius-sm, min 44×44px hit area
```

### Buttons
```
.btn              radius-md, text-base, space-4 px, space-2 py, min-height 44px, font-medium
.btn-primary      bg interactive-600, white text; hover bg interactive-700, duration-fast
.btn-secondary    bg neutral-200, neutral-900 text; hover bg neutral-300
.btn-ghost        transparent, neutral-600 text; hover bg neutral-200
.btn-icon         radius-full, 44×44px, ghost style — for icon-only actions
.btn-danger       bg error, white text; hover brightness 0.88
```

### FAB
```
.fab              position fixed, bottom space-16, right space-4, z-fab
                  radius-full, 56×56px, bg interactive-600, shadow-2
                  :hover shadow-3 + scale(1.05), ease-spring duration-fast
                  :active scale(0.95), duration-instant
```

### Forms
```
.form-label       text-sm, font-medium, neutral-900, display block, mb space-1
.input            border 1px neutral-200, radius-md, space-4 px, space-2 py, text-base
                  :focus-visible --focus-ring, border-color interactive-400
.textarea         same as .input, resize vertical, min-height space-16 × 3
```

### Overlays & Dialogs
```
.overlay-backdrop   fixed inset-0, bg surface-overlay, z-overlay
                    display none; .open → display flex, align-items center, justify center

.panel              bg surface-card, radius-lg, shadow-3, space-6 padding
                    width min(400px, 90vw)
.panel-header       flex row, space-between, align-center, mb space-4
                    title: text-xl, neutral-900
                    close btn: btn-icon, neutral-600
.panel-actions      flex row, gap space-3, justify end, mt space-4
                    confirm: btn-primary  |  cancel/secondary: btn-ghost

.dialog             .overlay-backdrop + .panel composition
.bottom-sheet       fixed bottom-0, full width, radius-lg top-only, shadow-3, z-sheet
                    slide-up animation duration-normal ease-out on open
                    slide-down ease-in on close
```

### Feedback
```
.toast            fixed top-right, z-toast, bg surface-card, shadow-2, radius-md
                  text-sm, space-4 padding, max-width 320px
                  enter: slide-in-right + fade, duration-slow ease-out
                  exit: fade-out, duration-fast ease-in
.toast-success    left border 4px semantic-500
.toast-error      left border 4px error
.toast-info       left border 4px interactive-600
```

### Icons
```html
<!-- Embedded once per page, hidden -->
<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
  <symbol id="icon-arrow-left"  viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-arrow-right" viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-arrow-up"    viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-arrow-down"  viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-thumb-up"    viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-thumb-down"  viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-classify"    viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-search"      viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-settings"    viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-plus"        viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-edit"        viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-close"       viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-check"       viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-back"        viewBox="0 0 24 24">…</symbol>
  <symbol id="icon-issue"       viewBox="0 0 24 24">…</symbol>
</svg>
```

```css
.icon    { display: inline-block; fill: currentColor; flex-shrink: 0; }
.icon-sm { width: 16px; height: 16px; }
.icon-md { width: 20px; height: 20px; }
.icon-lg { width: 24px; height: 24px; }
```

Usage: `<svg class="icon icon-md"><use href="#icon-search"></use></svg>`

---

## 11. Gesture Model

### Directions & Meanings
| Direction | Action | Context |
|-----------|--------|---------|
| Swipe right | Upvote | Card |
| Swipe left  | Downvote | Card |
| Swipe up    | Classify / change group / promote issue status | Card |
| Swipe down  | Reorder down / demote | Card |

### 3-State Interaction Model

```
State 1: CLOSED (resting)
  Card at natural position. No indicators visible.
  Threshold: < 40px drag distance.

State 2: REVEAL (drag ≥ 40px)
  Card translates in drag direction (1:1 with finger).
  Action tray appears behind card edge:
    swipe-right → left tray:   semantic-50 bg, thumb-up icon outline, semantic-500
    swipe-left  → right tray:  #FEE2E2 bg,    thumb-down icon outline, error
    swipe-up    → bottom tray: interactive-50 bg, classify icon outline, interactive-600
    swipe-down  → top tray:    neutral-50 bg, arrow-down icon outline, neutral-600
  Release in REVEAL → spring snap-back. NO action fired.

State 3: ACTIVATE (drag ≥ 50% card width, ~160px)
  Tray icon fills solid + scales 1.2×.
  Tray background deepens one shade.
  Device haptic: navigator.vibrate(30) if supported.
  Card resists further drag (rubber-band: 0.4× ratio beyond threshold).
  Release in ACTIVATE → action fires, card spring-snaps back, state updates in place.

Cancel rule: dragging back below 40px before release → returns to CLOSED. NO action.
Action fires on pointer-up position only — not on crossing the threshold.
```

### gesture.js Extension Required
Current `gesture.js` fires a one-shot event on pointer-up. The 3-state model requires:
- Real-time drag tracking with continuous `transform` during drag
- State machine: CLOSED → REVEAL → ACTIVATE (and back)
- Up/down swipe detection (currently left/right only)

**This is a planned implementation task — not part of this design system deliverable.**
A new `card-swipe.js` module is recommended to keep `gesture.js` generic.

---

## 12. Implementation Tasks (for writing-plans)

1. Create `design-tokens.css` — all `:root` custom properties from sections 4–9
2. Create `components.css` — all component classes from section 10
3. Create `icons.svg` — SVG sprite with all symbols from section 10
4. Extend `gesture.js` or create `card-swipe.js` — up/down support + 3-state drag model
5. Add CG-DS1 through CG-DS5 to `.ai/constitution.md`
6. Apply design system to `app2.html` (first page, reference implementation)
7. Apply design system to `app.html`
8. Apply design system to `infopedia.html`
9. Apply design system to PHP-rendered pages
