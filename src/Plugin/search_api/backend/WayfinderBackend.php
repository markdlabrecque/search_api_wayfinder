<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder\Plugin\search_api\backend;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiBackend;
use Drupal\search_api\Backend\BackendPluginBase;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_autocomplete\SearchInterface;
use Drupal\search_api_autocomplete\Suggestion\SuggestionFactory;
use Drupal\search_api_wayfinder\DocumentBuilder;
use Drupal\search_api_wayfinder\FieldMapper;
use Drupal\search_api_wayfinder\QueryBuilder;
use Drupal\search_api_wayfinder\ResponseParser;
use Drupal\search_api_wayfinder\WayfinderClient;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Search API backend for a Solr-wire-compatible Wayfinder server.
 */
#[SearchApiBackend(
  id: 'wayfinder',
  label: new TranslatableMarkup('Wayfinder'),
  description: new TranslatableMarkup('Index items and query a Wayfinder server, a Solr-wire-compatible search backend.'),
)]
class WayfinderBackend extends BackendPluginBase implements PluginFormInterface {

  protected ClientInterface $httpClient;

  protected ?LanguageManagerInterface $languageManager = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $plugin */
    $plugin = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $plugin->httpClient = $container->get('http_client');
    $plugin->languageManager = $container->get('language_manager');
    return $plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'scheme' => 'http',
      'host' => 'localhost',
      'port' => 8983,
      'path' => '/wayfinder',
      'core' => '',
      'timeout' => 5,
      'commitWithin' => 1000,
      'username' => '',
      'password' => '',
      'highlight' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = $this->getConfiguration();

