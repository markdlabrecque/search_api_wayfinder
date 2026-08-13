<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\Item;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\Query\ResultSet;

/**
 * Parses Wayfinder /select responses into Search API result sets.
 */
class ResponseParser {

  private readonly FieldMapper $fieldMapper;

  public function __construct(private readonly ?LanguageManagerInterface $languageManager = NULL) {
    $this->fieldMapper = new FieldMapper();
  }

  /**
   * Populates and returns the result set already owned by the query.
   */
  public function parse(array $response, QueryInterface $query): ResultSet {
    $resultSet = $query->getResults();
    [$count, $docs] = $this->extractResultDocs($response, $query);
    $resultSet->setResultCount($count);

    $index = $query->getIndex();
    $prefix = $index->id() . '-';
    $highlighting = isset($response['highlighting']) && is_array($response['highlighting'])
      ? $response['highlighting']
      : NULL;
    $languages = $highlighting === NULL ? [] : $this->resolvedLanguages($query);

    $items = [];
    foreach ($docs as $doc) {
      if (!is_array($doc)) {
        continue;
      }
      $docId = (string) ($doc['id'] ?? '');
      $itemId = str_starts_with($docId, $prefix) ? substr($docId, strlen($prefix)) : $docId;
      $item = new Item($index, $itemId);
      $item->setScore((float) ($doc['score'] ?? 1.0));

      if ($highlighting !== NULL) {
        $entry = $highlighting[$docId] ?? [];
        $highlighted = is_array($entry)
          ? $this->highlightedFields($entry, $index, $languages)
          : [];
        if ($highlighted !== []) {
          $item->setExtraData('highlighted_fields', $highlighted);
        }
      }
      $items[$itemId] = $item;
    }
    $resultSet->setResultItems($items);

    $facets = $this->parseFacets($response, $query);
    if ($facets !== NULL) {
      $resultSet->setExtraData('search_api_facets', $facets);
    }

    $spellcheck = $this->parseSpellcheck($response);
    if ($spellcheck !== NULL) {
      $resultSet->setExtraData('search_api_spellcheck', $spellcheck);
    }

    return $resultSet;
  }

  /**
   * Extracts normal or grouped result documents and their result count.
   *
   * @return array{0: int, 1: array}
   */
  private function extractResultDocs(array $response, QueryInterface $query): array {
    $grouping = $query->getOption('search_api_grouping', []);
    if (is_array($grouping) && !empty($grouping['use_grouping'])) {
      $fieldId = $grouping['fields'][0] ?? NULL;
      $field = is_string($fieldId) ? $query->getIndex()->getField($fieldId) : NULL;
      if ($field !== NULL) {
        $fieldName = $this->fieldMapper->sortFieldName(
          $fieldId,
          $field->getType(),
          $this->fieldMapper->isMultiValued($field),
          NULL,
        );
        $grouped = $response['grouped'][$fieldName] ?? [];
        if (is_array($grouped)) {
          $docs = [];
          foreach ($grouped['groups'] ?? [] as $group) {
            if (is_array($group)) {
              $groupDocs = $group['doclist']['docs'] ?? [];
              if (is_array($groupDocs)) {
                array_push($docs, ...$groupDocs);
              }
            }
          }
          return [(int) ($grouped['ngroups'] ?? 0), $docs];
        }
      }
    }

    $body = isset($response['response']) && is_array($response['response'])
      ? $response['response']
      : [];
    $docs = isset($body['docs']) && is_array($body['docs']) ? $body['docs'] : [];
    return [(int) ($body['numFound'] ?? 0), $docs];
  }

  /**
   * Parses flat facet term/count pairs, or NULL when facets were not requested.
   */
  private function parseFacets(array $response, QueryInterface $query): ?array {
    $requested = $query->getOption('search_api_facets', []);
    if (!is_array($requested) || $requested === []) {
      return NULL;
    }

    $returned = $response['facet_counts']['facet_fields'] ?? [];
    $returned = is_array($returned) ? $returned : [];
    $facets = [];
    foreach ($requested as $delta => $facet) {
      if (!is_array($facet)) {
        continue;
      }
      $fieldId = $facet['field'] ?? NULL;
      $field = is_string($fieldId) ? $query->getIndex()->getField($fieldId) : NULL;
      if ($field === NULL) {
        continue;
      }

      $mappedName = $this->fieldMapper->fieldName(
        $fieldId,
        $field->getType(),
        $this->fieldMapper->isMultiValued($field),
      );
      $safeDelta = is_string($delta) && preg_match('/^[A-Za-z0-9_:-]+$/D', $delta) === 1;
      $responseKey = $safeDelta && array_key_exists($delta, $returned) ? $delta : $mappedName;
      $pairs = $returned[$responseKey] ?? NULL;
      if (!is_array($pairs)) {
        continue;
      }

      $terms = [];
      $values = array_values($pairs);
      $length = count($values);
      for ($i = 0; $i + 1 < $length; $i += 2) {
        $terms[] = [
          'count' => (int) $values[$i + 1],
          'filter' => $values[$i] === NULL ? '!' : '"' . (string) $values[$i] . '"',
        ];
      }
      $facets[$delta] = $terms;
    }
    return $facets;
  }

