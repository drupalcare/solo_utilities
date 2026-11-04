<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormBuilder;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * Routes the theme settings form to its builder.
 */
final class SettingsHooks {

  public function __construct(
    private readonly SoloThemeDetection $themeDetection,
    private readonly SettingsFormBuilder $builder,
  ) {}

  /**
   * Implements hook_form_system_theme_settings_alter().
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_system_theme_settings_alter')]
  public function formSystemThemeSettingsAlter(array &$form, FormStateInterface $form_state): void {
    // Module alters run for every theme's page and on the global page, whose
    // edited theme is empty. Those are not Solo's.
    if (!$this->themeDetection->isEdited($form_state)) {
      return;
    }
    $this->builder->build($form, $form_state);
  }

}
