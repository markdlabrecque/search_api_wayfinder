<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder\Plugin\search_api\processor;

use Drupal\Component\Utility\Bytes;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Item\FieldInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorPluginBase;
use Drupal\search_api\SearchApiException;
use Drupal\search_api_wayfinder\Cache\ExtractionCacheInterface;
use Drupal\search_api_wayfinder\ExtractFileValidator;
use Drupal\search_api_wayfinder\FileReferenceMapInterface;
use Drupal\search_api_wayfinder\Plugin\search_api\backend\WayfinderBackend;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides shared configuration and extraction behavior for file processors.
 */
abstract class FileExtractionProcessorBase extends ProcessorPluginBase implements PluginFormInterface {

  /**
   * Constructs a file extraction processor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected ?EntityTypeManagerInterface $entityTypeManager = NULL,
    protected ?LoggerInterface $logger = NULL,
    protected ?ExtractionCacheInterface $extractionCache = NULL,
    protected ?QueueInterface $queue = NULL,
    protected ?FileReferenceMapInterface $fileMap = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->entityTypeManager = $container->get('entity_type.manager');
    $processor->logger = $container->get('logger.channel.search_api_wayfinder');
    $processor->extractionCache = $container->get('search_api_wayfinder.extraction_cache');
    $processor->queue = $container->get('queue')->get('wayfinder_extraction');
    $processor->fileMap = $container->get('search_api_wayfinder.file_reference_map');
    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function supportsIndex(IndexInterface $index): bool {
    $server = $index->getServerInstanceIfAvailable();
    return $server !== NULL && $server->getBackendId() === 'wayfinder';
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'extraction_mode' => 'inline',
      'excluded_extensions' => ExtractFileValidator::DEFAULT_EXCLUDED_EXTENSIONS,
      'max_filesize' => '0',
      'excluded_private' => FALSE,
      'number_indexed' => 0,
      'number_first_bytes' => '0',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $configuration = $this->getConfiguration();

    $form['extraction_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Extraction mode'),
      '#options' => [
        'inline' => $this->t('Inline'),
        'queue' => $this->t('Queue'),
      ],
      '#default_value' => $configuration['extraction_mode'],
    ];
    $form['excluded_extensions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded file extensions'),
      '#description' => $this->t('Enter one file extension per line.'),
      '#default_value' => str_replace(' ', "\n", $configuration['excluded_extensions']),
    ];
    $form['max_filesize'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum file size'),
      '#description' => $this->t('Enter a byte size such as 5 MB. Use 0 or leave empty for no limit.'),
      '#default_value' => $configuration['max_filesize'],
    ];
    $form['excluded_private'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude private files'),
      '#default_value' => $configuration['excluded_private'],
    ];
    $form['number_indexed'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum files indexed per field'),
      '#description' => $this->t('Use 0 for no limit.'),
      '#default_value' => $configuration['number_indexed'],
      '#min' => 0,
    ];
    $form['number_first_bytes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Maximum extracted text size'),
      '#description' => $this->t('Enter a byte size such as 1 MB. Use 0 or leave empty for no limit.'),
      '#default_value' => $configuration['number_first_bytes'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    foreach (['max_filesize', 'number_first_bytes'] as $key) {
      $value = (string) $form_state->getValue($key, '');
      if ($value !== '' && $value !== '0'
        && (!Bytes::validate($value) || Bytes::toNumber($value) <= 0)) {
        $form_state->setErrorByName($key, $this->t('Enter a valid byte size, or use 0 for no limit.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    foreach (['extraction_mode', 'max_filesize', 'excluded_private', 'number_first_bytes'] as $key) {
      if (array_key_exists($key, $values)) {
        $this->configuration[$key] = $values[$key];
      }
    }

    if (array_key_exists('number_indexed', $values)) {
      $this->configuration['number_indexed'] = (int) $values['number_indexed'];
    }
    if (array_key_exists('excluded_extensions', $values)) {
      $this->configuration['excluded_extensions'] = $this->normalizeExtensions((string) $values['excluded_extensions']);
    }
  }

  /**
   * Gets the content entity represented by an item, or NULL if unavailable.
   */
  protected function getEntity(ItemInterface $item): ?ContentEntityInterface {
    try {
      $entity = $item->getOriginalObject()->getValue();
    }
    catch (SearchApiException) {
      return NULL;
    }
    return $entity instanceof ContentEntityInterface ? $entity : NULL;
  }

  /**
   * Extracts files and adds their text to each supplied index field.
   *
   * @param \Drupal\file\FileInterface[] $files
   *   Files to extract.
   * @param \Drupal\search_api\Item\FieldInterface[] $indexFields
   *   Computed fields that receive each non-empty extraction.
   */
  protected function extractFiles(array $files, array $indexFields, ItemInterface $item): void {
    foreach ($files as $file) {
      if (!$file instanceof FileInterface) {
        continue;
      }

      $this->recordReference($file, $item);
      if ($this->configuration['extraction_mode'] === 'queue') {
        $this->queue?->createItem([
          'file_id' => (int) $file->id(),
          'index_id' => (string) $this->index->id(),
          'item_id' => (string) $item->getId(),
        ]);
        continue;
      }

      $backend = $this->getWayfinderBackend();
      if ($backend === NULL) {
        continue;
      }

      try {
        $text = $this->extractOrGetFromCache($file, $backend);
      }
      catch (SearchApiException $exception) {
        $this->logger?->error('Could not extract text from file {file}: {message}', [
          'file' => $file->getFileUri(),
          'message' => $exception->getMessage(),
          'exception' => $exception,
        ]);
        continue;
      }

      if ($text === '') {
        continue;
      }
      foreach ($indexFields as $indexField) {
        if ($indexField instanceof FieldInterface) {
          $indexField->addValue($text);
        }
      }
    }
  }

  /**
   * Returns cached extracted text or extracts and caches it.
   */
  protected function extractOrGetFromCache(FileInterface $file, WayfinderBackend $backend): string {
    if ($this->extractionCache !== NULL) {
      $cached = $this->extractionCache->get($file);
      if ($cached !== NULL) {
        return $cached;
      }
    }

    $text = $backend->extractContentFromFile($file->getFileUri());
    $this->extractionCache?->set($file, $text);
    return $text;
  }

  /**
   * Gets the configured index's Wayfinder backend.
   */
  private function getWayfinderBackend(): ?WayfinderBackend {
    $server = $this->index->getServerInstanceIfAvailable();
    if ($server === NULL) {
      return NULL;
    }
    $backend = $server->getBackend();
    return $backend instanceof WayfinderBackend ? $backend : NULL;
  }

  /**
   * Records a file-to-item relationship for later invalidation.
   */
  private function recordReference(FileInterface $file, ItemInterface $item): void {
    if ($this->fileMap !== NULL) {
      $this->fileMap->record(
        (string) $this->index->id(),
        (int) $file->id(),
        (string) $item->getId(),
      );
    }
  }

  /**
   * Normalizes a user-entered extension list for configuration storage.
   */
  private function normalizeExtensions(string $extensions): string {
    $normalized = [];
    foreach (preg_split('/\s+/', trim($extensions), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $extension) {
      $extension = ltrim(strtolower($extension), '.');
      if ($extension !== '' && !in_array($extension, $normalized, TRUE)) {
        $normalized[] = $extension;
      }
    }
    return implode(' ', $normalized);
  }

}
