# Workplan: Issue 39 explicit AND semantics

> System of record: **Filesystem**

## Objective

Preserve Search API's parsed `AND` semantics at the Wayfinder eDisMax wire boundary without changing OR, phrases, escaping, grouping, or negation.

## Action Plan

1. Add focused red assertions in `tests/src/Unit/QueryBuilderTest.php` for top-level and nested/default AND groups, including mixed OR and negated groups.
2. Change `QueryBuilder::flattenKeys()` to serialize AND/default groups with explicit ` AND ` separators while leaving all other serialization behavior intact.
3. Run focused QueryBuilder tests and the complete hermetic PHPUnit suite in the documented nested Wayfinder fixture layout.
4. Run this worktree's disposable integration harness with its generated Compose project and dynamic loopback ports. Keep the corpus unchanged and verify `garden report` returns 1 result, `rocket unrelated` returns 0, and `beacon guidance` returns 1; capture emitted explicit-AND wire evidence.
5. Review only the isolated worktree changes. Do not copy, clean, overwrite, stage, or revert analogous uncommitted edits in the primary checkout.

## Acceptance Criteria

- [ ] Top-level and nested Search API AND key arrays serialize explicit `AND` operators.
- [ ] OR, phrases, escaping, grouping, and negation retain focused coverage and behavior.
- [ ] The unchanged live corpus produces the expected 1/0/1 result counts.
- [ ] Focused and full PHPUnit tests pass with zero warnings.
- [ ] The isolated dynamic-port harness passes and cleans up only its generated project.
- [ ] Changes are limited to the QueryBuilder implementation and its regression tests unless live evidence requires a narrowly justified harness assertion.

## Isolation

Work only in the `ticket-39` worktree. The primary checkout contains analogous dirty edits plus unrelated README and `.pi` changes; they are not authoritative and must remain untouched.
