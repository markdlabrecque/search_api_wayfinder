<?php
// Drives the live Search API query API through the standalone `wayfinder`
// backend for issue #19. The deterministic checks cover fulltext, facets,
// MoreLikeThis, highlighting, file extraction, and terms autocomplete. Every
// capability is asserted; an upstream incompatibility is reported as a named
// failure rather than silently treated as a skip.
//
// This script exits non-zero (and prints a ROUNDTRIP: FAIL line) unless the
// node created by create_content.php comes back from a real Wayfinder core
// through WayfinderBackend::search(), so run.sh can fail loudly instead of
// silently reporting "0 results" as if that were fine.

use Drupal\search_api\Entity\Index;

$index = Index::load('wf19_index');
if (!$index) {
  echo "ROUNDTRIP: FAIL - index 'wf19_index' does not exist (setup_server_index.php did not run or failed)\n";
  exit(1);
}

$pmm = \Drupal::service('plugin.manager.search_api.parse_mode');
$fixture = json_decode((string) file_get_contents('/opt/drupal/wayfinder_fixture.json'), TRUE, 512, JSON_THROW_ON_ERROR);
$expected_target = 'entity:node/' . $fixture['target_node_id'] . ':en';
$expected_related = 'entity:node/' . $fixture['related_node_id'] . ':en';
$expected_attachment = 'entity:node/' . $fixture['attachment_node_id'] . ':en';
$expected_report_title_a = 'entity:node/' . $fixture['report_title_a_id'] . ':en';
$expected_report_title_b = 'entity:node/' . $fixture['report_title_b_id'] . ':en';
$expected_report_body = 'entity:node/' . $fixture['report_body_id'] . ':en';

$exit_code = 0;

// Issue #41 configuration-drift contract. Boosts are durable Search API
// index-field configuration, not production scoring policy: title is 3 and
// body plus extracted file text are explicitly 1.
$expected_boosts = ['title' => 3.0, 'body' => 1.0, 'file_content' => 1.0];
foreach ($expected_boosts as $field_id => $expected_boost) {
  $field = $index->getField($field_id);
  $actual_boost = $field ? (float) $field->getBoost() : NULL;
  echo "BOOST_CONFIG: $field_id expected=$expected_boost actual=" . ($actual_boost === NULL ? 'missing' : $actual_boost) . "\n";
  if ($actual_boost === NULL || abs($actual_boost - $expected_boost) > 0.0001) {
    echo "BOOST: FAIL - durable field boost drift for $field_id (expected $expected_boost)\n";
    $exit_code = 1;
  }
}

// Build the same three-field query that is sent by the backend and send it
// with the real client. Printing qf here is live request evidence, while the
// response docs below provide scored ordering evidence without guessing from
// a Search API result item's incidental array order.
try {
  $relevance_query = $index->query();
  $relevance_query->setParseMode($pmm->createInstance('terms'));
  $relevance_query->keys('report');
  $relevance_query->setFulltextFields(['title', 'body', 'file_content']);
  // Mirror the backend's language-aware builder. A bare builder defaults to
  // `und`, which is not necessarily the live site's configured language.
  $relevance_builder = new \Drupal\search_api_wayfinder\QueryBuilder(
    new \Drupal\search_api_wayfinder\FieldMapper(),
    \Drupal::languageManager(),
  );
  $wire_params = $relevance_builder->build($relevance_query);
  $wire_qf = (string) ($wire_params['qf'] ?? '');
  echo "WIRE_QF_REQUEST: $wire_qf\n";

  $field_mapper = new \Drupal\search_api_wayfinder\FieldMapper();
  $language_ids = array_map(
    static fn ($language): string => $language->getId(),
    array_values(\Drupal::languageManager()->getLanguages()),
  );
  if ($language_ids === []) {
    $language_ids = ['und'];
  }
  $expected_qf_tokens = [];
  foreach (['title', 'body', 'file_content'] as $field_id) {
    $field = $index->getField($field_id);
    foreach ($language_ids as $language_id) {
      $token = $field_mapper->fieldName(
        $field_id,
        $field->getType(),
        $field_mapper->isMultiValued($field),
        $language_id,
      );
      $expected_qf_tokens[] = $token . ($field_id === 'title' ? '^3' : '');
    }
  }
  // qf is a whitespace-separated set of field clauses; clause order is not
  // part of the wire contract and must not make this live check flaky.
  $actual_qf_tokens = preg_split('/\s+/', trim($wire_qf), -1, PREG_SPLIT_NO_EMPTY);
  sort($expected_qf_tokens);
  sort($actual_qf_tokens);
  if ($actual_qf_tokens !== $expected_qf_tokens) {
    echo "WIRE_QF: FAIL - expected tokens [" . implode(', ', $expected_qf_tokens) . "], got [" . implode(', ', $actual_qf_tokens) . "]\n";
    $exit_code = 1;
  }
  else {
    echo "WIRE_QF: PASS - live client request applies title ^3 and body/file ^1 defaults\n";
  }

  $wire_response = (new \Drupal\search_api_wayfinder\WayfinderClient(
    \Drupal::service('http_client'),
    'http://wayfinder:8983/wayfinder/content',
    5,
    'operator',
    'secret',
  ))->select($wire_params + ['rows' => 10, 'echoParams' => 'all']);
  $report_docs = $wire_response['response']['docs'] ?? [];
  $positions = [];
  foreach ($report_docs as $position => $doc) {
    $id = (string) ($doc['id'] ?? '');
    $score = (float) ($doc['score'] ?? 0);
    echo "REPORT_SCORE: position=$position id=$id score=$score\n";
    $positions[$id] = ['position' => $position, 'score' => $score];
  }
  $internal_id = static fn (string $id): string => 'wf19_index-' . $id;
  $title_a = $positions[$internal_id($expected_report_title_a)] ?? NULL;
  $title_b = $positions[$internal_id($expected_report_title_b)] ?? NULL;
  $body_only = $positions[$internal_id($expected_report_body)] ?? NULL;
  if (!$title_a || !$title_b || !$body_only ||
      $title_a['score'] <= 0 || $title_b['score'] <= 0 || $body_only['score'] <= 0 ||
      $title_a['position'] >= $body_only['position'] ||
      $title_b['position'] >= $body_only['position']) {
    echo "RANKING: FAIL - both title report matches must score and rank above the body-only report match\n";
    $exit_code = 1;
  }
  else {
    echo "RANKING: PASS - both title report matches outrank the body-only report match\n";
  }
}
catch (\Throwable $e) {
  echo "WIRE_QF/RANKING: FAIL - " . get_class($e) . ': ' . $e->getMessage() . "\n";
  $exit_code = 1;
}