    $form['scheme'] = [
      '#type' => 'select',
      '#title' => $this->t('HTTP protocol'),
      '#options' => ['http' => 'http', 'https' => 'https'],
      '#default_value' => $config['scheme'],
    ];
    $form['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wayfinder host'),
      '#default_value' => $config['host'],
      '#required' => TRUE,
    ];
    $form['port'] = [
      '#type' => 'number',
      '#title' => $this->t('Wayfinder port'),
      '#default_value' => $config['port'],
      '#required' => TRUE,
    ];
    $form['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base path'),
      '#default_value' => $config['path'],
    ];
    $form['core'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wayfinder core'),
      '#default_value' => $config['core'],
      '#required' => TRUE,
    ];
    $form['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Request timeout (seconds)'),
      '#default_value' => $config['timeout'],
    ];
    $form['commitWithin'] = [
      '#type' => 'number',
      '#title' => $this->t('Commit within (milliseconds)'),
      '#default_value' => $config['commitWithin'],
    ];
    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('HTTP Basic authentication username'),
      '#default_value' => $config['username'],
    ];
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('HTTP Basic authentication password'),
    ];
    $form_state->set('password', (string) $config['password']);
    $form['highlight'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Retrieve result highlighting from the server'),
      '#description' => $this->t('Ask Wayfinder for highlighted snippets and expose them as result data.'),
      '#default_value' => $config['highlight'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $host = $form_state->getValue('host');
    if (is_string($host) && trim($host) === '') {
      $form_state->setError($form['host'], $this->t('Wayfinder host cannot be empty.'));
    }

    $core = $form_state->getValue('core');
    if (is_string($core) && trim($core) === '') {
      $form_state->setError($form['core'], $this->t('Wayfinder core cannot be empty.'));
    }

    $port = $form_state->getValue('port');
    if ($port !== NULL && $port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
      $form_state->setError($form['port'], $this->t('Port must be between 1 and 65535.'));
    }

    $username = $form_state->getValue('username');
    $password = $form_state->getValue('password');
    if (!is_string($username) || !is_string($password)) {
      return;
    }

    $storedUsername = (string) ($this->configuration['username'] ?? '');
    $storedPassword = (string) ($form_state->get('password') ?? $this->configuration['password'] ?? '');
    if ($password === '' && $username === $storedUsername && $storedPassword !== '') {
      $password = $storedPassword;
      $form_state->setValue('password', $storedPassword);
    }

    if (str_contains($username, ':') || preg_match('/[\x00-\x1F\x7F]/', $username)) {
      $form_state->setErrorByName('username', $this->t('The username must not contain a colon or ASCII control characters.'));
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $password)) {
      $form_state->setErrorByName('password', $this->t('The password must not contain ASCII control characters.'));
    }
    if (($username === '') !== ($password === '')) {
      $form_state->setErrorByName($username === '' ? 'username' : 'password', $this->t('A username and password must either both be provided or both be empty.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    foreach (['scheme', 'host', 'port', 'path', 'core', 'timeout', 'commitWithin', 'username', 'password', 'highlight'] as $key) {
      if (array_key_exists($key, $values)) {
        $this->configuration[$key] = $values[$key];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedFeatures() {
    return [
      'search_api_facets',
      'search_api_facets_operator_or',
      'search_api_mlt',
      'search_api_grouping',
      'search_api_autocomplete',
      'search_api_spellcheck',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDataType($type) {
    return in_array($type, [
      'text',
      'string',
      'integer',
      'decimal',
      'date',
      'boolean',
      'solr_string_storage',
      'solr_string_docvalues',
      'solr_text_unstemmed',
      'solr_text_omit_norms',
      'solr_text_wstoken',
      'solr_text_suggester',
      'solr_text_spellcheck',
    ], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function isAvailable() {
    try {
      return $this->getClient()->ping();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function viewSettings() {
    $info = [[
      'label' => $this->t('Server URL'),
      'info' => $this->getCoreUrl(),
    ]];

    try {
      $system = $this->getClient()->adminSystem();
    }
    catch (SearchApiException) {
      return $info;
    }

    $version = $system['lucene']['wayfinder-spec-version'] ?? NULL;
    if (is_string($version) && $version !== '') {
      $info[] = [
        'label' => $this->t('Wayfinder version'),
        'info' => $version,
      ];
    }
    return $info;
  }

  /**
   * {@inheritdoc}
   */
  public function indexItems(IndexInterface $index, array $items) {
    $client = $this->getClient();
    $builder = new DocumentBuilder(new FieldMapper());
    $commitWithin = $this->getConfiguration()['commitWithin'] ?? 1000;
    $indexedIds = [];

    foreach ($items as $id => $item) {
      $client->update($builder->buildAddCommand($item, $index->id()), ['commitWithin' => $commitWithin]);
      $indexedIds[] = $id;
    }
    return $indexedIds;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItems(IndexInterface $index, array $item_ids) {
    $documentIds = array_map(
      static fn ($id): string => $index->id() . '-' . (string) $id,
      $item_ids,
    );
    $this->getClient()->update(['delete' => $documentIds]);
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAllIndexItems(IndexInterface $index, $datasource_id = NULL) {
    $query = 'index_id:"' . $index->id() . '"';
    if ($datasource_id) {
      $query .= ' AND ss_search_api_datasource:"' . $datasource_id . '"';
    }
    $this->getClient()->update(['delete' => ['query' => $query]]);
  }

  /**
   * {@inheritdoc}
   */
  public function search(QueryInterface $query): void {
    $builder = $this->queryBuilder();
    $client = $this->getClient();

    if ($query->getOption('search_api_mlt')) {
      $response = $client->mlt($builder->buildMlt($query));
    }
    else {
      $response = $client->select($builder->build($query, !empty($this->configuration['highlight'])));
    }
    (new ResponseParser($this->languageManager))->parse($response, $query);
  }

  /**
   * Extracts plain text from a file through Wayfinder's extract endpoint.
   */
  public function extractContentFromFile(string $filepath): string {
    $response = $this->getClient()->extract($filepath);
    return is_string($response['file'] ?? NULL) ? $response['file'] : '';
  }

  /**
   * Returns terms-component autocomplete suggestions.
   */
  public function getAutocompleteSuggestions(QueryInterface $query, SearchInterface $search, $incomplete_key, $user_input) {
    try {
      $response = $this->getClient()->terms(
        $this->queryBuilder()->buildAutocompleteTerms($query, (string) $incomplete_key),
      );
    }
    catch (SearchApiException) {
      return [];
    }

    $terms = [];
    $fields = $response['terms'] ?? [];
    if (!is_array($fields)) {
      return [];
    }
    foreach ($fields as $list) {
      if (!is_array($list)) {
        continue;
      }
      $values = array_values($list);
      for ($i = 0, $count = count($values); $i + 1 < $count; $i += 2) {
        if (is_string($values[$i]) && is_numeric($values[$i + 1])) {
          $terms[$values[$i]] = (int) $values[$i + 1];
        }
      }
    }

    $factory = new SuggestionFactory((string) $user_input);
    $prefixLength = mb_strlen((string) $incomplete_key);
    $suggestions = [];
    foreach ($terms as $term => $count) {
      $suggestions[] = $factory->createFromSuggestionSuffix(mb_substr($term, $prefixLength), $count);
    }
    return $suggestions;
  }

  /**
   * Returns spellcheck-component autocomplete suggestions.
   */
  public function getSpellcheckAutocompleteSuggestions(QueryInterface $query, string $incomplete): array {
    try {
      $response = $this->getClient()->select(
        $this->queryBuilder()->buildAutocompleteSpellcheck($query, $incomplete),
      );
    }
    catch (SearchApiException) {
      return [];
    }

    $flat = $response['spellcheck']['suggestions'] ?? [];
    if (!is_array($flat)) {
      return [];
    }
    $factory = new SuggestionFactory($incomplete);
    $suggestions = [];
    $seen = [];
    $values = array_values($flat);
    for ($i = 0, $count = count($values); $i + 1 < $count; $i += 2) {
      $details = $values[$i + 1];
      if (!is_array($details) || !is_array($details['suggestion'] ?? NULL)) {
        continue;
      }
      foreach ($details['suggestion'] as $member) {
        $word = is_string($member) ? $member : (is_array($member) ? ($member['word'] ?? NULL) : NULL);
        if (!is_string($word) || isset($seen[$word])) {
          continue;
        }
        $seen[$word] = TRUE;
        $suggestions[] = $factory->createFromSuggestedKeys($word);
      }
    }
    return $suggestions;
  }

  /**
   * Returns suggester-component autocomplete suggestions.
   */
  public function getSuggesterAutocompleteSuggestions(QueryInterface $query, string $incomplete, array $contextFilterTags): array {
    try {
      $response = $this->getClient()->suggest(
        $this->queryBuilder()->buildAutocompleteSuggester($query, $incomplete, $contextFilterTags),
      );
    }
    catch (SearchApiException) {
      return [];
    }

    $dictionaries = $response['suggest'] ?? [];
    if (!is_array($dictionaries)) {
      return [];
    }
    $factory = new SuggestionFactory($incomplete);
    $suggestions = [];
    $seen = [];
    foreach ($dictionaries as $queries) {
      if (!is_array($queries)) {
        continue;
      }
      foreach ($queries as $phrases) {
        $entries = is_array($phrases) ? ($phrases['suggestions'] ?? []) : [];
        if (!is_array($entries)) {
          continue;
        }
        foreach ($entries as $entry) {
          $term = is_array($entry) ? ($entry['term'] ?? NULL) : NULL;
          if (!is_string($term) || isset($seen[$term])) {
            continue;
          }
          $seen[$term] = TRUE;
          $suggestions[] = $factory->createFromSuggestedKeys($term);
        }
      }
    }
    return $suggestions;
  }

  /**
   * Builds the configured core URL.
   */
  protected function getCoreUrl(): string {
    $config = $this->getConfiguration();
    $path = '/' . ltrim((string) $config['path'], '/');
    return sprintf(
      '%s://%s:%s%s/%s',
      $config['scheme'],
      $config['host'],
      $config['port'],
      rtrim($path, '/'),
      $config['core'],
    );
  }

  /**
   * Builds the HTTP transport for this backend configuration.
   */
  protected function getClient(): WayfinderClient {
    $config = $this->getConfiguration();
    return new WayfinderClient(
      $this->httpClient,
      $this->getCoreUrl(),
      (float) ($config['timeout'] ?? 5),
      (string) ($config['username'] ?? ''),
      (string) ($config['password'] ?? ''),
    );
  }

  private function queryBuilder(): QueryBuilder {
    return new QueryBuilder(new FieldMapper(), $this->languageManager);
  }

}
