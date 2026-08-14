<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\ConditionGroupInterface;
use Drupal\search_api\Query\ConditionInterface;
use Drupal\search_api\Query\QueryInterface;

/** Translates Search API queries into Wayfinder wire parameters. */
class QueryBuilder {

  private FieldMapper $fieldMapper;
  private array $languages = [FieldMapper::LANGUAGE_UNSPECIFIED];

  public function __construct(?FieldMapper $fieldMapper = NULL, private readonly ?LanguageManagerInterface $languageManager = NULL) {
    $this->fieldMapper = $fieldMapper ?? new FieldMapper();
  }

  public function build(QueryInterface $query, bool $highlight = FALSE): array {
    $index = $query->getIndex();
    $this->languages = $this->resolveLanguages($query);
    $params = ['fl' => 'id,index_id,score'];
    $keys = $query->getKeys();
    $params['qf'] = $this->buildQf($query, $index, $keys !== NULL);
    if ($keys === NULL) {
      $params['q'] = '*:*';
    }
    else {
      $params['q'] = $this->flattenKeys($keys);
      $params['defType'] = 'edismax';
    }

    $filters = ['index_id:"' . $index->id() . '"'];
    $conditions = $query->getConditionGroup();
    if (!$conditions->isEmpty()) {
      if ($conditions->getConjunction() === 'AND') {
        foreach ($conditions->getConditions() as $condition) {
          $filters[] = $this->buildConditionMember($condition, $index, $condition instanceof ConditionGroupInterface);
        }
      }
      else {
        $filters[] = $this->buildConditionGroup($conditions, $index, FALSE);
      }
    }
    $params['fq'] = count($filters) === 1 ? $filters[0] : $filters;
    $params += $this->buildFacets($query, $index);
    $params += $this->buildGrouping($query, $index);
    $params += $this->buildSpellcheck($query);
    if ($highlight) {
      $names = $this->mapFieldNames($this->fulltextFieldIds($query, $index), $index, $this->languages);
      $params += ['hl' => 'true', 'hl.fl' => implode(',', $names)];
    }
    $sort = $this->buildSort($query, $index);
    if ($sort !== '') {
      $params['sort'] = $sort;
    }
    return $params + $this->buildPaging($query);
  }

  public function buildMlt(QueryInterface $query): array {
    $index = $query->getIndex();
    $option = $query->getOption('search_api_mlt');
    if (!is_array($option) || !isset($option['id'])) {
      throw new \InvalidArgumentException('The search_api_mlt option must provide a seed item id.');
    }
    $this->languages = $this->resolveLanguages($query);
    return [
      'q' => 'id:' . $this->fieldMapper->filterValue($index->id() . '-' . $option['id'], 'string'),
      'mlt.fl' => implode(',', $this->mapFieldNames((array) ($option['fields'] ?? []), $index, $this->languages)),
      'mlt.mintf' => 1,
      'mlt.mindf' => 1,
      'mlt.maxqt' => 100,
      'mlt.maxntp' => 2000,
      'fq' => 'index_id:"' . $index->id() . '"',
    ] + $this->buildPaging($query);
  }

  public function buildAutocompleteTerms(QueryInterface $query, string $incomplete): array {
    $index = $query->getIndex();
    $this->languages = $this->resolveLanguages($query);
    $fields = array_values(array_unique($this->mapFieldNames($this->fulltextFieldIds($query, $index), $index, $this->languages)));
    return [
      'terms' => 'true',
      'terms.fl' => count($fields) === 1 ? $fields[0] : $fields,
      'terms.prefix' => $incomplete,
      'terms.limit' => (int) ($query->getOption('limit') ?? 10),
      'omitHeader' => 'true',
    ];
  }

  public function buildAutocompleteSpellcheck(QueryInterface $query, string $incomplete): array {
    $this->languages = $this->resolveLanguages($query);
    return [
      'spellcheck' => 'true',
      'spellcheck.q' => $incomplete,
      'spellcheck.dictionary' => $this->dictionaryParam(),
      'rows' => 0,
      'omitHeader' => 'true',
    ];
  }

