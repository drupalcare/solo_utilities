<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Primary Sidebar Menu section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class PrimarySidebarMenuSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Primary Sidebar menu region.
    if ($context->hasMixRegion("primary_sidebar_menu")) {

      $region = "primary_sidebar_menu";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure the sidebar menu behavior, styling, and layout options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["sidebar_menu_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-menu-layout-styling",
      ];

      // Menu Layout & Styling Tab.
      $form["solo_settings"]["settings_{$region}"]["menu_layout_styling"] = [
        "#type" => "details",
        "#title" => $this->t("Menu Layout & Styling"),
        "#group" => "sidebar_menu_tabs",
        "#weight" => -10,
        "#id" => "edit-menu-layout-styling",
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_layout_styling"]["classes"] = $this->elements->cssClasses($settings, $region);

      $form["solo_settings"]["settings_{$region}"]["menu_layout_styling"]["menus__primary_sidebar__border"] = [
        "#parents" => ['menus', 'primary_sidebar', 'border'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box if you prefer to add border for each menu item"),
        "#default_value" => $settings->get('menus.primary_sidebar.border'),
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_layout_styling"]["menus__primary_sidebar__branding"] = [
        "#parents" => ['menus', 'primary_sidebar', 'branding'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to display a clickable logo next to the close button."),
        "#description" => $this->t("To also display the site name, add the class show-sitename to the field (CSS Classes Only)."),
        "#default_value" => $settings->get('menus.primary_sidebar.branding'),
      ];

      // Menu Behavior & Accessibility Tab.
      $form["solo_settings"]["settings_{$region}"]["menu_behavior_accessibility"] = [
        "#type" => "details",
        "#title" => $this->t("Menu Behavior & Accessibility"),
        "#group" => "sidebar_menu_tabs",
        "#weight" => -8,
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_behavior_accessibility"]["menus__primary_sidebar__open_on_hover"] = [
        "#parents" => ['menus', 'primary_sidebar', 'open_on_hover'],
        "#type" => "checkbox",
        "#title" => $this->t("Open sidebar dropdowns on hover (large screens only)"),
        "#description" => $this->t("Shows the sidebar main menu dropdowns when hovering over them with the mouse, instead of clicking."),
        "#default_value" => $settings->get('menus.primary_sidebar.open_on_hover'),
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings_tab"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "sidebar_menu_tabs",
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
