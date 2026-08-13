# Workplan: Issue 19 end-to-end integration harness

> System of record: **Filesystem**

## Objective

Make the Docker integration harness safe for concurrent runs by using isolated Compose projects and dynamically allocated host ports, expand it to verify the implemented Search API Wayfinder feature surface, then run it from a current Wayfinder checkout and fix regressions with test-first evidence.

## Action Plan

1. [x] Update Docker Compose and harness orchestration so each run has an isolated project name, no globally fixed container names, dynamically selected collision-free host ports by default, optional explicit port overrides, and run-scoped cleanup.
2. [x] Expand deterministic live Drupal coverage for indexing and full-text search, facets, MLT, highlighting, file extraction, and terms autocomplete, installing the soft autocomplete dependency only where required and failing loudly per capability.
3. [x] Remove stale harness comments and documentation claims, and document concurrent execution and explicit port override behavior.
4. [x] Review the harness against Wayfinder origin/main `cfa87a03969f68098894ada7b980e52d00f8178a`, including its Dockerfile, search-api preset, authentication, endpoint, schema, and runtime prerequisites.
5. [x] Integrate the infrastructure and coverage changes and add focused regressions for language-aware highlight parsing, dynamic-field highlighting, and dynamic-field MLT mining exposed by the live run.
6. [x] Run the hermetic PHPUnit suite in the required repository layout and obtain full green single and concurrent Docker harness runs from a clean current-Wayfinder worktree with the tested Wayfinder regressions applied.
7. [x] Record reproducible commands and pass/fail evidence for issue #19.

## Running the harness

The harness is safe to run concurrently because each invocation derives a new
Compose project and uses project-scoped Docker resources. By default Compose
assigns loopback ephemeral host ports; the harness discovers them with
`docker compose port` after startup.

```bash
WAYFINDER_INTEGRATION=1 bash tests/integration/run.sh
WAYFINDER_INTEGRATION=1 bash tests/integration/run.sh &
WAYFINDER_INTEGRATION=1 bash tests/integration/run.sh & wait
```

Operators may set `WAYFINDER_HOST_PORT` and/or `DRUPAL_HOST_PORT`
explicitly; values must be integers from 1 through 65535 and must be
distinct. Docker remains the authority for whether an explicit port is
available, avoiding an external bind/check race. `COMPOSE_PROJECT_NAME` is
treated as a base label, not as a shared project identity. Invalid overrides
fail before Compose startup and `down -v` removes the generated project and
its Drupal volume.

The live query script reports named failures for unsupported Wayfinder
endpoints. It does not turn an upstream capability gap into a pass. Terms
autocomplete uses the optional `drupal/search_api_autocomplete` dependency; no
unsupported SuggestComponent capability is claimed.

## Validation

The canonical hermetic infrastructure contract is:

```bash
bash tests/integration/test_harness_round1_contract.sh
```

It validates Compose-assigned default ports, explicit override validation,
loopback binding, named-volume state, and run-scoped cleanup without requiring
a Docker daemon. The live harness itself remains manual and requires a current
Wayfinder checkout containing `Dockerfile` and `presets/search-api.toml`.

## Recorded evidence

Validation used Wayfinder origin/main
`cfa87a03969f68098894ada7b980e52d00f8178a`. The first live run exposed two
Wayfinder regressions: dynamic `search-api.toml` fields were rejected by
highlighting and ignored by MLT term mining. Focused Rust tests reproduced both
failures before the corresponding Wayfinder fixes were applied.

```bash
# Module checks. Preserve the CI-required nested fixture layout while using a
# disposable composer:2 container for PHP and dependencies.
mkdir -p /tmp/wayfinder/drupal/search_api_wayfinder
rsync -a --delete --exclude=.git --exclude=.pi --exclude=vendor \
  ./ /tmp/wayfinder/drupal/search_api_wayfinder/
rsync -a --delete /home/mark/Projects/wayfinder/solr-ref/ \
  /tmp/wayfinder/solr-ref/
docker run --rm --entrypoint sh \
  -v /tmp/wayfinder:/tmp/wayfinder \
  -w /tmp/wayfinder/drupal/search_api_wayfinder composer:2 -lc \
  'composer install --no-interaction --no-progress --prefer-dist --ignore-platform-req=ext-gd && vendor/bin/phpunit --do-not-cache-result'
# PHPUnit 11.5.56: OK (475 tests, 879 assertions)

bash tests/integration/test_harness_round1_contract.sh
# All round-1 harness contracts passed.

# From <wayfinder>/drupal/search_api_wayfinder after applying the tested
# dynamic-field highlighting/MLT Wayfinder regressions:
WAYFINDER_INTEGRATION=1 bash tests/integration/run.sh
# AUTH, ROUNDTRIP, HIGHLIGHT, FACETS, MLT, AUTOCOMPLETE, EXTRACT: PASS
```

Two overlapping invocations were also run from the same checkout. Both exited
zero, reported every named capability as `PASS`, used distinct generated
projects (`wayfinder-1786636202-370882-10610` and
`wayfinder-1786636202-370883-16970`), and removed their project-scoped volumes.

Wayfinder-side focused and full validation passed: the dynamic-field highlight
and MLT tests, all 68 highlight/MLT tests, `cargo fmt --check`,
`cargo clippy --all-targets -- -D warnings`, and the full `cargo test` suite.

## Completion Checks

- [x] Concurrency contract checks pass without a Docker daemon.
- [x] Deterministic live coverage and run-scoped cleanup are implemented.
- [x] PHPUnit passes in the required repository layout via a disposable PHP container.
- [x] Full single and concurrent Docker/Wayfinder harness runs pass.
- [x] Current Wayfinder compatibility regressions have focused red/green tests and a validated implementation.
