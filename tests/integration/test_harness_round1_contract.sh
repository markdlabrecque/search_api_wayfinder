#!/usr/bin/env bash
# Canonical round-1 infrastructure contract for issue #19. Defaults are
# Docker-assigned ports, not preselected host-port ranges.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RUN_SCRIPT="$HERE/run.sh"
COMPOSE_FILE="$HERE/docker-compose.yml"
TMP_ROOT="$(mktemp -d)"
FAILURES=0
pass() { printf 'PASS: %s\n' "$1"; }
fail() { printf 'FAIL: %s\n' "$1" >&2; FAILURES=$((FAILURES + 1)); }
trap 'rm -rf "$TMP_ROOT"' EXIT

if grep -Eq 'port_is_available|reserve_port|acquire_port_lock|PORT_STATE_DIR' "$RUN_SCRIPT"; then
  fail 'custom host-port allocation/locking remains'
else
  pass 'custom host-port allocation/locking removed'
fi
if grep -Eq 'docker compose port wayfinder 8983' "$RUN_SCRIPT" && grep -Eq 'docker compose port drupal 80' "$RUN_SCRIPT"; then
  pass 'published ports are discovered with docker compose port'
else
  fail 'published ports must be discovered with docker compose port'
fi
if grep -Eq '127\.0\.0\.1:\$\{WAYFINDER_HOST_PORT:-\}:8983' "$COMPOSE_FILE" &&
   grep -Eq '127\.0\.0\.1:\$\{DRUPAL_HOST_PORT:-\}:80' "$COMPOSE_FILE"; then
  pass 'published ports bind to loopback and support ephemeral defaults'
else
  fail 'published ports must bind loopback with empty-host ephemeral defaults'
fi
if grep -Eq 'drupal_site:/opt/drupal' "$COMPOSE_FILE" && ! grep -Eq 'drupal-site[^:]*:/opt/drupal' "$COMPOSE_FILE"; then
  pass 'Drupal state uses a project-scoped named volume'
else
  fail 'Drupal state must use a named volume, not host bind files'
fi
if grep -Eq 'docker compose down -v' "$RUN_SCRIPT"; then
  pass 'cleanup removes the project and named volume'
else
  fail 'cleanup must use docker compose down -v'
fi

FAKE_BIN="$TMP_ROOT/bin"
mkdir -p "$FAKE_BIN"
cat >"$FAKE_BIN/docker" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
op="${2:-unknown}"
printf '%s|%s|%s|%s\n' "$op" "${COMPOSE_PROJECT_NAME:-}" "${WAYFINDER_HOST_PORT:-}" "${DRUPAL_HOST_PORT:-}" >>"$HARNESS_TEST_LOG"
if [ "$op" = port ]; then
  case "${3:-}" in
    wayfinder) printf '127.0.0.1:23101\n' ;;
    drupal) printf '127.0.0.1:23102\n' ;;
    *) exit 1 ;;
  esac
fi
# Stop after port discovery; the harness must still clean up its project.
if [ "$op" = exec ]; then exit 42; fi
STUB
cat >"$FAKE_BIN/curl" <<'STUB'
#!/usr/bin/env bash
exit 0
STUB
chmod +x "$FAKE_BIN/docker" "$FAKE_BIN/curl"

mkdir -p "$TMP_ROOT/work"
cp "$RUN_SCRIPT" "$TMP_ROOT/work/run.sh"
cp "$COMPOSE_FILE" "$TMP_ROOT/work/docker-compose.yml"
set +e
env PATH="$FAKE_BIN:$PATH" HARNESS_TEST_LOG="$TMP_ROOT/default.log" \
  WAYFINDER_INTEGRATION=1 COMPOSE_PROJECT_NAME=round1-default \
  bash "$TMP_ROOT/work/run.sh" >"$TMP_ROOT/default.out" 2>&1
status=$?
set -e
if [ "$status" -ne 0 ] &&
   [ "$(grep -c '^port|' "$TMP_ROOT/default.log")" -eq 2 ] &&
   grep -q '^down|' "$TMP_ROOT/default.log" &&
   awk -F '|' '$3 != "" || $4 != "" { bad = 1 } END { exit bad }' "$TMP_ROOT/default.log"; then
  pass 'default run discovers Docker-assigned ports without changing Compose overrides and cleans up on failure'
else
  fail 'default run did not preserve ephemeral Compose configuration through discovery and cleanup'
fi

set +e
env PATH="$FAKE_BIN:$PATH" HARNESS_TEST_LOG="$TMP_ROOT/invalid.log" \
  WAYFINDER_INTEGRATION=1 WAYFINDER_HOST_PORT=bad DRUPAL_HOST_PORT=23102 \
  bash "$TMP_ROOT/work/run.sh" >"$TMP_ROOT/invalid.out" 2>&1
status=$?
set -e
if [ "$status" -ne 0 ] && [ ! -s "$TMP_ROOT/invalid.log" ]; then
  pass 'invalid explicit override fails before Compose startup'
else
  fail 'invalid explicit override reached Compose'
fi

if [ "$FAILURES" -ne 0 ]; then
  printf '\n%d round-1 contract check(s) failed.\n' "$FAILURES" >&2
  exit 1
fi
printf '\nAll round-1 harness contracts passed.\n'
