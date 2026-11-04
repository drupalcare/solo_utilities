<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Page Title region section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class PageTitleSection implements SettingsSectionInterface {

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
    // Page title region.
    if ($context->hasMixRegion("page_title")) {

      $region = "page_title";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure page title typography, display effects, and styling options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["page_title_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-typography-settings",
      ];

      // Typography Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["typography_settings"] = [
        "#type" => "details",
        "#title" => $this->t("Typography Settings"),
        "#group" => "page_title_tabs",
        "#weight" => -10,
        "#id" => "edit-typography-settings",
      ];

      $form["solo_settings"]["settings_{$region}"]["typography_settings"]["page_title__font_size_small"] = [
        "#parents" => ['page_title', 'font_size_small'],
        "#title" => $this->t("Select the desired font size for the page title on small screen."),
        "#type" => "select",
        "#options" => $this->options->pageTitleFontSize(),
        "#default_value" => $settings->get('page_title.font_size_small'),
        "#description" => $this->t("Small screens, in pixels or rem units. Follows the website's breakpoints, found under Global Site Settings."),
      ];

      $form["solo_settings"]["settings_{$region}"]["typography_settings"]["page_title__font_size_large"] = [
        "#parents" => ['page_title', 'font_size_large'],
        "#title" => $this->t("Select the desired font size for the page title on large screen."),
        "#type" => "select",
        "#options" => $this->options->pageTitleFontSize(),
        "#default_value" => $settings->get('page_title.font_size_large'),
        "#description" => $this->t("Large screens, in pixels or rem units. Follows the website's breakpoints, found under Global Site Settings."),
      ];

      $form["solo_settings"]["settings_{$region}"]["typography_settings"]["page_title__google_font"] = [
        "#parents" => ['page_title', 'google_font'],
        "#type" => "select",
        "#title" => $this->t("Page Title Fonts"),
        "#description"   => $this->t("Imported only: not applied to any HTML tag by default. Each of the 25 fonts has a CSS class you can apply to any HTML tag; Eagle Lake, for example, uses <code>eagle-lake</code>. <a target='_blank' rel='noopener' href='https://unitedstarsofamerica.com/node/394'>Live demo of all available fonts</a>."),
        "#options" => $this->options->nameTitleGoogleFont(),
        "#default_value" => $settings->get('page_title.google_font'),
      ];

      // Display & Effects Tab.
      $form["solo_settings"]["settings_{$region}"]["display_effects"] = [
        "#type" => "details",
        "#title" => $this->t("Display & Effects"),
        "#group" => "page_title_tabs",
        "#weight" => -8,
      ];

      $form["solo_settings"]["settings_{$region}"]["display_effects"]["classes"] = $this->elements->cssClasses($settings, $region);

      $form["solo_settings"]["settings_{$region}"]["display_effects"]["page_title__animation"] = [
        "#parents" => ['page_title', 'animation'],
        "#type" => "select",
        "#title" => $this->t("Select the desired CSS text animation effects for the page title."),
        "#options" => $this->options->animatePageTitle(),
        "#description" => $this->t("Animations are sorted by name (e.g. 'Aurora Wave', 'Dreamy Bloom'). The effects are also CSS classes for any HTML tag: <code>animate-site-</code> then the animation name, for example <code>animate-solo-aurora-wave</code>. <a target='_blank' rel='noopener' href='https://unitedstarsofamerica.com/node/190'>Live demo of all available animations</a>."),
        "#default_value" => $settings->get('page_title.animation'),
      ];

      $form["solo_settings"]["settings_{$region}"]["display_effects"]["page_title__centered"] = [
        "#parents" => ['page_title', 'centered'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to center Page Title."),
        "#default_value" => $settings->get('page_title.centered'),
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "page_title_tabs",
        "#weight" => -6,
        '#attributes' => [
          'class' => ['solo-color-panel'],
          'data-solo-panel' => 'colors',
        ],
      ];

      $this->elements->addRegionColors($form["solo_settings"]["settings_{$region}"]["color_settings"], $settings, $region);

    }
  }

}
