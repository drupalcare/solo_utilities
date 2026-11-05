<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Welcome Text region section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class WelcomeTextSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Welcome region.
    if ($context->hasMixRegion("welcome_text")) {

      $region = "welcome_text";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure the welcome region display, accessibility, layout, and styling options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["welcome_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-welcome-text-region-configuration",
      ];

      // Region Configuration Tab.
      $form["solo_settings"]["settings_{$region}"]["region_configuration"] = [
        "#type" => "details",
        "#title" => $this->t("Region Configuration"),
        "#group" => "welcome_tabs",
        "#weight" => -10,
        "#id" => "edit-welcome-text-region-configuration",
      ];

      $form["solo_settings"]["settings_{$region}"]["region_configuration"]["classes"] = $this->elements->cssClasses($settings, $region);
      $form["solo_settings"]["settings_{$region}"]["region_configuration"]["regions__welcome_text__visibility"] = $this->elements->regionVisibility($settings, 'regions.welcome_text.visibility', "welcome");
      $form["solo_settings"]["settings_{$region}"]["region_configuration"]["regions__welcome_text__aria_landmark"] = [
        "#parents" => ['regions', 'welcome_text', 'aria_landmark'],
        "#type" => "checkbox",
        "#title" => $this->t("Enable ARIA Landmark for Welcome Region"),
        "#default_value" => $settings->get('regions.welcome_text.aria_landmark'),
        "#description" => $this->t("Adds <code>role='region'</code> and <code>aria-label</code> to the welcome region for improved accessibility. Enable only if this region consistently contains important, navigable content."),
      ];

      $form["solo_settings"]["settings_{$region}"]["region_configuration"]["regions__welcome_text__width"] = [
        "#parents" => ['regions', 'welcome_text', 'width'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to enable full width for this region (recommended for hero sections)."),
        "#default_value" => $settings->get('regions.welcome_text.width'),
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "welcome_tabs",
        "#weight" => -8,
        '#attributes' => [
          'class' => ['solo-color-panel'],
          'data-solo-panel' => 'colors',
        ],
      ];

      $this->elements->addRegionColors($form["solo_settings"]["settings_{$region}"]["color_settings"], $settings, $region);

    }
  }

}
