<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Service;

use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\block\BlockInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Migrates and manages block title visibility settings for Solo theme.
 *
 * This service handles the migration of legacy block title visibility settings
 * to the new solo_block_title_visibility format. It provides functionality for
 * detecting Solo theme hierarchy, migrating old settings, and cleaning up
 * custom settings during module uninstallation.
 *
 * The migration supports converting from various legacy formats (visible, TRUE,
 * '1', etc.) to standardized visibility values (visible, visually_hidden, none).
 *
 * @internal
 */
final class BlockTitleVisibilityMigrator {

  /**
   * The block title settings this module stores on each block.
   *
   * They live in the block's third_party_settings.solo_utilities.
   */
  public const BLOCK_TITLE_KEYS = [
    'solo_block_title_visibility',
    'solo_block_title_tag',
  ];

  /**
   * Constructs a BlockTitleVisibilityMigrator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service for loading block entities.
   * @param \Drupal\solo_utilities\Service\SoloThemeDetection $detection
   *   The one home of the Solo theme hierarchy and default theme questions.
   * @param \Drupal\solo_utilities\Service\BlockTitleSettings $blockTitleSettings
   *   Reads the block title settings a block stores.
   * @param \Drupal\Core\Extension\ThemeSettingsProvider $themeSettings
   *   Reads theme settings by theme name.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SoloThemeDetection $detection,
    private readonly BlockTitleSettings $blockTitleSettings,
    private readonly ThemeSettingsProvider $themeSettings,
  ) {}

  /**
   * Determines if block title visibility feature is enabled in theme settings.
   *
   * Checks if the current default theme is part of the Solo theme hierarchy
   * and if the enable_block_title_visibility setting is enabled.
   *
   * @return bool
   *   TRUE if the feature is enabled and theme is Solo-based, FALSE otherwise.
   */
  protected function isFeatureEnabled(): bool {
    $theme = $this->detection->defaultThemeName();
    if (!$this->detection->isInHierarchy($theme)) {
      return FALSE;
    }
    return (bool) $this->themeSettings->getSetting('enable_block_title_visibility', $theme);
  }

  /**
   * Migrates legacy block title visibility settings to new format.
   *
   * Scans all block entities and converts legacy label_display settings
   * to the new solo_block_title_visibility format. Only processes blocks
   * for Solo-based themes when the feature is enabled.
   *
   * Legacy value mapping:
   * - 'visible', TRUE, '1' => 'visible'
   * - '0', FALSE => 'visually_hidden'
   * - NULL => 'none'
   * - default => 'visible'
   *
   * Blocks that already have the new setting are skipped to preserve
   * manual configurations.
   */
  public function migrateLegacySettings(): void {
    if (!$this->isFeatureEnabled()) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('block');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();

    foreach ($storage->loadMultiple($ids) as $block) {
      if (!$block instanceof BlockInterface) {
        continue;
      }

      $theme = $block->getTheme();
      if (!$this->detection->isInHierarchy($theme)) {
        continue;
      }

      $settings = $block->get('settings');
      $has_new = $this->blockTitleSettings->get($block, 'solo_block_title_visibility') !== NULL;
      $legacy = $settings['label_display'] ?? NULL;

      if (!$has_new) {
        $block->setThirdPartySetting('solo_utilities', 'solo_block_title_visibility', match (TRUE) {
          $legacy === 'visible',
          $legacy === TRUE,
          $legacy === '1' => 'visible',
          $legacy === '0',
          $legacy === FALSE => 'visually_hidden',
          $legacy === NULL => 'none',
          default => 'visible',
        });
        $block->save();
      }
    }
  }

  /**
   * Moves block title settings out of block plugin settings.
   *
   * Earlier releases stored solo_block_title_visibility and
   * solo_block_title_tag inside each block's PLUGIN settings. No block plugin
   * schema declares them, so every such block failed config validation. They
   * now live in the block's third_party_settings.solo_utilities, which this
   * module's schema declares.
   *
   * Runs for every block, whatever its theme and whether or not the feature is
   * enabled: the problem is the stored shape, not the rendering. A value that
   * already exists in third-party settings wins over a legacy copy.
   *
   * @return int
   *   The number of blocks changed.
   */
  public function moveToThirdPartySettings(): int {
    $storage = $this->entityTypeManager->getStorage('block');
    $changed = 0;

    foreach ($storage->loadMultiple() as $block) {
      if (!$block instanceof BlockInterface) {
        continue;
      }

      $settings = $block->get('settings');
      $legacy = array_intersect_key($settings, array_flip(self::BLOCK_TITLE_KEYS));
      if (!$legacy) {
        continue;
      }

      foreach ($legacy as $key => $value) {
        if ($block->getThirdPartySetting('solo_utilities', $key) === NULL && $value !== NULL) {
          $block->setThirdPartySetting('solo_utilities', $key, (string) $value);
        }
      }
      $block->set('settings', array_diff_key($settings, $legacy));
      $block->save();
      $changed++;
    }

    return $changed;
  }

  /**
   * Removes custom block title visibility settings during module uninstall.
   *
   * Scans all block entities and removes the solo_block_title_visibility
   * setting from block configurations. This ensures clean uninstallation
   * without leaving orphaned settings in the database.
   *
   * Only processes blocks for Solo-based themes when the feature is enabled.
   * Each modified block is saved immediately to persist the changes.
   */
  public function cleanupBlockSettings(): void {
    if (!$this->isFeatureEnabled()) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('block');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();

    foreach ($storage->loadMultiple($ids) as $block) {
      if (!$block instanceof BlockInterface) {
        continue;
      }

      $theme = $block->getTheme();
      if (!$this->detection->isInHierarchy($theme)) {
        continue;
      }

      // Third-party settings are removed by core when this module is
      // uninstalled; only a legacy plugin-settings copy needs clearing here.
      $settings = $block->get('settings');
      $legacy = array_intersect_key($settings, array_flip(self::BLOCK_TITLE_KEYS));
      if ($legacy) {
        $block->set('settings', array_diff_key($settings, $legacy));
        $block->save();
      }
    }
  }

}
