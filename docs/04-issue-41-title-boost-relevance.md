# Workplan: Issue 41 title boost relevance

> System of record: **Filesystem**

## Objective

Make tester relevance intentional and reproducible: title matches use boost `3`, body and extracted attachment text remain at boost `1`, emitted `qf` reflects that policy, title matches outrank the body-only `report` match, and attachment-only search remains green.

## Action Plan

1. Inspect the DDEV tester's exported index configuration read-only, then reproduce it in an isolated tester copy owned by this team. Never mutate the shared live D11 containers/database during concurrent work.
2. Set title boost `3.0` and body/file boosts `1.0` in the isolated export. Mirror the policy in the repository's disposable integration setup and add a configuration drift assertion.
3. Preserve/build a deterministic three-document relevance corpus: two title matches for `report`, one body-only match, and an attachment-only token that occurs nowhere in title/body.
4. Capture the actual `/select` request or equivalent live client evidence and assert `qf` applies `^3` to title while body and file remain at their default/effective boost.
5. Add a named live ranking check requiring both title matches to score and rank above the body-only match, without imposing arbitrary order between title matches. Preserve the exact attachment-only extraction assertion.
6. Run harness contract, full PHPUnit, single live harness, and overlapping live harness invocations using generated Compose projects and dynamic loopback ports.
7. After isolated validation and only during serialized integration, sync the exported index configuration/documentation into `/home/mark/Projects/d11`; do not import into or restart its shared running instance unless explicitly needed for final acceptance and no sibling is using it.

## Acceptance Criteria

- [ ] Durable tester configuration records title `3`, body `1`, and attachment `1` relevance policy.
- [ ] Live wire/client evidence proves the expected `qf` boosts.
- [ ] Both title matches rank above the body-only `report` match.
- [ ] Attachment-only extraction search still returns the intended document.
- [ ] Automated assertions live in the versioned disposable integration harness.
- [ ] Single and concurrent harness runs use distinct projects, dynamic ports, and run-scoped cleanup.
- [ ] Shared D11 mutation is serialized and limited to the validated export/docs sync.