// Wayfinder keeps its configured-core ping public, but all query endpoints
// require the backend's credentials. Prove both contracts before the normal
// authenticated Search API round trip below.
$unauthenticated = new \Drupal\search_api_wayfinder\WayfinderClient(
  \Drupal::service('http_client'),
  'http://wayfinder:8983/wayfinder/content',
);
if (!$unauthenticated->ping()) {
  echo "AUTH: FAIL - unauthenticated client could not ping the public endpoint\n";
  exit(1);
}
try {
  $unauthenticated->select(['q' => '*:*']);
  echo "AUTH: FAIL - unauthenticated select unexpectedly succeeded\n";
  exit(1);
}
catch (\Drupal\search_api\SearchApiException $e) {
  if ($e->getMessage() !== 'authentication required') {
    echo "AUTH: FAIL - unauthenticated select message was: " . $e->getMessage() . "\n";
    exit(1);
  }
}
echo "AUTH: PASS - public ping and exact unauthenticated select failure verified\n";

// Keep these operator probes isolated from the broader round-trip checks. A
// Wayfinder parse failure must be reported as a failed search, not converted
// into a plausible empty result set by the harness. The expected counts are
// derived from this script's five-node fixture and Wayfinder's eDisMax
// negative-only query behavior: causa and duis are absent, and the
// negative-only NOT causa query has no positive clause to select documents.
foreach ([
  'causa OR duis' => 0,
  'causa AND duis' => 0,
  'NOT causa' => 0,
] as $expression => $expected_count) {
  try {
    $query = $index->query();
    $query->setParseMode($pmm->createInstance('terms'));
    $query->keys($expression);
    $query->setFulltextFields(['title', 'body']);
    $results = $query->execute();
    $count = $results->getResultCount();
    echo "literal_operator_probe [$expression]: $count results\n";
    if ($count !== $expected_count) {
      echo "literal_operator_probe [$expression]: FAIL - expected $expected_count results from the deterministic fixture\n";
      $exit_code = 1;
    }
  }
  catch (\Throwable $e) {
    echo "literal_operator_probe [$expression]: FAIL - " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $exit_code = 1;
  }
}

