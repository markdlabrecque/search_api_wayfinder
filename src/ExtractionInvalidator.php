<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\Utility\Utility;
use Psr\Log\LoggerInterface;

/**
 * Marks index items that reference changed files for reindexing.
 */
final class ExtractionInvalidator {

  public function __construct(
    private readonly FileReferenceMapInterface $map,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?LoggerInterface $logger = NULL,
  ) {}

  /**
   * Marks items referencing an updated file for reindexing.
   */
  public function onFileUpdate(FileInterface $file): void {
    $this->invalidate((int) $file->id());
  }

  /**
   * Marks items referencing a deleted file and forgets its references.
   */
  public function onFileDelete(FileInterface $file): void {
    $fileId = (int) $file->id();
    $this->invalidate($fileId);
    $this->map->forgetFile($fileId);
  }

  /**
   * Marks all index items recorded for a file as updated.
   */
  private function invalidate(int $fileId): void {
    $references = $this->map->itemsForFile($fileId);
    if ($references === []) {
      return;
    }

    $itemsByIndex = [];
    foreach ($references as $reference) {
      $indexId = (string) $reference['index'];
      [$datasourceId, $rawId] = Utility::splitCombinedId((string) $reference['item']);
      $itemsByIndex[$indexId][(string) $datasourceId][] = (string) $rawId;
    }

    $indexes = $this->entityTypeManager
      ->getStorage('search_api_index')
      ->loadMultiple(array_keys($itemsByIndex));

    foreach ($itemsByIndex as $indexId => $itemsByDatasource) {
      $index = $indexes[$indexId] ?? NULL;
      if (!$index instanceof IndexInterface) {
        $this->logger?->warning('Could not invalidate file references for missing Search API index "{index_id}".', [
          'index_id' => $indexId,
        ]);
        continue;
      }

      foreach ($itemsByDatasource as $datasourceId => $rawIds) {
        $index->trackItemsUpdated($datasourceId, $rawIds);
      }
    }
  }

}
