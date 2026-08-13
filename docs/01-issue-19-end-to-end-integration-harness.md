# Workplan: Issue 19 end-to-end integration harness

> System of record: **Filesystem**

## Objective

Make the Docker integration harness safe for concurrent runs by using isolated Compose projects and dynamically allocated host ports, expand it to verify the implemented Search API Wayfinder feature surface, then run it from a current Wayfinder checkout and fix regressions with test-first evidence.

## Action Plan

1. [ ] Update Docker Compose and harness orchestration so each run has an isolated project name, no globally fixed container names, dynamically selected collision-free host ports by default, optional explicit port overrides, and run-scoped cleanup.
2. [ ] Expand deterministic live Drupal coverage for indexing and full-text search, facets, MLT, highlighting, file extraction, and autocomplete, installing soft dependencies only where required and failing loudly per capability.
3. [ ] Remove stale harness comments and documentation claims, and document concurrent execution and explicit port override behavior.
4. [ ] Review the harness against current Wayfinder origin/main, including current Dockerfile, search-api preset, authentication, endpoint, schema, and runtime prerequisites.
5. [ ] Integrate the infrastructure and coverage changes, resolve conflicts, and add focused unit regressions for any implementation defects exposed by the live run.
6. [ ] Run the hermetic PHPUnit suite in the required repository layout and obtain a full green Docker harness run from a clean, current Wayfinder checkout.
7. [ ] Record reproducible commands and pass/fail evidence for issue #19.

## Completion Checks

- [ ] Each action-plan item is complete or explicitly deferred.
- [ ] Targeted validation or tests have been run and recorded.
- [ ] Any remaining follow-up is linked to the system of record.
