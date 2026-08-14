# Workplan: Issue 42 deterministic tester ordering

> System of record: **Filesystem**

## Objective

Keep relevance primary for keyword searches while making score ties, match-all browsing, and category-only browsing deterministic with stable secondary ordering.

## Dependency and overlap

Coordinate with #41 because both touch tester setup/configuration and live relevance assertions. Planning and red-test work may proceed independently, but shared D11 export integration must be serialized after #41 and preserve its boost configuration.

## Action Plan

1. Inspect the DDEV View/index exports read-only and reproduce them in an isolated team-owned tester copy. Confirm whether the existing indexed title emits a sortable field; add a dedicated sortable field only if live evidence shows it is required.
2. Configure sort priority as relevance descending, sortable title ascending, then Search API item ID ascending (or an evidence-backed equivalent), so equal titles remain deterministic while keyword relevance stays primary.
3. Add automated live checks in the versioned disposable integration harness: repeat empty and category-only queries and compare ordered IDs; for keyword queries require non-increasing scores and apply title/ID ordering only within score ties.
4. Retain/add focused QueryBuilder wire coverage for the exact multi-sort expression and verify the live outgoing sort parameter.
5. Run focused/full tests, harness contracts, and single plus overlapping live harness runs using generated Compose projects, dynamic loopback ports, and run-scoped cleanup.
6. After #41 is integrated and isolated proof is green, serialize syncing the validated View/index export and ordering documentation into `/home/mark/Projects/d11`. Preserve #41's boosts and do not mutate the shared running instance during concurrent team work.

## Acceptance Criteria

- [ ] A durable tester export defines deterministic relevance-desc/title-asc/item-ID-asc ordering or a documented evidence-backed equivalent.
- [ ] Repeated empty and category-only requests return identical ordered pages.
- [ ] Keyword searches remain relevance-first, with stable ordering only for equal scores.
- [ ] Exact wire sort behavior has focused and live evidence.
- [ ] No unnecessary sortable field is added when the existing title mapping suffices.
- [ ] Shared D11 integration is serialized after #41 and preserves its relevance policy.
- [ ] Focused, full, contract, single-live, and concurrent-live validation passes in isolated harness projects.