// Issue #39's explicit-AND regression uses words that are present in the
// actual fixture, rather than stale corpus assumptions: only the related node
// contains both "beacon" and "guidance"; no fixture node contains both
// "rocket" and "unrelated" or both "garden" and "report".
foreach ([
  'beacon guidance' => 1,
  'rocket unrelated' => 0,
  'garden report' => 0,
] as $expression => $expected_count) {
  try {
    $query = $index->query();
    $query->setParseMode($pmm->createInstance('terms'));
    $query->keys($expression);
    $query->setFulltextFields(['title', 'body']);
    $count = $query->execute()->getResultCount();
    echo "explicit_and_probe [$expression]: $count results\n";
    if ($count !== $expected_count) {
      echo "explicit_and_probe [$expression]: FAIL - expected $expected_count results from the deterministic fixture\n";
      $exit_code = 1;
    }
  }
  catch (\Throwable $e) {
    echo "explicit_and_probe [$expression]: FAIL - " . get_class($e) . ': ' . $e->getMessage() . "\n";
    $exit_code = 1;
  }
}

try {
  $query = $index->query();
  $query->setParseMode($pmm->createInstance('terms'));
  $query->keys('wayfinderroundtrip');
  $query->setFulltextFields(['title', 'body']);
  $results = $query->execute();

  $count = $results->getResultCount();
  echo "fulltext_wayfinderroundtrip: $count results\n";

  $result_ids = array_keys($results->getResultItems());
  foreach ($result_ids as $result_id) {
    echo "  result item id: $result_id\n";
  }
  sort($result_ids);
  $expected_ids = [$expected_target];
  sort($expected_ids);
  $found_target = $result_ids === $expected_ids;

  if ($count < 1 || !$found_target) {
    echo "ROUNDTRIP: FAIL - expected at least 1 result for 'wayfinderroundtrip', got $count\n";
    $exit_code = 1;
  }
  else {
    echo "ROUNDTRIP: PASS - real index+search round trip through WayfinderBackend::search() succeeded\n";
  }
}
catch (\Throwable $e) {
  echo "ROUNDTRIP: FAIL - " . get_class($e) . ": " . $e->getMessage() . "\n";
  echo $e->getTraceAsString() . "\n";
  $exit_code = 1;
}

// Highlighting is isolated so an upstream highlighter regression cannot mask
// otherwise-working fulltext, facet, or extraction capabilities.
$backend = $index->getServerInstance()->getBackend();
$backend_config = $backend->getConfiguration();
try {
  $highlight_config = $backend_config;
  $highlight_config['highlight'] = TRUE;
  $backend->setConfiguration($highlight_config);

  $query = $index->query();
  $query->setParseMode($pmm->createInstance('terms'));
  $query->keys('wayfinderroundtrip');
  $query->setFulltextFields(['title', 'body']);
  $results = $query->execute();
  $has_highlight = FALSE;
  foreach ($results->getResultItems() as $item) {
    if ($item->hasExtraData('highlighted_fields')) {
      $has_highlight = TRUE;
      break;
    }
  }
  if (!$has_highlight) {
    echo "HIGHLIGHT: FAIL - Wayfinder returned no highlighted_fields for the dedicated query (upstream dynamic-field highlighting blocker)\n";
    $exit_code = 1;
  }
  else {
    echo "HIGHLIGHT: PASS - highlighted fields were returned and mapped by ResponseParser\n";
  }
}
catch (\Throwable $e) {
  echo "HIGHLIGHT: FAIL - " . get_class($e) . ': ' . $e->getMessage() . " (upstream dynamic-field highlighting blocker)\n";
  $exit_code = 1;
}
finally {
  $backend->setConfiguration($backend_config);
}

// Facets, MoreLikeThis, and highlighting are deliberately exercised through
// Search API rather than direct HTTP calls. A failure is reported as a live
// compatibility blocker instead of being softened into a skipped assertion.
try {
  $query = $index->query();
  $query->setParseMode($pmm->createInstance('terms'));
  $query->keys(NULL);
  $query->setFulltextFields(['title', 'body']);
  $query->setOption('search_api_facets', [
    'category' => [
      'field' => 'category',
      'limit' => 10,
      'min_count' => 1,
      'sort' => 'count',
    ],
  ]);
  $results = $query->execute();
  $facet_terms = $results->getExtraData('search_api_facets')['category'] ?? [];
  $rocket_count = 0;
  foreach ($facet_terms as $term) {
    if (($term['filter'] ?? '') === '"rocket"') {
      $rocket_count = (int) ($term['count'] ?? 0);
    }
  }
  if ($rocket_count < 2) {
    echo "FACETS: FAIL - expected the deterministic rocket facet count (>=2), got $rocket_count\n";
    $exit_code = 1;
  }
  else {
    echo "FACETS: PASS - category facet returned rocket=$rocket_count\n";
  }
}
catch (\Throwable $e) {
  echo "FACETS: FAIL - " . get_class($e) . ': ' . $e->getMessage() . " (Wayfinder facet compatibility blocker)\n";
  $exit_code = 1;
}

