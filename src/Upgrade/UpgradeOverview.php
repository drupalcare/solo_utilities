<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Upgrade;

use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\solo_utilities\Layout\RegionInventory;
use Drupal\solo_utilities\Service\SoloReadinessScanner;
use Drupal\solo_utilities\Theme\SoloThemeDetection;
use Psr\Log\LoggerInterface;

/**
 * Gathers what the Solo upgrade page shows, and runs the migration for it.
 *
 * It holds no migration or scanning logic: the migrator, the scanner and the
 * region inventory each answer for themselves. Nothing here renders.
 *
 * @internal
 */
final class UpgradeOverview {

  public const STATE_MIGRATED = 'migrated';

  public const STATE_WAITING = 'waiting';

  public const STATE_UNMAPPED = 'unmapped';

  public const STATE_NO_SETTINGS = 'no_settings';

  public const STATE_ERROR = 'error';

  public function __construct(
    private readonly SettingsMigrator $migrator,
    private readonly SoloReadinessScanner $scanner,
    private readonly RegionInventory $regions,
    private readonly SoloThemeDetection $detection,
    private readonly ExtensionList $themeList,
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Every Solo theme on disk, the parent first and then by depth and name.
   *
   * @return list<array{name: string, label: string, base: string, default: bool, installed: bool, regions: list<array{name: string, label: string, count: int}>, state: string, unknown: list<string>, unmovable: list<string>, backup: bool}>
   *   One entry per theme.
   */
  public function themes(): array {
    $list = $this->themeList->getList();
    $names = $this->detection->chainOnDisk();
    $depth = static fn(string $name): int => count($list[$name]->base_themes ?? []);
    usort($names, static fn(string $a, string $b): int => $depth($a) <=> $depth($b) ?: $a <=> $b);

    $default = $this->detection->defaultThemeName();
    $themes = [];
    foreach ($names as $name) {
      $base = $list[$name]->info['base theme'] ?? '';
      $themes[] = [
        'name' => $name,
        'label' => (string) ($list[$name]->info['name'] ?? $name),
        'base' => is_string($base) ? $base : '',
        'default' => $name === $default,
        'installed' => $this->themeHandler->themeExists($name),
        'regions' => $this->regionRows($name),
      ] + $this->status($name);
    }
    return $themes;
  }

  /**
   * The themes a migration would change now.
   *
   * @return list<string>
   *   Theme machine names.
   */
  public function waitingThemes(): array {
    return array_values(array_filter(
      $this->detection->chainOnDisk(),
      fn(string $name): bool => $this->status($name)['state'] === self::STATE_WAITING,
    ));
  }

  /**
   * What the scanner found in sub-theme and custom module code.
   *
   * @return array<string, mixed>|null
   *   SoloReadinessScanner::scan(), or NULL when the scan could not run. NULL
   *   must never be shown as "nothing found".
   */
  public function findings(): ?array {
    try {
      return $this->scanner->scan();
    }
    catch (\Exception $e) {
      $this->logger->error('The Solo readiness scan failed (@class).', ['@class' => $e::class]);
      return NULL;
    }
  }

  /**
   * Config objects holding old keys with no provable Solo theme on disk.
   *
   * @return list<string>
   *   Config object names; empty also when the map cannot be read, in which
   *   case every theme already reports the error state.
   */
  public function unprovable(): array {
    try {
      return $this->migrator->findUnprovable();
    }
    catch (\Exception) {
      return [];
    }
  }

  /**
   * Whether a block placement exists to be read or linked to.
   */
  public function hasBlocks(): bool {
    return $this->moduleHandler->moduleExists('block');
  }

  /**
   * Cache tags of the configuration behind the page.
   *
   * @return list<string>
   *   Tags of each theme's settings, the default theme and the block list.
   */
  public function cacheTags(): array {
    $tags = ['config:system.theme'];
    foreach ($this->detection->chainOnDisk() as $name) {
      $tags[] = 'config:' . $name . '.settings';
    }
    return $this->hasBlocks() ? array_merge($tags, $this->regions->cacheTags()) : $tags;
  }

  /**
   * Runs the migrator for the parent and every sub-theme.
   *
   * A second run changes nothing, so a theme that is not waiting is harmless.
   *
   * @return array{changed: list<string>, differences: array<string, list<string>>, failed: list<string>}
   *   Theme machine names, and per theme the keys whose new value was kept.
   */
  public function run(): array {
    $result = ['changed' => [], 'differences' => [], 'failed' => []];
    foreach ($this->migrator->themes() as $theme) {
      $report = [];
      if (!$this->migrator->tryMigrate($theme, FALSE, $report)) {
        $result['failed'][] = $theme;
        continue;
      }
      if ($report['changed']) {
        $result['changed'][] = $theme;
      }
      if ($report['differences'] !== []) {
        $result['differences'][$theme] = $report['differences'];
      }
    }
    return $result;
  }

  /**
   * The upgrade state of one theme.
   *
   * @return array{state: string, unknown: list<string>, unmovable: list<string>, backup: bool}
   *   The state, key names only, and whether a backup is held.
   */
  private function status(string $theme): array {
    try {
      $status = $this->migrator->status($theme);
      $backup = $this->migrator->hasBackup($theme);
    }
    catch (\Exception $e) {
      $this->logger->error('Reading the upgrade state of theme @theme failed (@class).', [
        '@theme' => $theme,
        '@class' => $e::class,
      ]);
      return ['state' => self::STATE_ERROR, 'unknown' => [], 'unmovable' => [], 'backup' => FALSE];
    }
    $state = match (TRUE) {
      !$status['stored'] => self::STATE_NO_SETTINGS,
      $status['waiting'] => self::STATE_WAITING,
      $status['unknown'] !== [] || $status['unmovable'] !== [] => self::STATE_UNMAPPED,
      default => self::STATE_MIGRATED,
    };
    return [
      'state' => $state,
      'unknown' => $status['unknown'],
      'unmovable' => $status['unmovable'],
      'backup' => $backup,
    ];
  }

  /**
   * The visible regions of one theme with their enabled block counts.
   *
   * @return list<array{name: string, label: string, count: int}>
   *   In the theme's order; empty for a theme that is not installed.
   */
  private function regionRows(string $theme): array {
    $counts = $this->hasBlocks() ? $this->regions->enabledBlockCounts($theme) : [];
    $rows = [];
    foreach ($this->regions->visibleRegions($theme) as $region => $label) {
      $rows[] = [
        'name' => (string) $region,
        'label' => (string) $label,
        'count' => $counts[$region] ?? 0,
      ];
    }
    return $rows;
  }

}
