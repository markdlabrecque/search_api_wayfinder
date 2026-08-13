<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\file\FileInterface;

/**
 * Discovers file entities linked from rendered content.
 */
final class LinkedFileDiscoverer implements LinkedFileDiscovererInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StreamWrapperManagerInterface $streamWrapperManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function discoverFromHtml(string $html): array {
    if ($html === '') {
      return [];
    }

    $files = [];
    if (preg_match_all('/<[^>]+>/s', $html, $tags)) {
      foreach ($tags[0] as $tag) {
        $attributes = $this->attributes($tag);
        if (($attributes['data-entity-type'] ?? NULL) === 'file' && !empty($attributes['data-entity-uuid'])) {
          $this->addFiles($files, $this->entityTypeManager->getStorage('file')->loadByProperties([
            'uuid' => $attributes['data-entity-uuid'],
          ]));
        }
        elseif (($attributes['data-entity-type'] ?? NULL) === 'media' && !empty($attributes['data-entity-uuid'])) {
          $media = $this->entityTypeManager->getStorage('media')->loadByProperties([
            'uuid' => $attributes['data-entity-uuid'],
          ]);
          foreach ($media as $entity) {
            $this->addReferencedFiles($files, $entity);
          }
        }

        if (isset($attributes['href'])) {
          $files += $this->discoverFromLinkUri($attributes['href']);
        }
      }
    }
    return $files;
  }

  /**
   * {@inheritdoc}
   */
  public function discoverFromLinkUri(string $uri): array {
    if ($uri === '') {
      return [];
    }

    if (preg_match('@^entity:file/(\d+)$@', $uri, $matches)) {
      $file = $this->entityTypeManager->getStorage('file')->load((int) $matches[1]);
      return $file instanceof FileInterface ? [(int) $file->id() => $file] : [];
    }
    if (preg_match('@^entity:media/(\d+)$@', $uri, $matches)) {
      $media = $this->entityTypeManager->getStorage('media')->load((int) $matches[1]);
      $files = [];
      if ($media instanceof EntityInterface) {
        $this->addReferencedFiles($files, $media);
      }
      return $files;
    }
    if (str_starts_with($uri, 'entity:')) {
      return [];
    }

    if (str_starts_with($uri, 'internal:')) {
      $uri = substr($uri, 9);
    }
    elseif (preg_match('@^https?://@i', $uri)) {
      $parts = parse_url($uri);
      if (!is_array($parts) || empty($parts['path'])) {
        return [];
      }
      $uri = $parts['path'];
    }

    return $this->resolvePath($uri);
  }

  /**
   * Resolves a public or private file path.
   */
  private function resolvePath(string $path): array {
    $path = rawurldecode((string) (parse_url($path, PHP_URL_PATH) ?? ''));
    foreach (['public', 'private'] as $scheme) {
      $wrapper = $this->streamWrapperManager->getViaScheme($scheme);
      if (!$wrapper) {
        continue;
      }
      $directory = '/' . trim($wrapper->getDirectoryPath(), '/');
      if ($path === $directory || str_starts_with($path, $directory . '/')) {
        $relative = ltrim(substr($path, strlen($directory)), '/');
        $files = $this->entityTypeManager->getStorage('file')->loadByProperties([
          'uri' => $scheme . '://' . $relative,
        ]);
        $result = [];
        $this->addFiles($result, $files);
        return $result;
      }
    }
    return [];
  }

  /**
   * Extracts quoted HTML attributes from a tag.
   */
  private function attributes(string $tag): array {
    $attributes = [];
    preg_match_all('/([:\w-]+)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $attributes[strtolower($match[1])] = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return $attributes;
  }

  /**
   * Adds referenced file entities from all entity reference fields.
   */
  private function addReferencedFiles(array &$files, EntityInterface $entity): void {
    if (method_exists($entity, 'referencedEntities')) {
      $this->addFiles($files, $entity->referencedEntities());
      return;
    }
    if (!method_exists($entity, 'getFields')) {
      return;
    }
    $fields = $entity->getFields();
    if (!is_iterable($fields)) {
      return;
    }
    foreach ($fields as $field) {
      if (method_exists($field, 'referencedEntities')) {
        $this->addFiles($files, $field->referencedEntities());
      }
    }
  }

  /**
   * Adds file entities to an ID-keyed result.
   */
  private function addFiles(array &$files, iterable $entities): void {
    foreach ($entities as $entity) {
      if ($entity instanceof FileInterface) {
        $files[(int) $entity->id()] = $entity;
      }
    }
  }

}
