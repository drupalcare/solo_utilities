<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\EventSubscriber;

use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\StorageTransformEvent;
use Drupal\solo_utilities\Upgrade\SettingsMigrator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies the settings map to config being imported.
 *
 * A config/sync exported from Solo 1 would put the old keys back after the
 * update. The first config export afterwards shows a whole-file diff, once.
 *
 * @internal
 */
final class SettingsImportSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly SettingsMigrator $migrator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Late, so config merged in by other import subscribers is covered too.
    // How this orders against config_split and config_ignore is not proven.
    return [ConfigEvents::STORAGE_TRANSFORM_IMPORT => ['onImportTransform', -100]];
  }

  /**
   * Rewrites old keys in the storage being imported.
   */
  public function onImportTransform(StorageTransformEvent $event): void {
    $this->migrator->transformStorage($event->getStorage());
  }

}
