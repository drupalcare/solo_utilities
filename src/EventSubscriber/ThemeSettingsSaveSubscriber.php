<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\solo_utilities\Css\ThemeAssetFiles;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Regenerates a Solo theme's files whenever its settings config is saved.
 *
 * A config event and not a form submit, so `drush cim` and `drush cset` also
 * regenerate. Config::save() invalidates the theme settings cache before this
 * event for an existing object. A first save does not, so a read earlier in the
 * same request can build from old values; the next page compares the bytes
 * through AssetAttacher and corrects the file.
 */
final class ThemeSettingsSaveSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ThemeAssetFiles $files,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onSave',
      ConfigEvents::DELETE => 'onDelete',
    ];
  }

  /**
   * Rebuilds the files of the saved theme; rebuild() ignores any other theme.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The config event.
   */
  public function onSave(ConfigCrudEvent $event): void {
    $theme = $this->themeName($event);
    if ($theme !== NULL) {
      $this->files->rebuild($theme);
    }
  }

  /**
   * Removes the files of a theme whose settings are gone.
   *
   * No hierarchy check: on uninstall the theme may already be unknown. Only
   * file names ThemeAssetFiles generates are removed.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The config event.
   */
  public function onDelete(ConfigCrudEvent $event): void {
    $theme = $this->themeName($event);
    if ($theme !== NULL) {
      $this->files->deleteAll($theme);
    }
  }

  /**
   * The theme a config object belongs to, when it is named <theme>.settings.
   *
   * @param \Drupal\Core\Config\ConfigCrudEvent $event
   *   The config event.
   */
  private function themeName(ConfigCrudEvent $event): ?string {
    return preg_match('/^([a-z0-9_]+)\.settings$/D', $event->getConfig()->getName(), $matches) === 1 ? $matches[1] : NULL;
  }

}