  public function buildAutocompleteSuggester(QueryInterface $query, string $incomplete, array $contextFilterTags = []): array {
    $this->languages = $this->resolveLanguages($query);
    $dictionary = FieldMapper::LANGUAGE_UNSPECIFIED;
    foreach ($contextFilterTags as $key => $tag) {
      if (!str_starts_with($tag, 'drupal/langcode:')) {
        continue;
      }
      $language = substr($tag, strlen('drupal/langcode:'));
      if ($language === 'multilingual') {
        $mapped = array_map(fn (string $lang): string => $this->fieldMapper->spellcheckDictionary($lang), $this->languages);
        $dictionary = count($mapped) === 1 ? $mapped[0] : $mapped;
        if (count($this->languages) === 1) {
          $contextFilterTags[$key] = 'drupal/langcode:' . $this->languages[0];
        }
        else {
          $prefix = $this->fieldMapper->encodeSolrName('drupal/langcode:');
          $contextFilterTags[$key] = '(' . $prefix . implode(' ' . $prefix, $this->languages) . ')';
        }
      }
      else {
        $dictionary = $this->fieldMapper->spellcheckDictionary($language);
      }
      break;
    }
    $params = [
      'suggest' => 'true',
      'suggest.q' => $incomplete,
      'suggest.count' => (int) ($query->getOption('limit') ?? 10),
      'suggest.highlight' => 'false',
      'suggest.dictionary' => $dictionary,
      'omitHeader' => 'true',
    ];
    if ($contextFilterTags !== []) {
      $encoded = array_map(function (string $tag): string {
        return '+' . (str_starts_with($tag, '(') ? $tag : $this->fieldMapper->encodeSolrName($tag));
      }, $contextFilterTags);
      $params['suggest.cfq'] = implode(' ', $encoded);
    }
    return $params;
  }

  private function resolveLanguages(QueryInterface $query): array {
    $found = $this->findLanguageCondition($query->getConditionGroup());
    if ($found !== []) {
      return $found;
    }
    if ($this->languageManager !== NULL) {
      $languages = [];
      foreach ($this->languageManager->getLanguages() as $language) {
        $languages[] = $language->getId();
      }
      if ($languages !== []) {
        return $languages;
      }
    }
    return [FieldMapper::LANGUAGE_UNSPECIFIED];
  }

  private function findLanguageCondition(ConditionGroupInterface $group): array {
    foreach ($group->getConditions() as $condition) {
      if ($condition instanceof ConditionGroupInterface) {
        $found = $this->findLanguageCondition($condition);
        if ($found !== []) {
          return $found;
        }
      }
      elseif ($condition instanceof ConditionInterface && $condition->getField() === 'search_api_language') {
        $operator = strtoupper(trim((string) $condition->getOperator()));
        if ($operator === '=' && is_scalar($condition->getValue())) {
          return [(string) $condition->getValue()];
        }
        if ($operator === 'IN' && is_array($condition->getValue())) {
          return array_values(array_map('strval', array_filter($condition->getValue(), static fn ($v) => $v !== NULL)));
        }
      }
    }
    return [];
  }

  private function dictionaryParam() {
    $values = array_map(fn (string $language): string => $this->fieldMapper->spellcheckDictionary($language), $this->languages);
    return count($values) === 1 ? $values[0] : $values;
  }

  private function buildSpellcheck(QueryInterface $query): array {
    $option = $query->getOption('search_api_spellcheck');
    if (!is_array($option)) {
      return [];
    }
    $params = ['spellcheck' => 'true', 'spellcheck.dictionary' => $this->dictionaryParam()];
    if (!empty($option['keys'])) {
      $params['spellcheck.q'] = implode(' ', (array) $option['keys']);
    }
    if (!empty($option['collate'])) {
      $params['spellcheck.collate'] = 'true';
    }
    return $params;
  }

  private function buildPaging(QueryInterface $query): array {
    $params = [];
    if (($offset = $query->getOption('offset')) !== NULL) {
      $params['start'] = (int) $offset;
    }
    $limit = $query->getOption('limit');
    if ((int) $limit === -1) {
      $params['rows'] = PHP_INT_MAX;
    }
    elseif ($limit !== NULL) {
      $params['rows'] = (int) $limit;
    }
    return $params;
  }

  private function fulltextFieldIds(QueryInterface $query, IndexInterface $index): array {
    $requested = $query->getFulltextFields();
    return $requested === NULL ? $index->getFulltextFields() : array_intersect($index->getFulltextFields(), $requested);
  }

