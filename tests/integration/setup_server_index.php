<?php
// Creates the standalone Wayfinder Search API server and a deterministic
// index for issue #19. The live run uses the maintained search-api preset,
// not a harness-local schema, and covers fulltext, facets, MLT, highlighting,
// extraction, and autocomplete through the backend's current feature flags.
// No search_api_solr, Solarium, or connector plugin is involved.

use Drupal\search_api\Entity\Server;
use Drupal\search_api\Entity\Index;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

// Issue #262 tracer: a file field on the article bundle. The
// wayfinder_file_extraction processor discovers it (file-typed field on an
// indexed datasource), declares a `saw_field_attachments` computed property,
// and populates it from /update/extract. Creating it here, before the index
// below, is what makes that property resolvable when the index field mapped
// to it is validated on save. Plain `file` fields only this slice -- media /
// entity:file are documented follow-ups, not this tracer.
FieldStorageConfig::create([
  'entity_type' => 'node',
  'field_name' => 'field_attachments',
  'type' => 'file',
  'cardinality' => -1,
])->save();
FieldConfig::create([
  'entity_type' => 'node',
  'bundle' => 'article',
  'field_name' => 'field_attachments',
  'label' => 'Attachments',
])->save();

// A controlled facet vocabulary keeps the live facet assertion deterministic.
FieldStorageConfig::create([
  'entity_type' => 'node',
  'field_name' => 'field_category',
  'type' => 'list_string',
  'cardinality' => 1,
  'settings' => ['allowed_values' => [
    'rocket' => 'Rocket',
    'garden' => 'Garden',
    'mission' => 'Mission',
  ]],
])->save();
FieldConfig::create([
  'entity_type' => 'node',
  'bundle' => 'article',
  'field_name' => 'field_category',
  'label' => 'Category',
])->save();

$server = Server::create([
  'id' => 'wf19_server',
  'name' => 'Wayfinder IT server',
  'description' => 'Issue #19 integration verification against a real Wayfinder instance.',
  'backend' => 'wayfinder',
  'backend_config' => [
    'scheme' => 'http',
    'host' => 'wayfinder',
    'port' => 8983,
    'path' => '/wayfinder',
    'core' => 'content',
    'timeout' => 5,
    'commitWithin' => 1000,
    'username' => 'operator',
    'password' => 'secret',
    // Enable highlighting only for the dedicated highlighting slice so a
    // server-side highlighting regression cannot mask facets or extraction.
    'highlight' => FALSE,
  ],
  'status' => TRUE,
]);
$server->save();

$index = Index::create([
  'id' => 'wf19_index',
  'name' => 'Wayfinder IT index',
  'server' => 'wf19_server',
  'status' => TRUE,
  'datasource_settings' => [
    'entity:node' => [
      'bundles' => [
        'default' => FALSE,
        'selected' => ['article', 'page'],
      ],
    ],
  ],
  'tracker_settings' => [
    'default' => [],
  ],
  'field_settings' => [
    // Issue #41 relevance policy: title matches are weighted three times
    // higher than the neutral body/file fields. These are Search API index
    // field settings (exportable and durable), not production scoring code.
    'title' => [
      'label' => 'Title',
      'datasource_id' => 'entity:node',
      'property_path' => 'title',
      'type' => 'text',
      'boost' => 3.0,
    ],
    'body' => [
      'label' => 'Body',
      'datasource_id' => 'entity:node',
      'property_path' => 'body',
      'type' => 'text',
      'boost' => 1.0,
    ],
    'category' => [
      'label' => 'Category',
      'datasource_id' => 'entity:node',
      'property_path' => 'field_category',
      'type' => 'string',
    ],
    // Issue #262: the extracted attachment text lands in its OWN fulltext
    // field with independent boost (decision 2), not appended to body. The
    // property path is the processor's index-level computed property
    // (datasource NULL), so no datasource_id here.
    'file_content' => [
      'label' => 'File content',
      'property_path' => 'saw_field_attachments',
      'type' => 'text',
      'boost' => 1.0,
    ],
  ],
  'options' => [
    'index_directly' => TRUE,
    'cron_limit' => 50,
  ],
  // Enable the extraction processor alongside the index. No settings of its
  // own in this tracer, so an empty config block.
  'processor_settings' => [
    'wayfinder_file_extraction' => [],
  ],
]);
$index->save();

echo "server + index created\n";
