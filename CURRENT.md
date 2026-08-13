# Current work plan

Build out the Search API backend implementation. Current state: `src/` is empty;
the red suite (16 test classes, 453 tests, ~9,400 lines) plus the README are the
spec. Work is tracked on GitHub — each ticket carries a condensed contract
distilled from its test class; **the test file is always the authoritative
spec**.

Tracking issue: <https://github.com/markdlabrecque/search_api_wayfinder/issues/20>

## Dev environment

Six test classes (WayfinderClient, WayfinderBackend, QueryBuilder,
ResponseParser, DocumentBuilder, FieldMapper) load ground-truth fixtures from
`solr-ref/` in the Wayfinder server repo, resolved relatively — **the module
checkout must sit at `<wayfinder repo>/drupal/search_api_wayfinder/`** (see
README "Testing"). A standalone clone fails those classes on missing fixtures.

## Phase 1 — core wire layer (dependency order)

- [x] [#2](https://github.com/markdlabrecque/search_api_wayfinder/issues/2) FieldMapper (foundation — everything depends on it)
- [ ] [#3](https://github.com/markdlabrecque/search_api_wayfinder/issues/3) WayfinderClient (independent of #2; parallelizable)
- [ ] [#4](https://github.com/markdlabrecque/search_api_wayfinder/issues/4) DocumentBuilder (needs #2)
- [ ] [#5](https://github.com/markdlabrecque/search_api_wayfinder/issues/5) QueryBuilder (needs #2; conventions shared with #6)
- [ ] [#6](https://github.com/markdlabrecque/search_api_wayfinder/issues/6) ResponseParser (needs #2; round-trip-tested against #5)
- [ ] [#7](https://github.com/markdlabrecque/search_api_wayfinder/issues/7) WayfinderBackend (needs #2–#6)

## Phase 2 — file extraction subsystem

- [ ] [#8](https://github.com/markdlabrecque/search_api_wayfinder/issues/8) ExtractFileValidator (independent)
- [ ] [#9](https://github.com/markdlabrecque/search_api_wayfinder/issues/9) KeyValueExtractionCache (independent)
- [ ] [#10](https://github.com/markdlabrecque/search_api_wayfinder/issues/10) FileReferenceMap (independent)
- [ ] [#11](https://github.com/markdlabrecque/search_api_wayfinder/issues/11) LinkedFileDiscoverer (independent)
- [ ] [#12](https://github.com/markdlabrecque/search_api_wayfinder/issues/12) FileExtractionProcessorBase + FileExtraction (needs #7, #8, #9, #10)
- [ ] [#13](https://github.com/markdlabrecque/search_api_wayfinder/issues/13) LinkedFileExtraction (needs #11, #12, #15)
- [ ] [#14](https://github.com/markdlabrecque/search_api_wayfinder/issues/14) ExtractorQueue worker (needs #7, #9)
- [ ] [#15](https://github.com/markdlabrecque/search_api_wayfinder/issues/15) ExtractionInvalidator (needs #10)

## Phase 3 — autocomplete & hardening

- [ ] [#16](https://github.com/markdlabrecque/search_api_wayfinder/issues/16) Autocomplete suggester plugins (needs #5, #7; includes writing their missing tests)
- [ ] [#17](https://github.com/markdlabrecque/search_api_wayfinder/issues/17) Test coverage audit (backend indexItems/delete paths, module hooks, suggest fixture)

## Phase 4 — infrastructure & verification

- [ ] [#18](https://github.com/markdlabrecque/search_api_wayfinder/issues/18) CI workflow (can start any time; must handle the solr-ref checkout layout)
- [ ] [#19](https://github.com/markdlabrecque/search_api_wayfinder/issues/19) End-to-end integration harness run (after everything else)

## Definition of done

`vendor/bin/phpunit` fully green (453+ tests, zero warnings —
`failOnWarning=true`) from a checkout inside the Wayfinder repo, CI green, and a
passing `WAYFINDER_INTEGRATION=1` harness run.
