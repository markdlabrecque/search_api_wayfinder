#!/usr/bin/env bash
# End-to-end verification for issue #19. Installs a real Drupal site, indexes
# a deterministic corpus through the standalone `wayfinder` Search API backend,
# and exercises the capabilities supported by the checked-out search-api
# preset. Docker and network access are required; run manually with:
#
#   WAYFINDER_INTEGRATION=1 bash tests/integration/run.sh
#
# Each invocation receives a generated Compose project and project-scoped
# Drupal volume. Compose assigns loopback ephemeral host ports by default;
# docker compose port discovers them after startup. Explicit
# WAYFINDER_HOST_PORT and DRUPAL_HOST_PORT overrides are validated before
# Docker starts. The soft autocomplete dependency is installed only in this
# ephemeral site. No fixed container names are used.

if [ "${WAYFINDER_INTEGRATION:-0}" != "1" ]; then
  echo "skipping search_api_wayfinder integration harness (set WAYFINDER_INTEGRATION=1 to run)"
  exit 0
fi

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$HERE"

RUN_TOKEN="$(date +%s)-${BASHPID}-${RANDOM}"
PROJECT_BASE="${COMPOSE_PROJECT_NAME:-wayfinder}"
PROJECT_BASE="$(printf '%s' "$PROJECT_BASE" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
PROJECT_BASE="${PROJECT_BASE:0:35}"
[ -n "$PROJECT_BASE" ] || PROJECT_BASE='wayfinder'
COMPOSE_PROJECT_NAME="${PROJECT_BASE}-${RUN_TOKEN}"
STACK_ATTEMPTED=0

validate_port_override() {
  local name="$1" value="$2"
  if ! [[ "$value" =~ ^[0-9]+$ ]] || [ "$value" -lt 1 ] || [ "$value" -gt 65535 ]; then
    echo "FAIL: $name must be an integer between 1 and 65535" >&2
    exit 2
  fi
}

cleanup() {
  local status=$?
  trap - EXIT
  if [ "$STACK_ATTEMPTED" = 1 ]; then
    echo "--- tearing down Compose project $COMPOSE_PROJECT_NAME ---"
    docker compose down -v || true
  fi
  exit "$status"
}
trap cleanup EXIT

export COMPOSE_PROJECT_NAME
if [ -n "${WAYFINDER_HOST_PORT+x}" ]; then
  validate_port_override WAYFINDER_HOST_PORT "$WAYFINDER_HOST_PORT"
fi
if [ -n "${DRUPAL_HOST_PORT+x}" ]; then
  validate_port_override DRUPAL_HOST_PORT "$DRUPAL_HOST_PORT"
fi
if [ -n "${WAYFINDER_HOST_PORT+x}" ] && [ -n "${DRUPAL_HOST_PORT+x}" ] &&
   [ "$WAYFINDER_HOST_PORT" = "$DRUPAL_HOST_PORT" ]; then
  echo 'FAIL: WAYFINDER_HOST_PORT and DRUPAL_HOST_PORT must be distinct' >&2
  exit 2
fi

echo "--- building wayfinder image + starting wayfinder ---"
STACK_ATTEMPTED=1
docker compose up -d --build wayfinder
WAYFINDER_PUBLISHED_PORT="$(docker compose port wayfinder 8983 | awk -F: 'NF { print $NF; exit }')"
if ! [[ "$WAYFINDER_PUBLISHED_PORT" =~ ^[0-9]+$ ]]; then
  echo "FAIL: docker compose port did not return a Wayfinder host port" >&2
  exit 1
fi
WAYFINDER_BASE_URL="http://127.0.0.1:${WAYFINDER_PUBLISHED_PORT}/wayfinder/content"
export WAYFINDER_BASE_URL

echo -n "waiting for wayfinder ping"
wayfinder_ready=0
for _ in $(seq 60); do
  if curl -sf "${WAYFINDER_BASE_URL}/admin/ping?wt=json" >/dev/null 2>&1; then
    echo " ok"; wayfinder_ready=1; break
  fi
  echo -n "."; sleep 1
done

if [ "$wayfinder_ready" != "1" ]; then
  echo "FAIL: wayfinder never became ready (ping did not succeed after 60 attempts)"
  exit 1
fi

echo "--- drupal container up ---"
docker compose up -d drupal
DRUPAL_PUBLISHED_PORT="$(docker compose port drupal 80 | awk -F: 'NF { print $NF; exit }')"
if ! [[ "$DRUPAL_PUBLISHED_PORT" =~ ^[0-9]+$ ]]; then
  echo "FAIL: docker compose port did not return a Drupal host port" >&2
  exit 1
fi
sleep 3

