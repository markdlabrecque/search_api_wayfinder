<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder\Cache;

use Drupal\file\FileInterface;

/**
 * Defines storage for extracted file text.
 */
interface ExtractionCacheInterface {

  /**
   * Gets cached extracted text, or NULL when the file is not cached.
   */
  public function get(FileInterface $file): ?string;

  /**
   * Caches extracted text for a file.
   */
  public function set(FileInterface $file, string $text): void;

  /**
   * Deletes cached extracted text for a file.
   */
  public function delete(FileInterface $file): void;

  /**
   * Deletes all cached extracted text.
   */
  public function clear(): void;

}