  private function mapFieldNames(array $ids, IndexInterface $index, array $languages): array {
    $names = [];
    foreach ($ids as $id) {
      $field = $index->getField($id);
      if (!$field) {
        continue;
      }
      $fieldLanguages = $this->isTextType($field->getType()) ? $languages : [FieldMapper::LANGUAGE_UNSPECIFIED];
      foreach ($fieldLanguages as $language) {
        $names[] = $this->fieldMapper->fieldName($id, $field->getType(), $this->fieldMapper->isMultiValued($field), $language);
      }
    }
    return $names;
  }

  private function buildQf(QueryInterface $query, IndexInterface $index, bool $applyBoost = TRUE): string {
    $names = [];
    foreach ($this->fulltextFieldIds($query, $index) as $id) {
      $field = $index->getField($id);
      if (!$field) {
        continue;
      }
      foreach ($this->isTextType($field->getType()) ? $this->languages : [FieldMapper::LANGUAGE_UNSPECIFIED] as $language) {
        $name = $this->fieldMapper->fieldName($id, $field->getType(), $this->fieldMapper->isMultiValued($field), $language);
        $boost = $field->getBoost();
        $names[] = $applyBoost && $boost != 1.0 ? $name . '^' . rtrim(rtrim(sprintf('%.2f', $boost), '0'), '.') : $name;
      }
    }
    return implode(' ', $names);
  }

