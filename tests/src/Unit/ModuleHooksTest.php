<?php

declare(strict_types=1);

namespace Drupal\Tests\search_api_wayfinder\Unit;

use Drupal\file\FileInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;

/**
 * Tests the module's file lifecycle hook forwarders.
 *
 * @group search_api_wayfinder
 */
class ModuleHooksTest extends TestCase {

  protected function setUp(): void {
    parent::setUp();
    require_once dirname(__DIR__, 3) . '/search_api_wayfinder.module';
  }

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  public function testFileUpdateForwardsTheSameFileToTheInvalidator(): void {
    $file = $this->createMock(FileInterface::class);
    $invalidator = new class {
      public ?FileInterface $updated = NULL;
      public function onFileUpdate(FileInterface $file): void {
        $this->updated = $file;
      }
    };
    $this->setInvalidator($invalidator);

    \search_api_wayfinder_file_update($file);

    $this->assertSame($file, $invalidator->updated);
  }

  public function testFileDeleteForwardsTheSameFileToTheInvalidator(): void {
    $file = $this->createMock(FileInterface::class);
    $invalidator = new class {
      public ?FileInterface $deleted = NULL;
      public function onFileDelete(FileInterface $file): void {
        $this->deleted = $file;
      }
    };
    $this->setInvalidator($invalidator);

    \search_api_wayfinder_file_delete($file);

    $this->assertSame($file, $invalidator->deleted);
  }

  private function setInvalidator(object $invalidator): void {
    $container = new Container();
    $container->set('search_api_wayfinder.extraction_invalidator', $invalidator);
    \Drupal::setContainer($container);
  }

}
