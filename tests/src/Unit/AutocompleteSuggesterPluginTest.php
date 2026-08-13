<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_wayfinder\Unit;

use Drupal\Component\FileCache\FileCacheFactory;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\search_api\Backend\BackendInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Query\QueryInterface;
use Drupal\search_api\ServerInterface;
use Drupal\search_api_autocomplete\Attribute\SearchApiAutocompleteSuggester;
use Drupal\search_api_autocomplete\SearchInterface;
use Drupal\search_api_autocomplete\Suggester\SuggesterManager;
use Drupal\search_api_wayfinder\Plugin\search_api\backend\WayfinderBackend;
use Drupal\search_api_wayfinder\Plugin\search_api_autocomplete\suggester\Spellcheck;
use Drupal\search_api_wayfinder\Plugin\search_api_autocomplete\suggester\Suggester;
use PHPUnit\Framework\TestCase;

/**
 * Tests the Wayfinder Search API Autocomplete suggester plugins.
 */
class AutocompleteSuggesterPluginTest extends TestCase {

  public function testPluginDefinitions(): void {
    $plugins = [
      Suggester::class => 'search_api_wayfinder_suggester',
      Spellcheck::class => 'search_api_wayfinder_spellcheck',
    ];

    foreach ($plugins as $class => $expectedId) {
      $attributes = (new \ReflectionClass($class))->getAttributes(SearchApiAutocompleteSuggester::class);
      $this->assertCount(1, $attributes);
      $definition = $attributes[0]->newInstance();
      $this->assertSame($expectedId, $definition->id);
      $this->assertNotNull($definition->label);
      $this->assertNotNull($definition->description);
    }
  }

  public function testAutocompletePluginManagerDiscoversBothPlugins(): void {
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
    $moduleHandler->method('moduleExists')->willReturn(TRUE);
    $root = dirname(__DIR__, 3);
    $namespaces = new \ArrayObject([
      'Drupal\\search_api_wayfinder' => [$root . '/src'],
      // Attribute discovery intentionally skips plugins with dependencies in
      // unavailable namespaces. Include the two installed soft dependencies
      // exactly as Drupal's real container.namespaces parameter does.
      'Drupal\\search_api_autocomplete' => [$root . '/vendor/drupal/search_api_autocomplete/src'],
      'Drupal\\search_api' => [$root . '/vendor/drupal/search_api/src'],
    ]);

    $previousConfiguration = FileCacheFactory::getConfiguration();
    $previousPrefix = FileCacheFactory::getPrefix();
    FileCacheFactory::setConfiguration([FileCacheFactory::DISABLE_CACHE => TRUE]);
    FileCacheFactory::setPrefix('search-api-wayfinder-test');
    try {
      $definitions = (new SuggesterManager($namespaces, $cache, $moduleHandler))->getDefinitions();
    }
    finally {
      FileCacheFactory::setConfiguration($previousConfiguration);
      FileCacheFactory::setPrefix($previousPrefix);
    }

    $this->assertArrayHasKey('search_api_wayfinder_suggester', $definitions);
    $this->assertArrayHasKey('search_api_wayfinder_spellcheck', $definitions);
  }

  public function testSuggestersSupportOnlySearchesUsingAnAutocompleteCapableWayfinderBackend(): void {
    [$search, , $server] = $this->searchWithBackend($this->createMock(WayfinderBackend::class), TRUE);

    $this->assertTrue(Suggester::supportsSearch($search));
    $this->assertTrue(Spellcheck::supportsSearch($search));

    [$unsupportedSearch] = $this->searchWithBackend($this->createMock(WayfinderBackend::class), FALSE);
    $this->assertFalse(Suggester::supportsSearch($unsupportedSearch));
    $this->assertFalse(Spellcheck::supportsSearch($unsupportedSearch));

    [$otherSearch] = $this->searchWithBackend($this->createMock(BackendInterface::class), TRUE);
    $this->assertFalse(Suggester::supportsSearch($otherSearch));
    $this->assertFalse(Spellcheck::supportsSearch($otherSearch));
  }

  public function testSuggestersDoNotSupportAnIndexWithoutAValidServer(): void {
    $index = $this->createMock(IndexInterface::class);
    $index->method('hasValidServer')->willReturn(FALSE);
    $index->expects($this->never())->method('getServerInstance');
    $search = $this->createMock(SearchInterface::class);
    $search->method('getIndex')->willReturn($index);

    $this->assertFalse(Suggester::supportsSearch($search));
    $this->assertFalse(Spellcheck::supportsSearch($search));
  }

  public function testSuggesterConfigurationFormListsServerIndexesAndSiteLanguages(): void {
    $backend = $this->createMock(WayfinderBackend::class);
    [$search, $index, $server] = $this->searchWithBackend($backend, TRUE);
    $index->method('id')->willReturn('current');

    $otherIndex = $this->createMock(IndexInterface::class);
    $otherIndex->method('id')->willReturn('other');
    $otherIndex->method('label')->willReturn('Other index');
    $server->method('getIndexes')->willReturn([$index, $otherIndex]);

    $english = $this->createMock(LanguageInterface::class);
    $english->method('getId')->willReturn('en');
    $english->method('getName')->willReturn('English');
    $french = $this->createMock(LanguageInterface::class);
    $french->method('getId')->willReturn('fr');
    $french->method('getName')->willReturn('French');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getLanguages')->willReturn(['en' => $english, 'fr' => $french]);

    $plugin = (new Suggester([], 'search_api_wayfinder_suggester', [], $languageManager))->setSearch($search);
    $plugin->setStringTranslation($this->createMock(TranslationInterface::class));
    $form = $plugin->buildConfigurationForm([], new FormState());

    $this->assertSame(['search_api/index' => '', 'drupal/langcode' => 'any'], $plugin->defaultConfiguration());
    $this->assertSame('current', $form['search_api/index']['#default_value']);
    $this->assertSame(['any', 'current', 'other'], array_keys($form['search_api/index']['#options']));
    $this->assertSame(
      ['any', 'multilingual', 'en', 'fr', LanguageInterface::LANGCODE_NOT_SPECIFIED],
      array_keys($form['drupal/langcode']['#options'])
    );
    $this->assertSame('any', $form['drupal/langcode']['#default_value']);
  }

