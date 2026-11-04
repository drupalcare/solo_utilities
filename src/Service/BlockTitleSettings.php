<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Service;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\block\BlockInterface;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * Reads and stores the block title settings of the Solo block title feature.
 *
 * The one home of the "is the feature on" question and of where a block keeps
 * its title settings. The procedural functions in solo_utilities.module
 * forward here and keep their names for existing callers.
 *
 * @internal
 */
final class BlockTitleSettings implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly SettingsReader $themeSettings,
    private readonly SoloThemeDetection $detection,
  ) {}

  /**
   * Whether the feature is on in the default front-end theme.
   *
   * TRUE if the default theme is Solo or a Solo sub-theme and its
   * 'enable_block_title_visibility' setting is on. The default theme is read
   * on purpose: block forms render under the admin theme.
   */
  public function isEnabled(): bool {
    $theme_name = $this->detection->defaultThemeName();
    if (!$this->detection->isInHierarchy($theme_name)) {
      return FALSE;
    }
    return $this->themeSettings->forTheme($theme_name)->bool('enable_block_title_visibility');
  }

  /**
   * Reads a block title setting: third-party first, then the legacy location.
   *
   * The legacy fallback covers blocks saved before solo_utilities_update_8002()
   * moved the values out of the block plugin settings.
   */
  public function get(BlockInterface $block, string $key): mixed {
    return $block->getThirdPartySetting('solo_utilities', $key)
      ?? ($block->get('settings')[$key] ?? NULL);
  }

  /**
   * Saves the block title settings as the block's third-party settings.
   *
   * They used to be written into the block PLUGIN settings, which no block
   * plugin schema declares, so every such block failed config validation. Any
   * legacy copy is removed here so a block holds the values in one place only.
   *
   * @param string $entity_type
   *   The entity type id.
   * @param \Drupal\block\BlockInterface $block
   *   The block being built.
   * @param array<string, mixed> $form
   *   The block form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function buildEntity(string $entity_type, BlockInterface $block, array &$form, FormStateInterface $form_state): void {
    foreach (BlockTitleVisibilityMigrator::BLOCK_TITLE_KEYS as $key) {
      $value = $form_state->getValue(['settings', $key]);
      if (isset($value)) {
        $block->setThirdPartySetting('solo_utilities', $key, $value);
      }
    }

    $settings = $block->get('settings');
    $legacy = array_intersect_key($settings, array_flip(BlockTitleVisibilityMigrator::BLOCK_TITLE_KEYS));
    if ($legacy) {
      $block->set('settings', array_diff_key($settings, $legacy));
    }
  }

}
