# Test coverage audit

Ticket #17 audited the README feature surface against the unit suite.

New coverage closes the identified gaps for backend indexing/deletion/removal,
server availability, file lifecycle hook forwarding, and loading the captured
`suggest_q_infix_en.json` fixture instead of an inlined copy. Dedicated
autocomplete plugin coverage was added with ticket #16.

Existing tests already cover field mapping, query construction (facets, MLT,
grouping, highlighting, spellcheck and autocomplete), response parsing,
transport errors/authentication, extraction validation/cache/discovery/
processors/queue/invalidation, processor settings and configuration schema.

The manual upgrade note has no executable behavior and is intentionally not
unit-tested. The Docker end-to-end harness remains outside the hermetic unit
suite and is tracked separately by ticket #19.
