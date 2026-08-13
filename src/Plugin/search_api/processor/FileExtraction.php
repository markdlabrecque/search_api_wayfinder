<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder\Plugin\search_api\processor;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\search_api\Attribute\SearchApiProcessor;
use Drupal\search_api\Datasource\DatasourceInterface;
use Drupal\search_api\Item\ItemInterface;
use Drupal\search_api\Processor\ProcessorProperty;

/**
 * Extracts text from files directly attached to indexed entities.
 */
#[SearchApiProcessor(
  id: 'wayfinder_file_extraction',
  label: new TranslatableMarkup('Wayfinder file extraction'),
  description: new TranslatableMarkup('Indexes text extracted from attached files through Wayfinder.'),
  stages: [
    'add_properties' => 0,
  ],
)]
class FileExtraction extends FileExtractionProcessorBase {

  /**
   * Prefix used for processor-defined attachment properties.
   */
  private const PROPERTY_PREFIX = 'saw_';

  /**
   * {@inheritdoc}
   */
  public function getPropertyDefinitions(?DatasourceInterface $datasource = NULL): array {
    if ($datasource !== NULL) {
      return [];
    }

    $properties = [];
    foreach ($this->fileFieldDefinitions() as $fieldName => $definition) {
      $label = $definition->getLabel();
      $properties[self::PROPERTY_PREFIX . $fieldName] = new ProcessorProperty([
        'label' => $this->t('Extracted text from @field', ['@field' => $label]),
        'description' => $this->t('Text extracted by Wayfinder from files in @field.', ['@field' => $label]),
        'type' => 'text',
        'processor_id' => $this->getPluginId(),
        'is_list' => TRUE,
      ]);
    }
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public function addFieldValues(ItemInterface $item): void {
    $entity = $this->getEntity($item);
    if ($entity === NULL || $this->entityTypeManager === NULL) {
      return;
    }

    $configuredFields = [];
    $itemFields = $item->getFields();
    foreach ($this->fileFieldDefinitions() as $fieldName => $definition) {
      $propertyPath = self::PROPERTY_PREFIX . $fieldName;
      $indexFields = $this->getFieldsHelper()->filterForPropertyPath($itemFields, NULL, $propertyPath);
      if ($indexFields !== []) {
        $configuredFields[$fieldName] = $indexFields;
      }
    }
    if ($configuredFields === []) {
      return;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');
    foreach ($configuredFields as $fieldName => $indexFields) {
      if (!$entity->hasField($fieldName)) {
        continue;
      }

      $fileIds = [];
      foreach ($entity->get($fieldName)->getValue() as $value) {
        if (isset($value['target_id'])) {
          $fileIds[] = $value['target_id'];
        }
      }
      if ($fileIds === []) {
        continue;
      }

      $files = $fileStorage->loadMultiple(array_values(array_unique($fileIds)));
      $this->extractFiles($files, $indexFields, $item);
    }
  }

  /**
   * Returns file-typed field definitions from every index datasource.
   *
   * @return array<string, \Drupal\Core\Field\FieldDefinitionInterface>
   *   Definitions keyed by field machine name.
   */
  private function fileFieldDefinitions(): array {
    $fields = [];
    foreach ($this->index->getDatasources() as $datasource) {
      foreach ($datasource->getPropertyDefinitions() as $propertyName => $definition) {
        if ($definition->getType() !== 'file') {
          continue;
        }
        $fieldName = $definition->getName() ?: (string) $propertyName;
        $fields[$fieldName] = $definition;
      }
    }
    return $fields;
  }

}
