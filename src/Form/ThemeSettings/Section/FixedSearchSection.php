<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Fixed Search Block section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class FixedSearchSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Fixed search region.
    if ($context->hasMixRegion("fixed_search_block")) {

      $region = "fixed_search_block";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure the fixed search block styling and behavior options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["fixed_search_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-search-configuration",
      ];

      // Search Configuration Tab.
      $form["solo_settings"]["settings_{$region}"]["search_configuration"] = [
        "#type" => "details",
        "#title" => $this->t("Search Configuration"),
        "#group" => "fixed_search_tabs",
        "#weight" => -10,
        "#id" => "edit-search-configuration",
      ];

      $form["solo_settings"]["settings_{$region}"]["search_configuration"]["classes"] = $this->elements->cssClasses($settings, $region);

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings_tab"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "fixed_search_tabs",
        "#weight" => -8,
        '#attributes' => [
          'class' => ['solo-color-panel'],
          'data-solo-panel' => 'colors',
        ],
      ];

      $this->elements->addRegionColors($form["solo_settings"]["settings_{$region}"]["color_settings_tab"], $settings, $region);

    }
  }

}