try {
  $query = $index->query();
  $query->setOption('search_api_mlt', [
    'id' => 'entity:node/' . $fixture['target_node_id'] . ':en',
    'fields' => ['title', 'body'],
  ]);
  $results = $query->execute();
  $result_ids = array_keys($results->getResultItems());
  sort($result_ids);
  if (!in_array($expected_related, $result_ids, TRUE) || in_array($expected_target, $result_ids, TRUE)) {
    echo "MLT: FAIL - expected related ID $expected_related and exclusion of seed $expected_target; got [" . implode(', ', $result_ids) . "]\n";
    $exit_code = 1;
  }
  else {
    echo "MLT: PASS - MoreLikeThis included $expected_related and excluded the seed document\n";
  }
}
catch (\Throwable $e) {
  echo "MLT: FAIL - " . get_class($e) . ': ' . $e->getMessage() . " (Wayfinder /mlt compatibility blocker)\n";
  $exit_code = 1;
}

// The autocomplete module is optional at runtime. This live check installs it
// in the ephemeral Drupal site, discovers the shipped Wayfinder suggester via
// the autocomplete plugin manager, and invokes the plugin's terms path. It
// does not claim support for an unavailable upstream SuggestComponent.
try {
  if (!class_exists('Drupal\\search_api_autocomplete\\Entity\\Search')) {
    throw new \RuntimeException('drupal/search_api_autocomplete is not installed');
  }
  $search = \Drupal\search_api_autocomplete\Entity\Search::create([
    'id' => 'wf19_autocomplete',
    'label' => 'Wayfinder integration autocomplete',
    'index_id' => 'wf19_index',
    'status' => TRUE,
  ]);
  $suggester_manager = \Drupal::service('plugin.manager.search_api_autocomplete.suggester');
  $definitions = $suggester_manager->getDefinitions();
  if (!isset($definitions['search_api_wayfinder_suggester'])) {
    throw new \RuntimeException('search_api_wayfinder_suggester was not discovered');
  }
  $suggester = $suggester_manager->createInstance('search_api_wayfinder_suggester', [
    'search_api/index' => 'wf19_index',
    'drupal/langcode' => 'any',
  ]);
  $suggester->setSearch($search);
  $query = $index->query();
  $query->setFulltextFields(['title', 'body']);
  $suggestions = $suggester->getAutocompleteSuggestions($query, 'wayfinder', 'wayfinder');
  $found = FALSE;
  foreach ($suggestions as $suggestion) {
    if ($suggestion->getSuggestionSuffix() === 'roundtrip') {
      $found = TRUE;
      break;
    }
  }
  if (!$found) {
    echo "AUTOCOMPLETE: FAIL - terms autocomplete did not suggest wayfinderroundtrip (upstream terms compatibility blocker)\n";
    $exit_code = 1;
  }
  else {
    echo "AUTOCOMPLETE: PASS - terms autocomplete suggested wayfinderroundtrip\n";
  }
}
catch (\Throwable $e) {
  echo "AUTOCOMPLETE: FAIL - " . get_class($e) . ': ' . $e->getMessage() . " (soft dependency or Wayfinder terms compatibility blocker)\n";
  $exit_code = 1;
}

// Issue #262 tracer end-to-end check: a fulltext query for a token that
// appears ONLY inside an attached file. The only way this returns the
// attachment node is if the wayfinder_file_extraction processor extracted the
// file's text via WayfinderBackend::extractContentFromFile() ->
// WayfinderClient::extract() (multipart POST /update/extract?extractOnly=true)
// during indexing, and that text is now searchable in the file_content field.
try {
  $query = $index->query();
  $query->setParseMode($pmm->createInstance('terms'));
  $query->keys('wayfinderattachment262');
  $query->setFulltextFields(['file_content']);
  $results = $query->execute();

  $result_ids = array_keys($results->getResultItems());
  sort($result_ids);
  $expected_ids = [$expected_attachment];
  sort($expected_ids);
  echo "fulltext_wayfinderattachment262 (file_content only): " . count($result_ids) . " results\n";

  if ($result_ids !== $expected_ids) {
    echo "EXTRACT: FAIL - expected exact attachment ID $expected_attachment, got [" . implode(', ', $result_ids) . "] (extraction/indexing did not populate the field)\n";
    $exit_code = 1;
  }
  else {
    echo "EXTRACT: PASS - file attachment text was extracted and returned the exact fixture ID $expected_attachment\n";
  }
}
catch (\Throwable $e) {
  echo "EXTRACT: FAIL - " . get_class($e) . ": " . $e->getMessage() . "\n";
  echo $e->getTraceAsString() . "\n";
  $exit_code = 1;
}

if ($exit_code !== 0) {
  throw new \RuntimeException('Search API round trip failed.');
}
