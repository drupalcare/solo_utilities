<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Config\ConfigInstallerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Update\UpdateRegistry;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;
use Drupal\solo_utilities\Upgrade\SettingsMap;
use Drupal\solo_utilities\Upgrade\SettingsMigrator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps Solo theme settings on the new names as themes and modules come in.
 *
 * @internal
 */
final class SettingsUpgradeHooks {

  /**
   * The parent type every Solo-based theme's settings use.
   */
  private const SCHEMA_TYPE = 'solo_theme_settings';

  public function __construct(
    private readonly SoloThemeDetectionInterface $detection,
    private readonly SettingsMap $map,
    private readonly SettingsMigrator $migrator,
    private readonly ConfigInstallerInterface $configInstaller,
    #[Autowire(service: 'update.post_update_registry')]
    private readonly UpdateRegistry $postUpdateRegistry,
  ) {}

  /**
   * Implements hook_themes_installed().
   *
   * A sub-theme's config/install still holds old names, and core installs it
   * as shipped. During a sync the data was already transformed on import. A
   * failure is logged, not thrown, as the theme is already installed.
   */
  #[Hook('themes_installed')]
  public function themesInstalled(array $theme_list): void {
    // First, before the sync return: answers remembered before this install
    // are stale whether or not the migration runs.
    $this->detection->resetCache();
    if ($this->configInstaller->isSyncing()) {
      return;
    }
    foreach (array_intersect($theme_list, $this->detection->chainOnDisk()) as $theme) {
      $this->migrator->tryMigrate($theme, TRUE);
    }
  }

  /**
   * Implements hook_themes_uninstalled().
   *
   * Forgets remembered theme answers; a removed theme must not still be Solo.
   */
  #[Hook('themes_uninstalled')]
  public function themesUninstalled(array $themes): void {
    $this->detection->resetCache();
  }

  /**
   * Implements hook_modules_installed().
   *
   * Core records every post-update of a newly installed module as already run,
   * and Solo's own post-update installs this module on sites without it. So the
   * migration runs here for them. On failure it unregisters this module's post
   * update, so the next updb runs it, and throws to keep the current one red.
   *
   * @throws \RuntimeException
   *   Naming the themes that failed.
   */
  #[Hook('modules_installed')]
  public function modulesInstalled(array $modules, bool $is_syncing): void {
    if ($is_syncing || !in_array('solo_utilities', $modules, TRUE)) {
      return;
    }
    $failed = $this->migrator->migrateAll();
    if ($failed !== []) {
      $this->postUpdateRegistry->filterOutInvokedUpdatesByExtension('solo_utilities');
      throw new \RuntimeException('Solo settings were not migrated for: ' . implode(', ', $failed) . '. Those themes are unchanged; see the log, then run updates again.');
    }
  }

  /**
   * Implements hook_config_schema_info_alter().
   *
   * A sub-theme's schema file may hold old names. Each chain theme gets Solo's
   * new type, plus whatever its file declares that is not in the map. Only an
   * existing definition is changed: core throws if an alter adds one, so a
   * sub-theme with no schema stays without.
   */
  #[Hook('config_schema_info_alter')]
  public function configSchemaInfoAlter(array &$definitions): void {
    if (!isset($definitions[self::SCHEMA_TYPE]) || !$this->map->exists()) {
      return;
    }
    foreach ($this->detection->chainOnDisk() as $theme) {
      $name = $theme . '.settings';
      if ($theme === 'solo' || !isset($definitions[$name]) || ($definitions[$name]['type'] ?? '') === self::SCHEMA_TYPE) {
        continue;
      }
      $own_keys = array_filter(
        $definitions[$name]['mapping'] ?? [],
        fn(string|int $key): bool => $this->map->lookup((string) $key) === NULL,
        ARRAY_FILTER_USE_KEY,
      );
      unset($definitions[$name]['mapping']);
      $definitions[$name]['type'] = self::SCHEMA_TYPE;
      if ($own_keys !== []) {
        $definitions[$name]['mapping'] = $own_keys;
      }
    }
  }

}
