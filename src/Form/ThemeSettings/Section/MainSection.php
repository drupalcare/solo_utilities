<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Main Containers section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class MainSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
    private readonly SettingsOptions $options,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    if ($context->groupRegions["main"]) {

      $region_label = implode(", ", $context->groupRegions["main"]);

      $form["solo_settings"]["settings_main_container"] = [
        "#type" => "details",
        "#title" => $this->t("Main containers"),
        "#description"   => $this->t("<p>The settings for @region_label.</p>", ["@region_label" => $region_label]),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_main_container"]["main_container_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-main-layout-settings",
      ];

      foreach ($context->groupRegions["main"] as $region => $label) {

        if ($region == "container") {
          $region = "main_container";
          $layout_region = "main";

          // Layout Settings Tab.
          $form["solo_settings"]["settings_main_container"]["layout_settings"] = [
            "#type" => "details",
            "#title" => $this->t("Layout settings"),
            "#group" => "main_container_tabs",
            "#weight" => -10,
            "#id" => "edit-main-layout-settings",
          ];

          $form["solo_settings"]["settings_main_container"]["layout_settings"]["region_groups__main__columns_2"] = $this->elements->regionColumns($settings, $layout_region, 2);
          $form["solo_settings"]["settings_main_container"]["layout_settings"]["region_groups__main__columns_3"] = $this->elements->regionColumns($settings, $layout_region, 3);

          $form["solo_settings"]["settings_main_container"]["layout_settings"]["region_groups__main__per_content_type_enabled"] = [
            "#parents" => ['region_groups', 'main', 'per_content_type_enabled'],
            "#type" => "checkbox",
            "#title" => $this->t("Enable per-content-type layouts for main region"),
            "#default_value" => $settings->get('region_groups.main.per_content_type_enabled'),
          ];

          foreach ($this->options->contentTypes() as $type_id => $type_label) {
            $form["solo_settings"]["settings_main_container"]["layout_settings"]["per_content_type"][$type_id] = [
              "#type" => "details",
              "#title" => $this->t("Layout for @label", ["@label" => $type_label]),
              "#open" => FALSE,
              "#states" => [
                "visible" => [
                  ":input[name='region_groups[main][per_content_type_enabled]']" => ["checked" => TRUE],
                ],
              ],
            ];

            // 2-column override.
            $layout_2col = $this->elements->regionColumns($settings, $layout_region, 2, $type_id);
            $layout_2col["#title"] = $this->t("Two-column layout (override)");
            $form["solo_settings"]["settings_main_container"]["layout_settings"]["per_content_type"][$type_id]["columns_2_$type_id"] = $layout_2col;

            // 3-column override.
            $layout_3col = $this->elements->regionColumns($settings, $layout_region, 3, $type_id);
            $layout_3col["#title"] = $this->t("Three-column layout (override)");
            $form["solo_settings"]["settings_main_container"]["layout_settings"]["per_content_type"][$type_id]["columns_3_$type_id"] = $layout_3col;
          }

          $form["solo_settings"]["settings_main_container"]["layout_settings"]["layout__mobile_order"] = [
            "#parents" => ['layout', 'mobile_order'],
            "#type" => "select",
            "#title" => $this->t("Select the layout order for mobile view"),
            "#description" => $this->t("Choose the order in which the main content and sidebars are displayed on smaller screens."),
            "#options" => $this->options->mobileLayout(),
            "#default_value" => $settings->get('layout.mobile_order'),
          ];

          // Styling & Design Tab.
          $form["solo_settings"]["settings_main_container"]["styling_design"] = [
            "#type" => "details",
            "#title" => $this->t("Styling & design"),
            "#group" => "main_container_tabs",
            "#weight" => -8,
          ];

          $form["solo_settings"]["settings_main_container"]["styling_design"]["region_groups__main__rounded"] = $this->elements->regionRounded($settings, $layout_region);
          $form["solo_settings"]["settings_main_container"]["styling_design"]["region_groups__main__border"] = $this->elements->regionBorder($settings, $layout_region);
          $form["solo_settings"]["settings_main_container"]["styling_design"]["classes"] = $this->elements->cssClasses($settings, $region);

          // Accessibility Tab.
          $form["solo_settings"]["settings_main_container"]["accessibility"] = [
            "#type" => "details",
            "#title" => $this->t("Accessibility"),
            "#group" => "main_container_tabs",
            "#weight" => -6,
          ];

          $form["solo_settings"]["settings_main_container"]["accessibility"]["skip_links__main"] = [
            "#parents" => ['skip_links', 'main'],
            "#type" => "checkbox",
            "#title" => $this->t("Enable skip to main content link"),
            "#default_value" => $settings->get('skip_links.main'),
          ];

          // Color Settings Tab.
          $form["solo_settings"]["settings_main_container"]["color_settings_tab"] = [
            "#type" => "details",
            "#title" => $this->t("Color settings"),
            "#group" => "main_container_tabs",
            "#weight" => -4,
            '#attributes' => [
              'class' => ['solo-color-panel'],
              'data-solo-panel' => 'colors',
            ],
          ];

          $this->elements->addRegionColors($form["solo_settings"]["settings_main_container"]["color_settings_tab"], $settings, $region);
        }
        else {
          // Individual Region Settings Tab.
          $form["solo_settings"]["settings_main_container"]["region_{$region}"] = [
            "#type" => "details",
            "#title" => $this->t("@label Settings", ["@label" => $label]),
            "#group" => "main_container_tabs",
            "#weight" => 0,
          ];

          $form["solo_settings"]["settings_main_container"]["region_{$region}"]["classes"] = $this->elements->cssClasses($settings, $region);

          $form["solo_settings"]["settings_main_container"]["region_{$region}"]["alignment"] = [
            "#parents" => ['regions', $region, 'alignment'],
            "#title" => $this->t("Region block alignment option"),
            "#type" => "select",
            "#options" => $this->options->alignment(),
            "#default_value" => $settings->get("regions.{$region}.alignment"),
            "#description" => $this->t("Block content is left-aligned by default. This aligns blocks to the center or right within this region, through region-level configuration. Large screens only."),
          ];

          // Color Settings for Individual Regions.
          $form["solo_settings"]["settings_main_container"]["region_{$region}"]["color_settings"] = [
            "#type" => "details",
            "#title" => $this->t("Color settings"),
            "#open" => FALSE,
            "#description" => $this->t("Configure colors for background, text, links, buttons, and other visual elements in this region."),
            '#attributes' => [
              'class' => ['solo-color-panel'],
              'data-solo-panel' => 'colors',
            ],
          ];

          $this->elements->addRegionColors($form["solo_settings"]["settings_main_container"]["region_{$region}"]["color_settings"], $settings, $region);
        }
      }

    }
  }

}
