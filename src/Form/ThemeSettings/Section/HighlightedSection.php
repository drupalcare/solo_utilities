<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Highlighted region section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class HighlightedSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Highlighted region.
    if ($context->hasMixRegion("highlighted")) {

      $region = "highlighted";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure the highlighted region display, accessibility, and styling options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["highlighted_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-highlighted-region-configuration",
      ];

      // Region Configuration Tab.
      $form["solo_settings"]["settings_{$region}"]["region_configuration"] = [
        "#type" => "details",
        "#title" => $this->t("Region configuration"),
        "#group" => "highlighted_tabs",
        "#weight" => -10,
        "#id" => "edit-highlighted-region-configuration",
      ];

      $form["solo_settings"]["settings_{$region}"]["region_configuration"]["regions__highlighted__visibility"] = $this->elements->regionVisibility($settings, 'regions.highlighted.visibility', "highlighted");
      $form["solo_settings"]["settings_{$region}"]["region_configuration"]["regions__highlighted__aria_landmark"] = [
        "#parents" => ['regions', 'highlighted', 'aria_landmark'],
        "#type" => "checkbox",
        "#title" => $this->t("Enable ARIA landmark for highlighted region"),
        "#default_value" => $settings->get('regions.highlighted.aria_landmark'),
        "#description" => $this->t("Adds <code>role='region'</code> and <code>aria-label</code> to the highlighted region for improved accessibility. Enable only if this region consistently contains important, navigable content."),
      ];
      $form["solo_settings"]["settings_{$region}"]["region_configuration"]["classes"] = $this->elements->cssClasses($settings, $region);

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings"] = [
        "#type" => "details",
        "#title" => $this->t("Color settings"),
        "#group" => "highlighted_tabs",
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
