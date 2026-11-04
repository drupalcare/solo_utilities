<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Footer Menu region section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class FooterMenuSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Footer menu region.
    if ($context->hasMixRegion("footer_menu")) {

      $region = "footer_menu";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure the footer menu styling and accessibility options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["footer_menu_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-menu-configuration",
      ];

      // Menu Configuration Tab.
      $form["solo_settings"]["settings_{$region}"]["menu_configuration"] = [
        "#type" => "details",
        "#title" => $this->t("Menu Configuration"),
        "#group" => "footer_menu_tabs",
        "#weight" => -10,
        "#id" => "edit-menu-configuration",
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_configuration"]["classes"] = $this->elements->cssClasses($settings, $region);

      // Accessibility Tab.
      $form["solo_settings"]["settings_{$region}"]["accessibility"] = [
        "#type" => "details",
        "#title" => $this->t("Accessibility"),
        "#group" => "footer_menu_tabs",
        "#weight" => -8,
      ];

      $form["solo_settings"]["settings_{$region}"]["accessibility"]["skip_links__footer"] = [
        "#parents" => ['skip_links', 'footer'],
        "#type" => "checkbox",
        "#title" => $this->t("Enable skip to footer link"),
        "#default_value" => $settings->get('skip_links.footer'),
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings_tab"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "footer_menu_tabs",
        "#weight" => -6,
        '#attributes' => [
          'class' => ['solo-color-panel'],
          'data-solo-panel' => 'colors',
        ],
      ];

      $this->elements->addRegionColors($form["solo_settings"]["settings_{$region}"]["color_settings_tab"], $settings, $region);

    }
  }

}
