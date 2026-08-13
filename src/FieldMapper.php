<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\SearchApiException;

/**
 * Maps Search API fields and values to their Wayfinder representations.
 */
class FieldMapper {

  public const LANGUAGE_UNSPECIFIED = 'und';

  public const SUGGESTER_SINK_FIELD = 'twm_suggest';

  /**
   * Search API data type prefixes used by search_api_solr dynamic fields.
   *
   * @var array<string, string>
   */
  private const TYPE_PREFIXES = [
    'text' => 't',
    'string' => 's',
    'integer' => 'it',
    'decimal' => 'ft',
    'date' => 'd',
    'boolean' => 'b',
    'solr_string_storage' => 'z',
    'solr_string_docvalues' => 'zdv',
    'solr_text_unstemmed' => 'tu',
    'solr_text_omit_norms' => 'to',
    'solr_text_wstoken' => 'tw',
  ];

  /**
   * Maps a Search API field to its Wayfinder field name.
   */
  public function fieldName(string $fieldId, string $type, bool $multiValued, string $language = self::LANGUAGE_UNSPECIFIED): string {
    if ($type === 'solr_text_suggester') {
      return self::SUGGESTER_SINK_FIELD;
    }
    if ($type === 'solr_text_spellcheck') {
      return 'spellcheck_' . $this->spellcheckDictionary($language);
    }

    $prefix = self::TYPE_PREFIXES[$type] ?? $type;
    if (str_starts_with($prefix, 't')) {
      return $this->encodeSolrName($prefix . 'm;' . $language . '_' . $fieldId);
    }

    return $this->encodeSolrName($prefix . ($multiValued ? 'm' : 's') . '_' . $fieldId);
  }

  /**
   * Returns the dictionary identifier used by the spellcheck sink.
   */
  public function spellcheckDictionary(string $language): string {
    return str_replace('-', '_', $language);
  }

  /**
   * Encodes characters that are not safe in Solr field names.
   */
  public function encodeSolrName(string $name): string {
    return (string) preg_replace_callback(
      '/[^A-Za-z0-9_]/',
      static fn (array $match): string => '_X' . strtolower(bin2hex($match[0])) . '_',
      $name,
    );
  }

  /**
   * Maps a field to the name used for sorting or grouping.
   *
   * A non-NULL language signals the sort/index path. NULL signals grouping,
   * which uses the ordinary mapped field.
   */
  public function sortFieldName(string $fieldId, string $type, bool $multiValued, ?string $language = NULL): string {
    if ($language !== NULL && ($this->isTextType($type) || $this->isStringType($type))) {
      return $this->encodeSolrName('sort_' . $fieldId);
    }

    return $this->fieldName($fieldId, $type, $multiValued, $language ?? self::LANGUAGE_UNSPECIFIED);
  }

  /**
   * Determines cardinality from definitions along the indexed property path.
   */
  public function isMultiValued(FieldInterface $field): bool {
    try {
      $properties = $field->getIndex()->getPropertyDefinitions($field->getDatasourceId());
    }
    catch (SearchApiException $e) {
      return FALSE;
    }

    foreach (explode(IndexInterface::PROPERTY_PATH_SEPARATOR, $field->getPropertyPath()) as $name) {
      if (!isset($properties[$name])) {
        return FALSE;
      }

      $definition = $properties[$name];
      if ($definition instanceof FieldDefinitionInterface) {
        $storage = $definition->getFieldStorageDefinition();
        if ($storage instanceof FieldStorageDefinitionInterface && $storage->getCardinality() !== 1) {
          return TRUE;
        }
      }
      elseif ($definition->isList()) {
        return TRUE;
      }

      $definition = $this->unwrapProperty($definition);
      if (!$definition instanceof ComplexDataDefinitionInterface) {
        return FALSE;
      }
      $properties = $definition->getPropertyDefinitions();
    }

    return FALSE;
  }

  /**
   * Formats a field value for indexing.
   */
  public function formatValue(mixed $value, string $type): mixed {
    if ($type === 'date') {
      return gmdate('Y-m-d\TH:i:s\Z', (int) $value);
    }
    if ($type === 'boolean') {
      return $value ? 'true' : 'false';
    }
    if ($this->isTextType($type) && $value instanceof \Stringable) {
      return (string) $value;
    }

    return $value;
  }

  /**
   * Formats a field value for a Lucene filter expression.
   */
  public function filterValue(mixed $value, string $type): string {
    $formatted = $this->formatValue($value, $type);
    if ($this->isTextType($type) || $this->isStringType($type) || $type === 'boolean') {
      return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $formatted) . '"';
    }

    return (string) $formatted;
  }

  private function isTextType(string $type): bool {
    return $type === 'text' || str_starts_with($type, 'solr_text_');
  }

  private function isStringType(string $type): bool {
    return $type === 'string' || str_starts_with($type, 'solr_string_');
  }

  /**
   * Unwraps list items and reference targets before path traversal continues.
   */
  private function unwrapProperty(DataDefinitionInterface $definition): ?DataDefinitionInterface {
    while (TRUE) {
      if ($definition instanceof ListDataDefinitionInterface) {
        $definition = $definition->getItemDefinition();
        continue;
      }
      if ($definition instanceof DataReferenceDefinitionInterface) {
        $definition = $definition->getTargetDefinition();
        continue;
      }
      return $definition;
    }
  }

}
