<?php

namespace Drupal\search_api_wayfinder;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;

/**
 * Stores the index items that reference each file.
 */
final class FileReferenceMap implements FileReferenceMapInterface {

  /**
   * Constructs a file reference map.
   */
  public function __construct(
    private readonly KeyValueStoreInterface $store,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(string $indexId, int $fileId, string $itemId): void {
    $key = $this->key($fileId);
    $items = $this->itemsForFile($fileId);
    $reference = [
      'index' => $indexId,
      'item' => $itemId,
    ];

    if (!in_array($reference, $items, TRUE)) {
      $items[] = $reference;
      $this->store->set($key, $items);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function itemsForFile(int $fileId): array {
    $items = $this->store->get($this->key($fileId), []);
    return is_array($items) ? $items : [];
  }

  /**
   * {@inheritdoc}
   */
  public function forgetFile(int $fileId): void {
    $this->store->delete($this->key($fileId));
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): void {
    $this->store->deleteAll();
  }

  /**
   * Builds the storage key for a file.
   */
  private function key(int $fileId): string {
    return 'file:' . $fileId;
  }

}
