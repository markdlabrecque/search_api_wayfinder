# Workplan: Issue 40 literal Boolean terms

> System of record: **Filesystem**

## Objective

Serialize user terms equal to `AND`, `OR`, or `NOT` as literals rather than backend syntax, while preserving issue #39's explicit conjunction behavior and ensuring backend parse failures remain observable.

## Dependency

Issue #39 must land first. Rebase this branch onto the accepted #39 commit before red testing; the final branch must contain only #40-specific changes relative to that base.

## Action Plan

1. Capture actual Search API `terms` key arrays for the reported top-level, nested, and negated inputs. Probe candidate literal encodings against live Wayfinder/eDisMax and compare relevant upstream Search API Solr behavior; choose encoding from evidence.
2. Add focused red tests for literal `AND`, `OR`, and `NOT` terms in top-level AND arrays, nested AND/OR groups, and negated groups. Retain #39 regressions for ordinary explicit-AND terms.
3. Update `QueryBuilder::flattenKeys()` so scalar user values matching Boolean keywords use the proven literal encoding while array metadata alone controls conjunction and negation. Preserve phrases, escaping, parentheses, OR groups, and negated groups.
4. Verify existing backend failure propagation and logging. If a search exception can become a plausible zero-result response or lacks observable logging, add the narrowest test-first logging/propagation fix; do not broaden error-handling scope without red evidence.
5. Extend the isolated live harness to exercise `causa OR duis`, `causa AND duis`, and `NOT causa`, assert no Wayfinder HTTP 400/parse errors, and preserve #39's multiword-AND checks.
6. Run focused tests, full PHPUnit in the nested fixture layout, the harness contract test, and a generated-project/dynamic-port live integration run.

## Acceptance Criteria

- [ ] Literal Boolean keyword terms are covered at top level and in nested/negated groups.
- [ ] Generated Wayfinder queries are valid and preserve Search API's parsed structure.
- [ ] Issue #39 explicit-AND behavior remains green.
- [ ] Live Views-equivalent searches do not produce Wayfinder HTTP 400 responses.
- [ ] Backend parse failures are observably logged/propagated rather than converted to plausible zero results.
- [ ] Focused, full, contract, and isolated live harness validation passes.
