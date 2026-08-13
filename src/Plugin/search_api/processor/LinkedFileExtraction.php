<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder\Plugin\search_api\processor;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorProperty;
use Drupal\search_api_wayfinder\Cache\ExtractionCacheInterface;
use Drupal\search_api_wayfinder\FileReferenceMapInterface;
use Drupal\search_api_wayfinder\LinkedFileDiscovererInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts text from files linked from indexed entities.
 */
#[SearchApiProcessor(
  id: 'wayfinder_linked_file_extraction',
  label: new TranslatableMarkup('Wayfinder linked file extraction'),
  description: new TranslatableMarkup('Indexes text extracted from files linked in text and link fields.'),
  stages: [
    'add_properties' => 0,
  ],
)]
class LinkedFileExtraction extends FileExtractionProcessorBase {

  /**
   * Constructs a linked-file extraction processor.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    array $plugin_definition,
    protected ?LinkedFileDiscovererInterface $linkedFileDiscoverer = NULL,
    ?EntityTypeManagerInterface $entityTypeManager = NULL,
    ?LoggerInterface $logger = NULL,
    ?ExtractionCacheInterface $extractionCache = NULL,
    ?QueueInterface $queue = NULL,
    ?FileReferenceMapInterface $fileMap = NULL,
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $entityTypeManager,
      $logger,
      $extractionCache,
      $queue,
      $fileMap,
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    /** @var static $processor */
    $processor = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $processor->linkedFileDiscoverer = $container->get('search_api_wayfinder.linked_file_discoverer');
    return $processor;
  }

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    if ($datasource !== NULL) {
      return [];
    }

    return [
      'saw_linked' => new ProcessorProperty([
        'label' => $this->t('Extracted text from linked files'),
        'description' => $this->t('Text extracted by Wayfinder from files linked in text and link fields.'),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $itemFields = $item->getFields();
    $indexFields = $this->getFieldsHelper()->filterForPropertyPath($itemFields, NULL, 'saw_linked');
    if ($indexFields === [] || $this->linkedFileDiscoverer === NULL) {
      return;
    }

    $entity = $this->getEntity($item);
    if ($entity === NULL) {
      return;
    }

    $files = [];
    foreach ($this->scannableFieldDefinitions() as $fieldName => $type) {
      if (!$entity->hasField($fieldName)) {
        continue;
      }

      foreach ($entity->get($fieldName)->getValue() as $value) {
        if (str_starts_with($type, 'text') && is_string($value['value'] ?? NULL)) {
          $files += $this->linkedFileDiscoverer->discoverFromHtml($value['value']);
        }
        elseif ($type === 'link' && is_string($value['uri'] ?? NULL)) {
          $files += $this->linkedFileDiscoverer->discoverFromLinkUri($value['uri']);
        }
      }
    }

    if ($files !== []) {
      $this->extractFiles($files, $indexFields, $item);
    }
  }

  /**
   * Returns text and link field definitions from every index datasource.
   *
   * @return array<string, string>
   *   Field types keyed by field machine name.
   */
  private function scannableFieldDefinitions(): array {
    $fields = [];
    foreach ($this->index->getDatasources() as $datasource) {
      foreach ($datasource->getPropertyDefinitions() as $propertyName => $definition) {
        $type = (string) $definition->getType();
        if ($type !== 'link' && !str_starts_with($type, 'text')) {
          continue;
        }
        $fieldName = $definition->getName() ?: (string) $propertyName;
        $fields[$fieldName] = $type;
      }
    }
    return $fields;
  }

}
