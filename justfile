# InfoPedia PHP — task runner
# Install: https://just.systems  |  run: just <recipe>  |  list: just

php  := env_var_or_default("PHP",  "php")
port := env_var_or_default("PORT", "8080")
base := "http://localhost:" + port

# List all recipes
default:
    @just --list

# ── Test ─────────────────────────────────────────────────────────────────────

# Run unit tests (no server, pure functions)
unit:
    {{php}} test/run_all.php

# Run E2E tests (no server — uses PHP subprocesses)
e2e:
    {{php}} test/e2e.php

# E2E with full request/response trace
e2e-debug:
    {{php}} test/e2e.php --debug

# Run one specific unit test file
# usage: just test-file test/util_entry_test.php
test-file file:
    {{php}} {{file}}

# Full suite: unit + e2e  (CI entry point — exits non-zero on failure)
ci: unit e2e

# ── Serve ────────────────────────────────────────────────────────────────────

# Start PHP built-in server (Ctrl-C to stop)
serve:
    {{php}} -S localhost:{{port}} -t .

# ── Inspect (requires running server) ────────────────────────────────────────

# Fetch entries as JSON
entries tid="":
    curl -s "{{base}}/entries?format=json{{if tid != ""}}&tid={{tid}}{{endif}}"

# Fetch entries as txt.0.2  (one line per entry, human-readable)
collect tid="":
    curl -s "{{base}}/entries?format=txt.0.2{{if tid != ""}}&tid={{tid}}{{endif}}"

# Fetch votes as JSON
votes tid="":
    curl -s "{{base}}/votes?format=json{{if tid != ""}}&tid={{tid}}{{endif}}"

# Health check
health:
    curl -s "{{base}}/health"

# Post an entry  (usage: just post "/my/node | Hello world.")
post entry tid="":
    curl -s -X POST "{{base}}/entries{{if tid != ""}}&tid={{tid}}{{endif}}" \
         --data-urlencode "entry={{entry}}"

# Force cache refresh and dump entries
refresh tid="":
    curl -s "{{base}}/entries?format=txt.0.2&refresh{{if tid != ""}}&tid={{tid}}{{endif}}"

# ── E2E — manual sequence (no server needed) ─────────────────────────────────

# Add an entry
# just e2e-add-entry                              (uses default path + content)
# just e2e-add-entry "/climate/solutions | Solar panels." demo
e2e-add-entry entry="/demo/hello | Hello from just." tid="demo":
    {{php}} test/e2e_run.php POST /entries "sid=just&tid={{tid}}" "entry={{entry}}"

# Add a vote
# just e2e-add-vote                              (uses default poll path)
# just e2e-add-vote "/poll/q1 | votes:just:1 | Good idea?" demo
e2e-add-vote entry="/demo/poll | votes:just:1 | Good idea?" tid="demo":
    {{php}} test/e2e_run.php POST /votes "sid=just&tid={{tid}}" "entry={{entry}}"

# Read entries + votes for a tenant
# just e2e-read
# just e2e-read myproject
e2e-read tid="demo":
    @echo "── entries ──"
    {{php}} test/e2e_run.php GET /entries "sid=just&tid={{tid}}&format=txt.0.2&refresh"
    @echo ""
    @echo "── votes ────"
    {{php}} test/e2e_run.php GET /votes   "sid=just&tid={{tid}}&format=txt.0.2&refresh"

# Full manual sequence: add entry, add vote, read back
# just e2e-demo
# just e2e-demo myproject
e2e-demo tid="demo":
    @echo "── add entry ──"
    {{php}} test/e2e_run.php POST /entries "sid=just&tid={{tid}}" "entry=/demo/hello | Hello from just."
    @echo "── add vote ───"
    {{php}} test/e2e_run.php POST /votes   "sid=just&tid={{tid}}" "entry=/demo/poll | votes:just:1 | Good idea?"
    @echo "── read back ──"
    {{php}} test/e2e_run.php GET  /entries "sid=just&tid={{tid}}&format=txt.0.2&refresh"
    @echo ""
    {{php}} test/e2e_run.php GET  /votes   "sid=just&tid={{tid}}&format=txt.0.2&refresh"

# ── Logs ─────────────────────────────────────────────────────────────────────

# Show last 40 log lines
log:
    tail -40 infopedia.log

# Follow log in real time
log-tail:
    tail -f infopedia.log

# Grep log for errors
log-errors:
    grep -i " ERROR " infopedia.log | tail -20

# ── Clean ────────────────────────────────────────────────────────────────────

# Remove cache files
clean-cache:
    rm -f data/*.cache data/*.cache.outdated

# Remove throttle state files
clean-throttle:
    rm -f data/throttle_*.dat

# Remove E2E test tenant data
clean-test:
    rm -f data/*_e2e.* data/entries_e2e.* data/votes_e2e.*

# Remove all generated runtime files
clean: clean-cache clean-throttle clean-test

# ── Code quality ─────────────────────────────────────────────────────────────

# Check PHP syntax on all source files
lint:
    @for f in *.php; do {{php}} -l "$f" | grep -v "No syntax errors"; done; echo "lint done"

# Show all TODOs and FIXMEs in source
todo:
    grep -rn "TODO\|FIXME\|KLUDGE\|HACK" --include="*.php" . | grep -v test/ | grep -v ".git"
