<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Breadcrumb region section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class BreadcrumbSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Breadcrumb region.
    if ($context->hasMixRegion("breadcrumb")) {

      $region = "breadcrumb";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure breadcrumb navigation display, behavior, and styling options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["breadcrumb_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-breadcrumb-configuration",
      ];

      // Breadcrumb Configuration Tab.
      $form["solo_settings"]["settings_{$region}"]["breadcrumb_configuration"] = [
        "#type" => "details",
        "#title" => $this->t("Breadcrumb Configuration"),
        "#group" => "breadcrumb_tabs",
        "#weight" => -10,
        "#id" => "edit-breadcrumb-configuration",
      ];

      $form["solo_settings"]["settings_{$region}"]["breadcrumb_configuration"]["classes"] = $this->elements->cssClasses($settings, $region);

      $form["solo_settings"]["settings_{$region}"]["breadcrumb_configuration"]["breadcrumb__hide_page_title"] = [
        "#parents" => ['breadcrumb', 'hide_page_title'],
        "#type" => "checkbox",
        "#title" => $this->t("Exclude the page title from the Breadcrumb."),
        "#description" => $this->t("By default, Drupal leaves the page title out of the breadcrumb and Solo adds it. Enable this to stop adding it. Applies to all content types, Views pages, taxonomy terms and user profile pages."),
        "#default_value" => $settings->get('breadcrumb.hide_page_title'),
      ];

      $form["solo_settings"]["settings_{$region}"]["breadcrumb_configuration"]["breadcrumb__scroll"] = [
        "#parents" => ['breadcrumb', 'scroll'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to enable Breadcrumb scrolling (Prevent Wrap)"),
        "#default_value" => $settings->get('breadcrumb.scroll'),
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "breadcrumb_tabs",
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
