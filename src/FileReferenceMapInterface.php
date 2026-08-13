<?php

namespace Drupal\search_api_wayfinder;

/**
 * Maps files to the indexed items that reference them.
 */
interface FileReferenceMapInterface {

  /**
   * Records that an index item references a file.
   */
  public function record(string $indexId, int $fileId, string $itemId): void;

  /**
   * Returns the index items that reference a file.
   *
   * @return array
   *   An ordered list of arrays containing "index" and "item" keys.
   */
  public function itemsForFile(int $fileId): array;

  /**
   * Forgets all references to a file.
   */
  public function forgetFile(int $fileId): void;

  /**
   * Clears all file references.
   */
  public function clear(): void;

}
