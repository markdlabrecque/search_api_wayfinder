<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

/**
 * Discovers file entities linked from content.
 */
interface LinkedFileDiscovererInterface {

  /**
   * Discovers files referenced by rendered HTML.
   *
   * @return \Drupal\file\FileInterface[]
   *   Files keyed by file ID.
   */
  public function discoverFromHtml(string $html): array;

  /**
   * Discovers files referenced by a Drupal link URI.
   *
   * @return \Drupal\file\FileInterface[]
   *   Files keyed by file ID.
   */
  public function discoverFromLinkUri(string $uri): array;

}