  public function testSuggesterSubmitStoresPerIndexAndLanguageConfiguration(): void {
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $plugin = new Suggester([], 'search_api_wayfinder_suggester', [], $languageManager);
    $formState = (new FormState())->setValues([
      'search_api/index' => 'products',
      'drupal/langcode' => 'fr',
    ]);
    $form = [];

    $plugin->submitConfigurationForm($form, $formState);

    $this->assertSame([
      'search_api/index' => 'products',
      'drupal/langcode' => 'fr',
    ], $plugin->getConfiguration());
  }

  public function testSuggesterDelegatesWithConfiguredIndexAndLanguageContext(): void {
    $backend = $this->createMock(WayfinderBackend::class);
    [$search] = $this->searchWithBackend($backend, TRUE);
    $query = $this->createMock(QueryInterface::class);
    $expected = [];
    $query->expects($this->once())
      ->method('addCondition')
      ->with('search_api_language', 'fr');
    $backend->expects($this->once())
      ->method('getAutocompleteSuggestions')
      ->with($query, $search, 'fox', 'quick fox')
      ->willReturn($expected);

    $plugin = new Suggester([
      'search_api/index' => 'products',
      'drupal/langcode' => 'fr',
    ], 'search_api_wayfinder_suggester', [], $this->createMock(LanguageManagerInterface::class));
    $plugin->setSearch($search);

    $this->assertSame($expected, $plugin->getAutocompleteSuggestions($query, 'fox', 'quick fox'));
  }

  public function testSuggesterAnyConfigurationDelegatesWithoutContextFilters(): void {
    $backend = $this->createMock(WayfinderBackend::class);
    [$search] = $this->searchWithBackend($backend, TRUE);
    $query = $this->createMock(QueryInterface::class);
    $query->expects($this->never())->method('addCondition');
    $backend->expects($this->once())
      ->method('getAutocompleteSuggestions')
      ->with($query, $search, 'fo', 'fox')
      ->willReturn([]);

    $plugin = new Suggester([
      'search_api/index' => 'any',
      'drupal/langcode' => 'any',
    ], 'search_api_wayfinder_suggester', [], $this->createMock(LanguageManagerInterface::class));
    $plugin->setSearch($search);

    $this->assertSame([], $plugin->getAutocompleteSuggestions($query, 'fo', 'fox'));
  }

  public function testSpellcheckHasNoConfigurationAndDelegatesCompleteInput(): void {
    $backend = $this->createMock(WayfinderBackend::class);
    [$search] = $this->searchWithBackend($backend, TRUE);
    $query = $this->createMock(QueryInterface::class);
    $backend->expects($this->once())
      ->method('getSpellcheckAutocompleteSuggestions')
      ->with($query, 'qwick fox')
      ->willReturn([]);

    $plugin = new Spellcheck([], 'search_api_wayfinder_spellcheck', []);
    $plugin->setSearch($search);

    $this->assertSame([], $plugin->defaultConfiguration());
    $this->assertSame([], $plugin->buildConfigurationForm([], new FormState()));
    $this->assertSame([], $plugin->getAutocompleteSuggestions($query, 'fox', 'qwick fox'));
  }

  public function testUnavailableBackendReturnsNoSuggestionsWithoutDelegating(): void {
    [$search] = $this->searchWithBackend($this->createMock(BackendInterface::class), TRUE);
    $query = $this->createMock(QueryInterface::class);

    $suggester = new Suggester(
      [],
      'search_api_wayfinder_suggester',
      [],
      $this->createMock(LanguageManagerInterface::class),
    );
    $suggester->setSearch($search);
    $spellcheck = new Spellcheck([], 'search_api_wayfinder_spellcheck', []);
    $spellcheck->setSearch($search);

    $this->assertSame([], $suggester->getAutocompleteSuggestions($query, 'fo', 'fox'));
    $this->assertSame([], $spellcheck->getAutocompleteSuggestions($query, 'fo', 'fox'));
  }

  /**
   * @return array{SearchInterface, IndexInterface, ServerInterface}
   */
  private function searchWithBackend(BackendInterface $backend, bool $supportsAutocomplete): array {
    $server = $this->createMock(ServerInterface::class);
    $server->method('getBackend')->willReturn($backend);
    $server->method('supportsFeature')->willReturn($supportsAutocomplete);

    $index = $this->createMock(IndexInterface::class);
    $index->method('hasValidServer')->willReturn(TRUE);
    $index->method('getServerInstance')->willReturn($server);

    $search = $this->createMock(SearchInterface::class);
    $search->method('getIndex')->willReturn($index);

    return [$search, $index, $server];
  }

}
