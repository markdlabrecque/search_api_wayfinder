<?php

declare(strict_types=1);

namespace Drupal\search_api_wayfinder\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\FileInterface;
use Drupal\search_api\IndexInterface;
use Drupal\search_api\SearchApiException;
use Drupal\search_api\Utility\Utility;
use Drupal\search_api_wayfinder\Cache\ExtractionCacheInterface;
use Drupal\search_api_wayfinder\Plugin\search_api\backend\WayfinderBackend;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Extracts queued files and marks their referencing items for reindexing.
 */
#[QueueWorker(
  id: 'wayfinder_extraction',
  title: new TranslatableMarkup('Wayfinder file extraction'),
  cron: ['time' => 60],
)]
final class ExtractorQueue extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ExtractionCacheInterface $cache,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('search_api_wayfinder.extraction_cache'),
      $container->get('logger.channel.search_api_wayfinder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $file = $this->entityTypeManager->getStorage('file')->load($data['file_id']);
    if (!$file instanceof FileInterface) {
      return;
    }

    $index = $this->entityTypeManager->getStorage('search_api_index')->load($data['index_id']);
    if (!$index instanceof IndexInterface) {
      return;
    }

    $backend = $index->getServerInstanceIfAvailable()?->getBackend();
    if (!$backend instanceof WayfinderBackend) {
      return;
    }

    if ($this->cache->get($file) === NULL) {
      try {
        $text = $backend->extractContentFromFile($file->getFileUri());
      }
      catch (SearchApiException $exception) {
        $this->logger->error(
          'Failed to extract file @file_id for Search API index @index_id: @message',
          [
            '@file_id' => $data['file_id'],
            '@index_id' => $data['index_id'],
            '@message' => $exception->getMessage(),
          ],
        );
        throw $exception;
      }
      $this->cache->set($file, $text);
    }

    [$datasource_id, $raw_id] = Utility::splitCombinedId($data['item_id']);
    $index->trackItemsUpdated($datasource_id, [$raw_id]);
  }

}
