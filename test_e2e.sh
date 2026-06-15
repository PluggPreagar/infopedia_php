#!/usr/bin/env bash
# Run unit tests + E2E tests using the local PHP interpreter (no server needed).
# Usage:
#   ./test_e2e.sh           unit + e2e
#   ./test_e2e.sh --debug   verbose HTTP output for e2e

PHP=$(command -v php8.3 2>/dev/null || command -v php8.2 2>/dev/null \
   || command -v php8.1 2>/dev/null || command -v php8.0 2>/dev/null \
   || command -v php 2>/dev/null)

echo "PHP: $PHP"
echo ""

echo "=== Unit tests ==="
$PHP test/run_all.php
UNIT=$?

echo ""
echo "=== E2E tests ==="
$PHP test/e2e.php "$@"
E2E=$?

echo ""
[ $UNIT -eq 0 ] && [ $E2E -eq 0 ] && echo "ALL PASS" && exit 0
echo "FAILED (unit=$UNIT e2e=$E2E)" && exit 1
