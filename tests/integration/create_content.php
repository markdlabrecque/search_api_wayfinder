<?php
// Deterministic corpus for issue #19's live capability checks: a target node
// with a distinctive fulltext term, a related rocket document for facets and
// MoreLikeThis, filler documents, and a file-only token for extraction.

use Drupal\node\Entity\Node;

// Issue #262 tracer: an attachment whose text exists NOWHERE else in the
// corpus, so a fulltext hit for `wayfinderattachment262` can only come from
// /update/extract having indexed the file's contents. file_save_data() writes
// to the public stream wrapper and returns a managed File entity, which the
// node's field_attachments (created in setup_server_index.php) references.
$attachment_text = "This text lives only inside the attached file. "
  . "The searchable token is wayfinderattachment262.";
// Drupal 11 dropped the procedural file_save_data(); the file_system service
// + a managed File entity is the version-stable way to produce the fixture.
$uri = \Drupal::service('file_system')->saveData(
  $attachment_text,
  'public://wayfinder-attachment-262.txt',
  \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE,
);
if ($uri === FALSE) {
  throw new \RuntimeException('Failed to write the attachment fixture file.');
}
$file = \Drupal\file\Entity\File::create([
  'uri' => $uri,
  'status' => 1,
  'uid' => 1,
]);
$file->save();
echo "attachment file id: " . $file->id() . " (uri $uri)\n";

$target = Node::create([
  'type' => 'article',
  'title' => 'The wayfinderroundtrip beacon guides lost travellers',
  'body' => [
    'value' => 'This node exists solely to prove the search_api_wayfinder round trip: index it into a real Wayfinder core, search for wayfinderroundtrip, get this node back.',
    'format' => 'basic_html',
  ],
  'field_category' => ['rocket'],
  'status' => 1,
]);
$target->save();
echo "target node id: " . $target->id() . "\n";

$related = Node::create([
  'type' => 'article',
  'title' => 'Rocket guidance for the beacon mission',
  'body' => [
    'value' => 'A related rocket document shares the beacon guidance vocabulary.',
    'format' => 'basic_html',
  ],
  'field_category' => ['rocket'],
  'status' => 1,
]);
$related->save();

echo "related node id: " . $related->id() . "\n";

// Issue #41 relevance corpus: exactly two title-only matches and one
// body-only match for `report`. No other fixture title/body contains the
// token, so a title boost has an observable ordering contract.
$report_title_a = Node::create([
  'type' => 'article',
  'title' => 'Annual report for the beacon mission',
  'body' => [
    'value' => 'A publication about navigation and planning.',
    'format' => 'basic_html',
  ],
  'field_category' => ['mission'],
  'status' => 1,
]);
$report_title_a->save();

$report_title_b = Node::create([
  'type' => 'article',
  'title' => 'Financial report overview',
  'body' => [
    'value' => 'A publication about budgets and planning.',
    'format' => 'basic_html',
  ],
  'field_category' => ['mission'],
  'status' => 1,
]);
$report_title_b->save();

$report_body = Node::create([
  'type' => 'article',
  'title' => 'Research archive',
  'body' => [
    'value' => 'The report is stored in the body of this document.',
    'format' => 'basic_html',
  ],
  'field_category' => ['mission'],
  'status' => 1,
]);
$report_body->save();

$fillers = [
  ['title' => 'A lazy afternoon in the garden', 'body' => 'Spent the afternoon reading in the garden.', 'category' => 'garden'],
  ['title' => 'About our mission', 'body' => 'We build search infrastructure and believe in open standards.', 'category' => 'mission'],
];
foreach ($fillers as $data) {
  Node::create([
    'type' => 'article',
    'title' => $data['title'],
    'body' => ['value' => $data['body'], 'format' => 'basic_html'],
    'field_category' => [$data['category']],
    'status' => 1,
  ])->save();
}

// Two byte-identical keyword fixtures make the relevance tie-break contract
// observable: equal scores must fall through to title and then item id.
foreach (['mission', 'mission'] as $category) {
  Node::create([
    'type' => 'article',
    'title' => 'Wayfinder deterministic tie fixture',
    'body' => [
      'value' => 'wayfinderdeterministictie',
      'format' => 'basic_html',
    ],
    'field_category' => [$category],
    'status' => 1,
  ])->save();
}

// The attachment node: its title/body deliberately do NOT contain the token,
// so the only way the token can be found by search is through the extracted
// file content. This is the end of the #262 vertical slice.
$attached = Node::create([
  'type' => 'article',
  'title' => 'An attached document with no relevance term',
  'body' => [
    'value' => 'The body is innocuous prose. The searchable content is in the attachment only.',
    'format' => 'basic_html',
  ],
  'field_category' => ['mission'],
  'field_attachments' => [
    ['target_id' => $file->id()],
  ],
  'status' => 1,
]);
$attached->save();
echo "attachment node id: " . $attached->id() . " (file id " . $file->id() . ")\n";

file_put_contents('/opt/drupal/wayfinder_fixture.json', json_encode([
  'target_node_id' => $target->id(),
  'related_node_id' => $related->id(),
  'attachment_node_id' => $attached->id(),
  'report_title_a_id' => $report_title_a->id(),
  'report_title_b_id' => $report_title_b->id(),
  'report_body_id' => $report_body->id(),
], JSON_THROW_ON_ERROR));

echo "content created\n";
