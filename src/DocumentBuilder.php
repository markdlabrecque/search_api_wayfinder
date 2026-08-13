<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

use Drupal\search_api\Item\ItemInterface;

/**
 * Builds Solr "add" update commands from Search API items.
 *
 * Document ids deliberately omit search_api_solr's site-hash component: each
 * Wayfinder core belongs to one site.
 */
class DocumentBuilder {

  public function __construct(
    private readonly FieldMapper $fieldMapper,
  ) {}

  /**
   * Builds a Solr "add" command for a single Search API item.
   *
   * @return array
   *   An ["add" => ["doc" => [...]]] structure.
   */
  public function buildAddCommand(ItemInterface $item, string $indexId): array {
    $language = $item->getLanguage();
    $doc = [
      'id' => $indexId . '-' . $item->getId(),
      'index_id' => $indexId,
      'ss_search_api_language' => $language,
      'ss_search_api_datasource' => $item->getDatasourceId(),
      'sm_context_tags' => [
        $this->fieldMapper->encodeSolrName('search_api/index:' . $indexId),
        $this->fieldMapper->encodeSolrName('drupal/langcode:' . $language),
      ],
    ];

    foreach ($item->getFields() as $field) {
      $values = $field->getValues();
      if ($values === []) {
        continue;
      }

      $type = $field->getType();
      $formatted = array_values(array_map(
        fn (mixed $value): mixed => $this->fieldMapper->formatValue($value, $type),
        $values,
      ));
      $multiValued = $this->fieldMapper->isMultiValued($field);
      $fieldId = $field->getFieldIdentifier();
      $name = $this->fieldMapper->fieldName(
        $fieldId,
        $type,
        $multiValued,
        $language,
      );

      // Suggester and spellcheck fields collapse into shared array sinks.
      if ($type === 'solr_text_suggester' || $type === 'solr_text_spellcheck') {
        $doc[$name] = array_merge($doc[$name] ?? [], $formatted);
        continue;
      }

      $isText = $type === 'text' || str_starts_with($type, 'solr_text_');
      $fieldValue = ($multiValued || $isText) ? $formatted : $formatted[0];

      // Preserve the generated tags when a user field maps to the same name.
      if ($name === 'sm_context_tags') {
        $doc[$name] = array_merge($doc[$name], $formatted);
      }
      else {
        $doc[$name] = $fieldValue;
      }

      // Text- and string-prefixed fields receive one language-agnostic sort
      // copy. The first field to claim a colliding sort key wins.
      if (($name[0] === 't' || $name[0] === 's')) {
        $sortName = $this->fieldMapper->encodeSolrName('sort_' . $fieldId);
        if (!array_key_exists($sortName, $doc)) {
          $doc[$sortName] = $formatted[0];
        }
      }
    }

    return [
      'add' => [
        'doc' => $doc,
      ],
    ];
  }

}
