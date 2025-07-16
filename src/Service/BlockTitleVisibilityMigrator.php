<?php

namespace Drupal\solo_utilities\Service;

use Drupal\block\BlockInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Theme\ThemeManagerInterface;

/**
 * Migrate old label display settings.
 */
final class BlockTitleVisibilityMigrator {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected ConfigFactoryInterface $configFactory;
  protected ThemeManagerInterface $themeManager;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    ThemeManagerInterface $theme_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->themeManager = $theme_manager;
  }

  /**
   * Determines if block title visibility is enabled in the theme settings.
   */
  protected function isFeatureEnabled(): bool {
    $theme = $this->configFactory->get('system.theme')->get('default');
    if (!solo_utilities__is_solo_in_theme_hierarchy($theme)) {
      return FALSE;
    }
    return (bool) theme_get_setting('enable_block_title_visibility', $theme);
  }

  /**
   * Migrates legacy block title visibility settings.
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
      if (!solo_utilities__is_solo_in_theme_hierarchy($theme)) {
        continue;
      }

      $settings = $block->get('settings');
      $has_new = isset($settings['solo_block_title_visibility']);
      $legacy = $settings['label_display'] ?? NULL;

      if (!$has_new) {
        $settings['solo_block_title_visibility'] = match (TRUE) {
          $legacy === 'visible',
          $legacy === TRUE,
          $legacy === '1' => 'visible',
          $legacy === '0',
          $legacy === FALSE => 'visually_hidden',
          $legacy === NULL => 'none',
          default => 'visible',
        };
        $block->set('settings', $settings);
        $block->save();
      }
    }
  }

  /**
   * Cleans up custom block settings on uninstall.
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
      if (!solo_utilities__is_solo_in_theme_hierarchy($theme)) {
        continue;
      }

      $settings = $block->get('settings');
      if (isset($settings['solo_block_title_visibility'])) {
        unset($settings['solo_block_title_visibility']);
        $block->set('settings', $settings);
        $block->save();
      }
    }
  }

}