echo "--- composer project + module under test (path repo), no search_api_solr ---"
docker compose exec -T drupal bash -lc "
  set -euo pipefail
  cd /opt/drupal
  if [ ! -f composer.json ]; then
    composer create-project drupal/recommended-project:11.3.2 tmp_build --no-interaction
    shopt -s dotglob
    mv tmp_build/* .
    rmdir tmp_build
  fi
  php -r '\$c = json_decode(file_get_contents(\"composer.json\"), true); \$c[\"repositories\"][\"wf19_module\"] = [\"type\" => \"path\", \"url\" => \"/opt/module-src\", \"options\" => [\"versions\" => [\"wayfinder/search_api_wayfinder\" => \"dev-main\"]]]; file_put_contents(\"composer.json\", json_encode(\$c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));'
  composer config repositories.drupal composer https://packages.drupal.org/8
  composer require drush/drush:13.7.6 drupal/search_api:1.41.0 drupal/search_api_autocomplete:^1.0 'wayfinder/search_api_wayfinder:dev-main' --no-interaction
"

echo "--- confirming search_api_solr / solarium are NOT dependencies (acceptance item) ---"
docker compose exec -T drupal bash -lc "
  cd /opt/drupal
  if composer show drupal/search_api_solr >/dev/null 2>&1; then
    echo 'FAIL: drupal/search_api_solr present in dependency tree'
    exit 1
  fi
  if composer show solarium/solarium >/dev/null 2>&1; then
    echo 'FAIL: solarium/solarium present in dependency tree'
    exit 1
  fi
  echo 'confirmed: no drupal/search_api_solr or solarium/solarium in dependency tree'
"

echo "--- site install (sqlite) ---"
docker compose exec -T drupal bash -lc "
  cd /opt/drupal
  vendor/bin/drush site:install standard \
    --db-url=sqlite://sites/default/files/.ht.sqlite \
    --site-name='Wayfinder IT' --account-name=admin --account-pass=admin -y
  vendor/bin/drush en search_api search_api_autocomplete search_api_wayfinder node file -y
"

echo "--- module install / backend plugin discovery check ---"
docker compose exec -T drupal bash -lc "
  cd /opt/drupal
  vendor/bin/drush pml --filter=search_api_wayfinder --format=json
  vendor/bin/drush php:eval \"print_r(array_keys(\\\\Drupal::service('plugin.manager.search_api.backend')->getDefinitions()));\"
"

echo "--- server, index, content ---"
docker compose cp create_content.php drupal:/opt/drupal/create_content.php
docker compose cp setup_server_index.php drupal:/opt/drupal/setup_server_index.php
docker compose cp run_queries.php drupal:/opt/drupal/run_queries.php
docker compose exec -T drupal bash -lc "
  cd /opt/drupal
  # Setup before content: setup_server_index.php creates the field_attachments
  # file field (and the server/index). The attachment node created by
  # create_content.php references that field, so the field must exist first --
  # otherwise the file reference is silently dropped on save and the #262
  # extraction slice has nothing to extract.
  vendor/bin/drush php:script setup_server_index.php
  vendor/bin/drush php:script create_content.php
  vendor/bin/drush search-api:index wf19_index || vendor/bin/drush sapi-i wf19_index
"

# WayfinderBackend sends commitWithin=1000ms (setup_server_index.php), an
# async *scheduled* hard commit, not immediate -- so the just-indexed fields
# are not yet visible to /select without this. Force a synchronous commit
# straight to the wayfinder container so the round trip isn't racing it.
curl -sf --user operator:secret "${WAYFINDER_BASE_URL}/update?commit=true" -H 'Content-Type: application/json' -d '{}' >/dev/null

# Assert documents actually landed before handing off to run_queries.php,
# so the "indexing succeeded" claim above is backed by real evidence, not
# just this comment.
num_found="$(curl -sf --user operator:secret --get "${WAYFINDER_BASE_URL}/select" \
  --data-urlencode 'q=*:*' \
  --data-urlencode 'fq=index_id:"wf19_index"' \
  --data-urlencode 'rows=0' \
  | jq -r '.response.numFound // 0')"
if ! [ "$num_found" -ge 1 ] 2>/dev/null; then
  echo "FAIL: expected indexed documents for index_id=wf19_index, found $num_found"
  exit 1
fi
echo "confirmed: $num_found document(s) indexed for wf19_index"

# Keep one host-side probe so the evidence contains the actual sort parameter
# sent to a dynamically published loopback port, independently of Drupal's
# result parser. The focused QueryBuilder test covers its serialization; this
# proves the isolated live server accepts the same wire expression.
SORT_URL="$(curl -sf --user operator:secret --get "$WAYFINDER_BASE_URL/select" \
  --data-urlencode 'q=*:*' \
  --data-urlencode 'fq=index_id:"wf19_index"' \
  --data-urlencode 'fl=id,score,sort_title' \
  --data-urlencode 'sort=score desc,sort_title asc,id asc' \
  --data-urlencode 'rows=100' -o /dev/null -w '%{url_effective}')"
if printf '%s' "$SORT_URL" | grep -Eiq 'sort=score[+%20]+desc.*sort_title[+%20]+asc.*id[+%20]+asc'; then
  echo "SORT: PASS - live request $SORT_URL"
else
  echo "SORT: FAIL - live URL did not contain sort=score desc,sort_title asc,id asc: $SORT_URL" >&2
  exit 1
fi

echo "--- real index+search round trip ---"
docker compose exec -T drupal bash -lc "
  cd /opt/drupal
  vendor/bin/drush php:script run_queries.php
"

echo "--- done ---"