  private function flattenKeys($keys, bool $nested = FALSE): string {
    if (is_string($keys)) {
      $special = ['\\', '+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '"', '~', '*', '?', ':', '/'];
      $escaped = str_replace($special, array_map(static fn ($char) => '\\' . $char, $special), $keys);
      return preg_match('/\s/', $escaped) ? '"' . $escaped . '"' : $escaped;
    }
    $parts = [];
    foreach ($keys as $key => $value) {
      if ($key === '#conjunction' || $key === '#negation') {
        continue;
      }
      $part = $this->flattenKeys($value, TRUE);
      if ($part !== '') {
        $parts[] = $part;
      }
    }
    $combined = implode(($keys['#conjunction'] ?? 'AND') === 'OR' ? ' OR ' : ' AND ', $parts);
    if (!empty($keys['#negation'])) {
      return count($parts) > 1 ? '-(' . $combined . ')' : '-' . $combined;
    }
    return $nested && count($parts) > 1 ? '(' . $combined . ')' : $combined;
  }

  private function buildConditionMember($condition, IndexInterface $index, bool $nested): string {
    if ($condition instanceof ConditionInterface) {
      return $this->buildCondition($condition, $index);
    }
    if ($condition instanceof ConditionGroupInterface) {
      return $this->buildConditionGroup($condition, $index, $nested);
    }
    throw new \InvalidArgumentException('Unsupported Search API condition member.');
  }

  private function buildConditionGroup(ConditionGroupInterface $group, IndexInterface $index, bool $parenthesize): string {
    $parts = [];
    foreach ($group->getConditions() as $condition) {
      $parts[] = $this->buildConditionMember($condition, $index, $condition instanceof ConditionGroupInterface);
    }
    $query = implode($group->getConjunction() === 'OR' ? ' OR ' : ' AND ', $parts);
    if ($parenthesize) {
      $query = '(' . $query . ')';
    }
    $tags = $group->getTags();
    return $tags === [] ? $query : '{!tag=' . implode(',', $tags) . '}' . $query;
  }

  private function buildCondition(ConditionInterface $condition, IndexInterface $index): string {
    $id = $condition->getField();
    $field = is_string($id) ? $index->getField($id) : NULL;
    if (!$field) {
      throw new \InvalidArgumentException('Condition field is missing or is not part of the index.');
    }
    $operator = strtoupper(trim((string) $condition->getOperator()));
    $value = $condition->getValue();
    $languages = $this->isTextType($field->getType()) ? $this->languages : [FieldMapper::LANGUAGE_UNSPECIFIED];
    $clauses = [];
    foreach ($languages as $language) {
      $name = $this->fieldMapper->fieldName($id, $field->getType(), $this->fieldMapper->isMultiValued($field), $language);
      $clauses[] = $this->buildConditionForFieldName($name, $operator, $value, $field->getType());
    }
    if (count($clauses) === 1) {
      return $clauses[0];
    }
    return '(' . implode($this->missingVariantSatisfies($operator, $value) ? ' AND ' : ' OR ', $clauses) . ')';
  }

  private function missingVariantSatisfies(string $operator, $value): bool {
    if ($operator === '=' && $value === NULL) return TRUE;
    if ($operator === '<>' && $value !== NULL) return TRUE;
    if ($operator === 'NOT BETWEEN') return TRUE;
    if ($operator === 'IN' && is_array($value) && in_array(NULL, $value, TRUE)) return TRUE;
    if ($operator === 'NOT IN' && is_array($value) && !in_array(NULL, $value, TRUE)) return TRUE;
    return FALSE;
  }

  private function buildConditionForFieldName(string $name, string $operator, $value, string $type): string {
    if (in_array($operator, ['BETWEEN', 'NOT BETWEEN'], TRUE)) {
      if (!is_array($value) || $value === [] || count($value) > 2) {
        throw new \InvalidArgumentException('BETWEEN requires an array of one or two values.');
      }
      if (count($value) === 1) {
        $operator = $operator === 'BETWEEN' ? '=' : '<>';
        $value = array_values($value)[0];
      }
    }
    if ($value === NULL) {
      return match ($operator) {
        '=' => '-' . $name . ':[* TO *]',
        '<>' => $name . ':[* TO *]',
        default => throw new \InvalidArgumentException('NULL is supported only with = and <> conditions.'),
      };
    }
    if ($value === '*' && !in_array($operator, ['=', 'BETWEEN', 'NOT BETWEEN'], TRUE)) {
      throw new \InvalidArgumentException('Unsupported operator for wildcard searches.');
    }
    $formatted = fn ($v) => $this->fieldMapper->filterValue($v, $type);
    return match ($operator) {
      '=' => $name . ':' . ($value === '*' ? '*' : $formatted($value)),
      '<>' => '(*:* -' . $name . ':' . $formatted($value) . ')',
      '<' => $name . ':[* TO ' . $formatted($value) . '}',
      '<=' => $name . ':[* TO ' . $formatted($value) . ']',
      '>' => $name . ':{' . $formatted($value) . ' TO *]',
      '>=' => $name . ':[' . $formatted($value) . ' TO *]',
      'BETWEEN' => $name . ':[' . $this->rangeValues($value, $type) . ']',
      'NOT BETWEEN' => '(*:* -' . $name . ':[' . $this->rangeValues($value, $type) . '])',
      'IN' => $this->inQuery($name, $value, $type),
      'NOT IN' => $this->notInQuery($name, $value, $type),
      default => throw new \InvalidArgumentException('Unsupported condition operator "' . $operator . '".'),
    };
  }

  private function rangeValues(array $values, string $type): string {
    if (count($values) !== 2) throw new \InvalidArgumentException('BETWEEN requires an array of exactly two values.');
    $values = array_values($values);
    $endpoint = fn ($v) => $v === NULL || $v === '*' ? '*' : $this->fieldMapper->filterValue($v, $type);
    return $endpoint($values[0]) . ' TO ' . $endpoint($values[1]);
  }

  private function listValues($value): array {
    if (!is_array($value) || $value === []) throw new \InvalidArgumentException('An empty array is not allowed for IN conditions.');
    if (in_array('*', $value, TRUE)) throw new \InvalidArgumentException('Unsupported operator for wildcard searches.');
    return array_values($value);
  }

  private function inQuery(string $name, $value, string $type): string {
    $values = $this->listValues($value);
    $hasNull = in_array(NULL, $values, TRUE);
    $values = array_values(array_filter($values, static fn ($v) => $v !== NULL));
    if ($values === []) return '(*:* -' . $name . ':[* TO *])';
    $parts = implode(' ', array_map(fn ($v) => $this->fieldMapper->filterValue($v, $type), $values));
    $query = count($values) === 1 ? $name . ':' . $parts : $name . ':(' . $parts . ')';
    return $hasNull ? '(' . $query . ' OR -' . $name . ':[* TO *])' : $query;
  }

  private function notInQuery(string $name, $value, string $type): string {
    $values = $this->listValues($value);
    $hasNull = in_array(NULL, $values, TRUE);
    $values = array_values(array_filter($values, static fn ($v) => $v !== NULL));
    if ($values === []) return $hasNull ? '(' . $name . ':[* TO *])' : '(*:* -' . $name . ':())';
    $parts = implode(' ', array_map(fn ($v) => $this->fieldMapper->filterValue($v, $type), $values));
    return $hasNull ? '(' . $name . ':[* TO *] -' . $name . ':(' . $parts . '))' : '(*:* -' . $name . ':(' . $parts . '))';
  }

  private function buildSort(QueryInterface $query, IndexInterface $index, ?array $sorts = NULL): string {
    $parts = [];
    foreach ($sorts ?? $query->getSorts() as $id => $direction) {
      $name = match ($id) {
        'search_api_relevance' => 'score',
        'search_api_id' => 'id',
        'search_api_datasource' => 'ss_search_api_datasource',
        'search_api_language' => 'ss_search_api_language',
        default => $this->sortFieldName($id, $index),
      };
      $parts[] = $name . ' ' . (strtolower(trim((string) $direction)) === 'desc' ? 'desc' : 'asc');
    }
    return implode(',', $parts);
  }

  private function sortFieldName(string $id, IndexInterface $index): string {
    $field = $index->getField($id);
    if (!$field) throw new \InvalidArgumentException('Sort field is not part of the index.');
    return $this->fieldMapper->sortFieldName($id, $field->getType(), $this->fieldMapper->isMultiValued($field), FieldMapper::LANGUAGE_UNSPECIFIED);
  }

  private function buildFacets(QueryInterface $query, IndexInterface $index): array {
    $facets = $query->getOption('search_api_facets') ?: [];
    if (!is_array($facets) || $facets === []) return [];
    $fields = [];
    foreach ($facets as $delta => $facet) {
      $id = $facet['field'] ?? NULL;
      $field = is_string($id) ? $index->getField($id) : NULL;
      if (!$field) throw new \InvalidArgumentException('Facet field is missing or is not part of the index.');
      $local = [];
      if (($facet['operator'] ?? NULL) === 'or') $local[] = 'ex=facet:' . $id;
      if (preg_match('/^[A-Za-z0-9_:-]+$/', (string) $delta)) $local[] = 'key=' . $delta;
      if (isset($facet['limit'])) $local[] = 'facet.limit=' . ((int) $facet['limit'] > 0 ? (int) $facet['limit'] : -1);
      if (isset($facet['min_count'])) $local[] = 'facet.mincount=' . (int) $facet['min_count'];
      if (isset($facet['sort'])) $local[] = 'facet.sort=' . $this->localParamValue((string) $facet['sort']);
      if (isset($facet['missing'])) $local[] = 'facet.missing=' . ($facet['missing'] ? 'true' : 'false');
      $language = $this->isTextType($field->getType()) ? FieldMapper::LANGUAGE_UNSPECIFIED : FieldMapper::LANGUAGE_UNSPECIFIED;
      $name = $this->fieldMapper->fieldName($id, $field->getType(), $this->fieldMapper->isMultiValued($field), $language);
      $fields[] = '{!' . implode(' ', $local) . '}' . $name;
    }
    return ['facet' => 'true', 'facet.field' => count($fields) === 1 ? $fields[0] : $fields];
  }

  private function localParamValue(string $value): string {
    return preg_match('/^[A-Za-z0-9_:.+-]+$/', $value) ? $value : '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
  }

  private function buildGrouping(QueryInterface $query, IndexInterface $index): array {
    $option = $query->getOption('search_api_grouping');
    if (!is_array($option) || empty($option['use_grouping'])) return [];
    $fields = [];
    foreach ((array) ($option['fields'] ?? []) as $id) {
      $field = $index->getField($id);
      if (!$field || $this->isTextType($field->getType()) || $this->fieldMapper->isMultiValued($field)) continue;
      $fields[] = $this->fieldMapper->sortFieldName($id, $field->getType(), FALSE);
    }
    if ($fields === []) return [];
    $params = ['group' => 'true', 'group.ngroups' => 'true', 'group.field' => count($fields) === 1 ? $fields[0] : $fields];
    if (isset($option['group_limit']) && (int) $option['group_limit'] !== 1) $params['group.limit'] = (int) $option['group_limit'];
    if (isset($option['group_offset'])) $params['group.offset'] = (int) $option['group_offset'];
    if (!empty($option['group_sort'])) $params['group.sort'] = $this->buildSort($query, $index, $option['group_sort']);
    if (!empty($option['truncate'])) $params['group.truncate'] = 'true';
    if (!empty($option['group_facet'])) $params['group.facet'] = 'true';
    return $params;
  }

  private function isTextType(string $type): bool {
    return $type === 'text' || str_starts_with($type, 'solr_text_');
  }

}
