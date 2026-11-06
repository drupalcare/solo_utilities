<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * One section of the theme settings form.
 *
 * Sections are services tagged solo_utilities.settings_section; the tag's
 * priority sets their order. A section reads settings only through the context,
 * so it cannot read the wrong theme, and gives every element #parents with its
 * full stored path.
 *
 * @internal
 */
interface SettingsSectionInterface {

  /**
   * Adds this section to the form.
   *
   * @param array<string, mixed> $form
   *   The system_theme_settings form.
   * @param \Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext $context
   *   The edited theme, its settings and its occupied regions.
   */
  public function build(array &$form, SettingsFormContext $context): void;

}
