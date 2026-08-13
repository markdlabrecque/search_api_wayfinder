<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder\Cache;

use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\file\FileInterface;

/**
 * Stores extracted text in a key-value collection by file content hash.
 */
final class KeyValueExtractionCache implements ExtractionCacheInterface {

  public function __construct(
    private readonly KeyValueStoreInterface $store,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get(FileInterface $file): ?string {
    $value = $this->store->get($this->keyFor($file));
    return is_string($value) ? $value : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set(FileInterface $file, string $text): void {
    $this->store->set($this->keyFor($file), $text);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(FileInterface $file): void {
    $this->store->delete($this->keyFor($file));
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): void {
    $this->store->deleteAll();
  }

  /**
   * Builds a content-addressed key, falling back to the file entity ID.
   */
  private function keyFor(FileInterface $file): string {
    $hash = @hash_file('sha256', $file->getFileUri());
    return $hash === FALSE ? 'file:' . $file->id() : 'sha256:' . $hash;
  }

}
