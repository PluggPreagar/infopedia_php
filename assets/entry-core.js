/**
 * assets/entry-core.js — pure entry helpers shared by app2.html and vote.html.
 * No DOM access, no fetch. Loaded as plain globals before each page's own script.
 * Moved out of app2.html on 2026-09-26 (CA7: share via assets, never copy-paste;
 * CA18: the type vocabulary is defined exactly once).
 */

// ── Key helpers ───────────────────────────────────────────────────────────────
function fullKey(topic, nodeId) {
    return topic + ("/" === topic ? "" : "/") + nodeId;
}
function splitKey(key) {
    const lastSlash = key.lastIndexOf("/");
    if (lastSlash <= 0) return ["/", key.replace(/^\//, "")];
    return [key.substring(0, lastSlash), key.substring(lastSlash + 1)];
}

// ── Type suffix ───────────────────────────────────────────────────────────────
// Order matters: two-char suffixes ("!-", "??", "--") must win over their one-char prefix.
function getTypeFromMessage(message) {
    for (const t of [">", "!-", "??", "!", "?", ".", "@", "--"]) {
        if (message && message.endsWith(t)) return t;
    }
    return "";
}
function matchType(message, type) {
    const current = getTypeFromMessage(message);
    if (current === type) return message;
    if (!current) return message + type;
    if (type === "--") return message + type;
    return message.slice(0, -current.length).trim() + type;
}

// ── Type vocabulary (CA18) ────────────────────────────────────────────────────
const TYPE_DEFS = {
    "!-": { label: "Fake",       cssClass: "fake",       color: "#f44336", iconClass: "fa-circle-xmark"    },
    "!":  { label: "Fakt",       cssClass: "fakt",       color: "#4CAF50", iconClass: "fa-circle-check"    },
    "??": { label: "Gegenfrage", cssClass: "gegenfrage", color: "#2196F3", iconClass: "fa-right-left"      },
    "?":  { label: "Unklar",     cssClass: "unklar",     color: "#FF9800", iconClass: "fa-circle-question" },
    ".":  { label: "Meinung",    cssClass: "meinung",    color: "#888",    iconClass: "fa-comment"         },
    "@":  { label: "Quelle",     cssClass: "meinung",    color: "#888",    iconClass: "fa-link"            },
    ">":  { label: "Thema",      cssClass: "fakt",       color: "#4CAF50", iconClass: "fa-folder-open"     },
};
const TYPE_DEF_DEFAULT = { label: "Eintrag", cssClass: "meinung", color: "#888", iconClass: "fa-circle-dot" };

function getTypeDef(message) {
    const suffix = getTypeFromMessage(message);
    const base = TYPE_DEFS[suffix] || TYPE_DEF_DEFAULT;
    return { ...base, suffix };
}

// ── HTML escaping ─────────────────────────────────────────────────────────────
function escapeHtml(s) {
    return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

// ── Debounce (per-key, ms window) ─────────────────────────────────────────────
const _debounceMap = {};
function debounceKey(key, ms) {
    const now = Date.now();
    if (_debounceMap[key] && now - _debounceMap[key] < ms) return false;
    _debounceMap[key] = now;
    return true;
}

// ── Node id ───────────────────────────────────────────────────────────────────
// Time-ordered prefix + 6 random chars. Callers that hold the entry map should still
// re-roll on a hit (vote.html freshNodeId) — free defense-in-depth.
function generateNodeId() {
    return Date.now().toString(36).substring(2) + Math.random().toString(36).substring(2, 8);
}