  /**
   * Maps dynamic highlighting names back to Search API field IDs.
   *
   * @param array<string, mixed> $entry
   * @param string[] $languages
   *
   * @return array<string, array<int, string>>
   */
  private function highlightedFields(array $entry, IndexInterface $index, array $languages): array {
    $highlighted = [];
    foreach ($index->getFields() as $fieldId => $field) {
      $fieldId = (string) $fieldId;
      foreach ($languages as $language) {
        $fieldName = $this->fieldMapper->fieldName(
          $fieldId,
          $field->getType(),
          $this->fieldMapper->isMultiValued($field),
          $language,
        );
        if (!isset($entry[$fieldName]) || !is_array($entry[$fieldName])) {
          continue;
        }
        $highlighted[$fieldId] = array_values(array_map('strval', $entry[$fieldName]));
        break;
      }
    }
    return $highlighted;
  }

  /**
   * Resolves search_api_language conditions in query traversal order.
   *
   * @return string[]
   */
  private function resolvedLanguages(QueryInterface $query): array {
    $group = $query->getConditionGroup();
    if (!$group instanceof ConditionGroupInterface) {
      return [FieldMapper::LANGUAGE_UNSPECIFIED];
    }

    $languages = [];
    $this->collectLanguages($group, $languages);
    if ($languages !== []) {
      return array_values(array_unique($languages));
    }
    if ($this->languageManager !== NULL) {
      foreach ($this->languageManager->getLanguages() as $language) {
        $languages[] = $language->getId();
      }
    }
    return $languages === [] ? [FieldMapper::LANGUAGE_UNSPECIFIED] : array_values(array_unique($languages));
  }

  /**
   * @param string[] $languages
   */
  private function collectLanguages(ConditionGroupInterface $group, array &$languages): void {
    foreach ($group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $this->collectLanguages($condition, $languages);
      }
      elseif ($condition instanceof ConditionInterface && $condition->getField() === 'search_api_language') {
        $values = is_array($condition->getValue()) ? $condition->getValue() : [$condition->getValue()];
        foreach ($values as $value) {
          if (is_string($value) && $value !== '') {
            $languages[] = $value;
          }
        }
      }
    }
  }

  /**
   * Parses Solr's flat spellcheck named lists.
   */
  private function parseSpellcheck(array $response): ?array {
    if (!isset($response['spellcheck']) || !is_array($response['spellcheck'])) {
      return NULL;
    }

    $parsed = ['suggestions' => []];
    $suggestions = $response['spellcheck']['suggestions'] ?? [];
    if (is_array($suggestions)) {
      $values = array_values($suggestions);
      $length = count($values);
      for ($i = 0; $i + 1 < $length; $i += 2) {
        $term = $values[$i];
        $details = $values[$i + 1];
        if (!is_string($term) || !is_array($details) || !isset($details['suggestion']) || !is_array($details['suggestion'])) {
          continue;
        }
        $words = [];
        foreach ($details['suggestion'] as $member) {
          if (is_string($member)) {
            $words[] = $member;
          }
          elseif (is_array($member) && isset($member['word']) && is_string($member['word'])) {
            $words[] = $member['word'];
          }
        }
        if ($words !== []) {
          $parsed['suggestions'][$term] = $words;
        }
      }
    }

    $collations = $response['spellcheck']['collations'] ?? NULL;
    if (is_array($collations)) {
      $values = array_values($collations);
      $length = count($values);
      for ($i = 0; $i + 1 < $length; $i += 2) {
        if ($values[$i] === 'collation' && is_string($values[$i + 1])) {
          $parsed['collation'] = $values[$i + 1];
          break;
        }
      }
    }

    return $parsed;
  }

}
