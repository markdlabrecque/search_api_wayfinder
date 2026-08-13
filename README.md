# search_api_wayfinder

[![CI](https://github.com/markdlabrecque/search_api_wayfinder/actions/workflows/ci.yml/badge.svg)](https://github.com/markdlabrecque/search_api_wayfinder/actions/workflows/ci.yml)

A [Search API](https://www.drupal.org/project/search_api) backend plugin
(plugin id `wayfinder`) that indexes and queries a **Wayfinder** server — a
Solr-wire-compatible search backend.

It talks the Solr wire format — param names, semantics, and JSON envelope —
directly over Guzzle (Drupal core's `http_client` service). Only the request
*paths* differ: Wayfinder serves them under `/wayfinder/<core>/...` rather than
`/solr/<core>/...`, which is what the server's **Base path** setting selects.
It does **not** depend on `search_api_solr` or Solarium, and it does not use a
connector plugin: the backend plugin is self-contained. Field-naming
conventions and a handful of method-level behaviours are ported from
`search_api_solr` (both modules are GPL-2.0-or-later) so that the two produce
consistent wire output and a consistent config UX.

- Source: <https://github.com/markdlabrecque/search_api_wayfinder>
- Composer package name: `wayfinder/search_api_wayfinder`

## Requirements

| Requirement | Version |
| --- | --- |
| PHP | >= 8.1 |
| Drupal core | ^10.3 \|\| ^11 |
| `drupal/search_api` | ^1.35 |
| A running Wayfinder server | core built from the `search-api` preset (see [Wayfinder-side schema](#wayfinder-side-schema)) |

`drupal/search_api_autocomplete` (^1.0) is a **soft** dependency: the
autocomplete and spellcheck suggesters this module ships only load when that
module is installed, so it is deliberately absent from
`search_api_wayfinder.info.yml`. Install it yourself if you want autocomplete.

## Install

The module is published only from GitHub — it is on neither Packagist nor
drupal.org — so add the repository to your site's `composer.json` as a `vcs`
repository first, then require it by its **package** name
(`wayfinder/search_api_wayfinder`), not by its GitHub path:

```bash
# In your Drupal site root (the directory with composer.json).
composer config repositories.search_api_wayfinder vcs https://github.com/markdlabrecque/search_api_wayfinder
composer require wayfinder/search_api_wayfinder:dev-main
drush en search_api_wayfinder
```

Notes on that `composer require`:

- **There are no tagged releases yet**, so the constraint has to name the
  branch: `dev-main`. Once the repository carries semver tags, use a normal
  stable constraint (`^1.0`) instead.
- To pin a build reproducibly, require an explicit commit:
  `composer require 'wayfinder/search_api_wayfinder:dev-main#<sha>'`.
- The package is `"type": "drupal-module"`, so it lands in
  `web/modules/contrib/search_api_wayfinder` via `composer/installers`. That
  works out of the box on `drupal/recommended-project`; on a bespoke layout,
  make sure `composer/installers` is allowed in `config.allow-plugins` and that
  `extra.installer-paths` covers `type:drupal-module`.
- `drupal/search_api` comes from `https://packages.drupal.org/8`. A site built
  from `drupal/recommended-project` already has that repository; if yours does
  not, add it:
  `composer config repositories.drupal composer https://packages.drupal.org/8`.

### Without Composer

Cloning straight into `web/modules/custom/search_api_wayfinder` also works —
Drupal core's module classloader resolves `Drupal\search_api_wayfinder\*` from
`src/` without Composer's autoloader — provided `drupal/search_api` is already
installed on the site. You lose dependency-constraint checking, so this is for
local hacking, not production.

### Local development against a checkout

To work on the module and a site side by side, use a `path` repository pointing
at your clone:

```bash
composer config repositories.search_api_wayfinder path /abs/path/to/search_api_wayfinder
composer require wayfinder/search_api_wayfinder:@dev
```

`tests/integration/run.sh` does exactly this, if you want a worked example.

## Configure a server and index

1. **Configuration → Search and metadata → Search API → Add server**, and pick
   **Wayfinder** as the backend.
2. Fill in the connection settings to point at your Wayfinder instance:
   - **HTTP protocol**, **host**, **port**, **base path**, **core** — defaults
     are `http://localhost:8983/wayfinder/<core>`.
   - **Request timeout** (seconds) and **commitWithin** (ms).
   - **HTTP Basic authentication username / password**, if your Wayfinder
     instance requires auth.
   - **Retrieve result highlighting from the server** — off by default.
3. Add an index against that server and index content as usual.

Once the server is saved, its *View* page shows the server URL plus the
Wayfinder version, read from `{core}/admin/system`.

## File attachment extraction

The module can index the text of attached files by posting them to Wayfinder's
`/update/extract` endpoint (multipart part name `file`, `extractOnly=true`) —
no Tika or `search_api_attachments` involved. Two Search API **processors** are
shipped; enable them per index under the index's *Processors* tab:

- **`wayfinder_file_extraction`** — extracts text from files referenced by the
  indexed entity's own file/image/media fields.
- **`wayfinder_linked_file_extraction`** — extracts text from files
  *linked or embedded inside* rendered content (hrefs, embedded media),
  resolved by uuid/id/uri through the stream wrapper manager.

Both expose the same settings on their own configuration form:

| Setting | Meaning |
| --- | --- |
| **Extraction mode** | `inline` (extract during indexing) or `queue` (defer to the `wayfinder_extraction` queue, drained by cron or `drush queue:run wayfinder_extraction`) |
| **Excluded file extensions** | Space-separated list; those files are skipped |
| **Maximum file size** | Byte-size string (e.g. `2 MB`); `0` or empty means no limit |
| **Exclude `private://` files** | Skip files in the private filesystem |
| **Maximum files indexed per file field** | `0` for no limit |
| **Maximum extracted text** | Byte-size string; truncates extracted text; `0` for no limit |

Extraction results are cached in a keyvalue collection keyed by **content
hash**, so re-indexing unchanged files is a cache hit rather than a re-extract.

Invalidation is automatic: `hook_file_update()` and `hook_file_delete()` in
`search_api_wayfinder.module` forward to `ExtractionInvalidator`, which uses the
file→item reference map to mark every index item referencing a changed or
deleted file for reindex. A file saved without content changes still triggers a
reindex, but the re-extraction hits the cache.

## Autocomplete and spellcheck

With `drupal/search_api_autocomplete` installed, two suggester plugins become
available on an autocomplete search's configuration:

- **`search_api_wayfinder_suggester`** — reads suggestions through Wayfinder's
  *terms* component (`terms.fl=twm_suggest`), the sink field that the
  `solr_text_suggester` data type indexes into. Solr's SuggestComponent
  (`/suggest`) is deliberately not used. Configurable per index and language.
- **`search_api_wayfinder_spellcheck`** — reads the `spellcheck` component
  against the `spellcheck_<lang>` sink field written by the
  `solr_text_spellcheck` data type. No configuration of its own.

Neither indexes a site hash (see [Not supported](#not-supported)).

## Wayfinder-side schema

Create the Wayfinder core from the Wayfinder server repository's
**`presets/search-api.toml`**.

That preset expresses the `search_api_solr` dynamic-field naming convention
(`ts_title`, `tm_body`, `its_count`, `sm_tags`, `sort_*`, …) as Wayfinder
`[[fields]]`/`[[dynamic_fields]]` rules, derived from the captured ground-truth
configset at `solr-ref/search-api/configset/schema.xml` in that same
repository. This module's `FieldMapper` emits exactly those names, so **a core
built from any other schema will silently fail to match the fields the module
writes and queries** — no error on either side, just zero results.

### Language-aware text field names

Text-family fields are named **per language**, exactly as `search_api_solr`
names them (`SearchApiSolrBackend::formatSolrFieldNames()`): the type prefix is
followed by a forced `m` (cardinality is ignored for text), then the language
separator `;`, then the langcode, and the whole name is run through
`encodeSolrName()`, which replaces every character outside `[a-zA-Z0-9_]` with
`_X<lowercase hex>_`. So `title`/`text` in English is **`tm_X3b_en_title`**,
and its sort copy is the language-agnostic **`sort_title`** (see below).
Non-text fields are unchanged (`its_count`, `sm_tags`, …). Two fixed sinks are
named without the `;` encoding: `twm_suggest` (`solr_text_suggester`) and
`spellcheck_<lang>` with `-` replaced by `_` (`solr_text_spellcheck`) —
upstream's comment is "Don't use the language separator here!".

**This renames every text field on the wire, so it is a breaking change: a site
upgrading past this release must reindex in full.** Documents written under the
old names (`ts_title`, `tm_body`) are still in the core but no query will match
them again.

Which languages a *query* uses: any `search_api_language` condition on the
query (`=`/`IN`, including nested groups), otherwise every enabled site
language, otherwise `und`. Full-text field lists (`qf`, `hl.fl`, `mlt.fl`,
`terms.fl`) carry every variant; sorts and `group.sort` use the first resolved
language; facets always use `und`.

How a *condition* combines its variants is decided by one question, asked of
the clause the condition emits, never by the operator's name: **does a document
lacking that language variant satisfy the clause?** If yes the variants are
`AND`ed, if no they are `OR`ed. A document carries only the variant it was
indexed in, so getting this backwards makes the condition match every document.
`= NULL`, `<>`, `NOT BETWEEN`, `NOT IN` without a NULL member, and `IN` *with*
one (its missing-field alternative is true for every absent variant) all `AND`;
`=`, `BETWEEN`, plain `IN`, `<> NULL`, and `NOT IN` with a NULL member (which
keeps a field-exists requirement) all `OR`. `search_api_solr` states the same
rule for its own NULL case.

### Single language-agnostic sort copies

A text field's **sort copy is a single language-agnostic `sort_<id>` field**,
not one per language. `search_api_solr` writes a sort copy for every enabled
site language plus `und` ("To allow sorted multilingual searches we need to
fill *all* language-specific sort fields!") so a query resolved to one
collation can sort a document indexed under another — load-bearing only because
real Solr types each copy as a language-specific `collated_<lang>`, producing
different orderings. Wayfinder has no collation type (every `sort_*` maps to
plain `string`), so the per-language copies would be byte-identical with no
ordering benefit. A single field reclaims that redundancy: measured at ~30%
index overhead on a monolingual site and ~3.5x on an 8-language site.
**This renames every sort copy on the wire (`sort_X3b_en_title` → `sort_title`),
so an index built before this change must be reindexed — queries target
`sort_<id>`, which older documents do not carry.**

## Upgrading: change the Base path from `/solr` to `/wayfinder`

Wayfinder used to serve its API under `/solr/<core>/...` and the module's
default **Base path** was `/solr`. Wayfinder now serves the same wire API under
`/wayfinder/<core>/...`, and the default here is `/wayfinder`. There is no
`/solr` compatibility route, so a server still pointing at `/solr` gets 404s.

**This is a manual step for existing servers, deliberately not a
`hook_update_N`.** Changing the default in `defaultConfiguration()` only affects
*new* servers: Search API merges stored plugin configuration over the defaults,
so a server saved before this change keeps its stored `path` of `/solr`
regardless of the new default. An update hook is not used because the stored
value is legitimately operator-owned — a site may front Wayfinder with a proxy
that keeps `/solr`, or point the server at a real Solr-compatible endpoint, and
silently rewriting that would break it.

To upgrade, for each Search API server using the **Wayfinder** backend:

1. Go to **Configuration → Search and metadata → Search API**, edit the server.
2. Change **Base path** from `/solr` to `/wayfinder`.
3. Save, then confirm the server's *View* page still reports the Wayfinder
   version. A wrong base path does not error on that read — `viewSettings()`
   swallows the failure and simply omits the version row; what reports the
   server as unreachable is Search API's own availability check, which pings
   `{core}/admin/ping`.

Sites that export configuration should update `path` in the server's exported
`search_api.server.*.yml` too.

## Not supported

Deliberate descopes, each with its reason. (Each is also marked with a
`ponytail:` comment at its site in `src/`.)

- **Two Drupal sites sharing one core** (`DocumentBuilder`) — ids are
  `<index_id>-<item_id>`, with no `search_api_solr`-style site-hash component,
  so **one core per site is the supported topology**. This is a decision, not a
  pending simplification: several sites on one host means several Wayfinder
  processes with one core each, which the server already assumes — it serves a
  single core per process.

  Nothing enforces this. Point two sites at one core and documents whose index
  and item ids coincide overwrite each other silently, with no error on either
  side. The module cannot detect it; keep the topology correct by
  configuration.
- **Search API data types** (`FieldMapper`, `WayfinderBackend::supportsDataType()`).
  The six defaults (`text`, `string`, `integer`, `decimal`, `date`, `boolean`)
  plus the `search_api_solr` non-default types that round-trip on Wayfinder's
  existing schema types are supported: `solr_string_storage`,
  `solr_string_docvalues`, `solr_text_unstemmed`, `solr_text_omit_norms`,
  `solr_text_wstoken`, `solr_text_suggester`, and `solr_text_spellcheck` (the
  last two index into the fixed sink fields described under
  [Autocomplete and spellcheck](#autocomplete-and-spellcheck)).

  Two indexability divergences on the newly-supported types are accepted and
  documented at their `ponytail:` sites, not silently papered over:
  - `solr_string_storage` / `solr_string_docvalues` are *storage/docValues-only*
    in Solr (`indexed=false`). Wayfinder has no unindexed field, so they are
    indexed (and therefore queryable) here — a superset of their Solr
    capability. A field deliberately kept out of search by typing it
    storage-only **becomes searchable** on Wayfinder.
  - The `solr_text_*` variants all collapse onto `text_general`, so their Solr
    analyzer-chain distinctions (unstemmed vs. omit-norms vs. whitespace-
    tokenized) are not preserved — a scoring-quality divergence, not a data-
    integrity one.

  Still **not** supported, each with its reason rather than a silent omission:
  - `solr_date_range` — Wayfinder's `date` type holds a single instant, not a
    `[start TO end]` range; needs a new server-side date-range type.
  - `solr_text_custom` / `solr_text_custom_omit_norms` — `search_api_solr`'s
    escape hatch for site-defined analyzer chains (`SolrFieldType` entities);
    the preset has no equivalent.
  - `location` / `rpt` — spatial types, not yet scoped.
- **Per-facet settings beyond the four** (`QueryBuilder::buildFacets()`) —
  each facet's `limit`/`min_count`/`sort`/`missing` *is* expressed
  independently, as local params on that facet's own `facet.field`
  (`{!key=<delta> facet.limit=10 ...}<field>`), so two facets on one field keep
  their own settings. Nothing else is per-facet: Wayfinder honours
  `facet.prefix`/`facet.method` in neither form, and `facet.range.*` only
  globally, so a range facet cannot disagree with another one.

## Testing

The PHPUnit suite is hermetic — no network, no Docker:

```bash
composer install
vendor/bin/phpunit
```

**Checkout location matters.** Six of the unit test classes
(`WayfinderClientTest`, `WayfinderBackendTest`, `QueryBuilderTest`,
`ResponseParserTest`, `DocumentBuilderTest`, `FieldMapperTest`) load
ground-truth Solr fixtures from `solr-ref/` in the Wayfinder server repository,
resolved relatively as `tests/src/Unit/../../../../../solr-ref/`. Those paths
only resolve when this module is checked out **inside** the Wayfinder
repository at `drupal/search_api_wayfinder/`. A standalone clone will fail those
tests on missing fixtures; the other nine classes run anywhere.

The same applies to the end-to-end integration harness, which is Docker-based
and installs a live Drupal site against a live Wayfinder built from
`presets/search-api.toml`: its `docker-compose.yml` reaches four directories up
for the server's `Dockerfile` and preset. Run it from the Wayfinder repository
root:

```bash
WAYFINDER_INTEGRATION=1 bash drupal/search_api_wayfinder/tests/integration/run.sh
```

It is env-gated so it never runs as part of a default `vendor/bin/phpunit`.

## License

GPL-2.0-or-later. Portions of the field-naming and wire-format behaviour are
ported from [`search_api_solr`](https://www.drupal.org/project/search_api_solr),
which carries the same license.
