<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Header section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class HeaderSection implements SettingsSectionInterface {

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
    // Header region.
    if ($context->hasMixRegion("header")) {

      $region = "header";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure the header layout, branding, typography, and styling options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["header_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-header-layout-positioning",
      ];

      // Header Layout & Positioning Tab.
      $form["solo_settings"]["settings_{$region}"]["header_layout_positioning"] = [
        "#type" => "details",
        "#title" => $this->t("Header Layout & Positioning"),
        "#group" => "header_tabs",
        "#weight" => -10,
        "#id" => "edit-header-layout-positioning",
      ];

      $form["solo_settings"]["settings_{$region}"]["header_layout_positioning"]["classes"] = $this->elements->cssClasses($settings, $region);

      $form["solo_settings"]["settings_{$region}"]["header_layout_positioning"]["header__site_name__centered_default"] = [
        "#parents" => ['header', 'site_name', 'centered_default'],
        "#type" => "checkbox",
        "#title" => $this->t("Center the logo, with name and slogan beside it"),
        "#description" => $this->t("Centers the Site's Logo, Site's Name and Slogan within the header: the logo on the left, the site name and slogan centered alongside it."),
        "#default_value" => $settings->get('header.site_name.centered_default'),
      ];

      $form["solo_settings"]["settings_{$region}"]["header_layout_positioning"]["header__site_name__centered"] = [
        "#parents" => ['header', 'site_name', 'centered'],
        "#type" => "checkbox",
        "#title" => $this->t("Center the logo above name and slogan"),
        "#description" => $this->t("Aligns the Site's Logo, Site's Name and Slogan centrally: the logo at the top, followed by the site name and slogan."),
        "#default_value" => $settings->get('header.site_name.centered'),
      ];

      $form["solo_settings"]["settings_{$region}"]["header_layout_positioning"]["header__site_name__centered_small"] = [
        "#parents" => ['header', 'site_name', 'centered_small'],
        "#type" => "checkbox",
        "#title" => $this->t("Center the logo above name and slogan on small screens"),
        "#description" => $this->t("Aligns the Site's Logo, Site's Name and Slogan centrally on small screens: the logo at the top, followed by the site name and slogan. Follows the global breakpoints set on the site."),
        "#default_value" => $settings->get('header.site_name.centered_small'),
      ];

      $form["solo_settings"]["settings_{$region}"]["header_layout_positioning"]["header__change_icons"] = [
        "#parents" => ['header', 'change_icons'],
        "#type" => "checkbox",
        "#title" => $this->t("Swap the header sides"),
        "#description" => $this->t("LTR languages: Search Icon, User Menu, Sidebar Menu and Sidebar Hamburger on the left, logo and site name/slogan on the right. RTL languages: mirrored. The Main Sidebar menu opens from the left in LTR and from the right in RTL."),
        "#default_value" => $settings->get('header.change_icons'),
      ];

      // Branding & Typography Tab.
      $form["solo_settings"]["settings_{$region}"]["branding_typography"] = [
        "#type" => "details",
        "#title" => $this->t("Branding & Typography"),
        "#group" => "header_tabs",
        "#weight" => -8,
      ];

      $form["solo_settings"]["settings_{$region}"]["branding_typography"]["header__logo_alt"] = [
        "#parents" => ['header', 'logo_alt'],
        "#type" => "textfield",
        "#title" => $this->t("Enter the text to be used for the Site Name Link Title and Site Logo Image Alt."),
        "#default_value" => $settings->get('header.logo_alt'),
      ];

      $form["solo_settings"]["settings_{$region}"]["branding_typography"]["header__site_name__font_size_small"] = [
        "#parents" => ['header', 'site_name', 'font_size_small'],
        "#title" => $this->t("Select the desired font size for the Site Name on small screen."),
        "#type" => "select",
        "#options" => $this->options->siteNameFontSize(),
        "#default_value" => $settings->get('header.site_name.font_size_small'),
        "#description" => $this->t("Small screens, in pixels or rem units. Follows the website's breakpoints, found under Global Site Settings."),
      ];

      $form["solo_settings"]["settings_{$region}"]["branding_typography"]["header__site_name__font_size_large"] = [
        "#parents" => ['header', 'site_name', 'font_size_large'],
        "#title" => $this->t("Select the desired font size for the Site Name on large screen."),
        "#type" => "select",
        "#options" => $this->options->siteNameFontSize(),
        "#default_value" => $settings->get('header.site_name.font_size_large'),
        "#description" => $this->t("Large screens, in pixels or rem units. Follows the website's breakpoints, found under Global Site Settings."),
      ];

      $form["solo_settings"]["settings_{$region}"]["branding_typography"]["header__site_name__google_font"] = [
        "#parents" => ['header', 'site_name', 'google_font'],
        "#type" => "select",
        "#title" => $this->t("Site Name Fonts"),
        "#description"   => $this->t("Imported only: not applied to any HTML tag by default. Each of the 25 fonts has a CSS class you can apply to any HTML tag; Eagle Lake, for example, uses <code>eagle-lake</code>. <a target='_blank' rel='noopener' href='https://unitedstarsofamerica.com/node/394'>Live demo of all available fonts</a>."),
        "#options" => $this->options->nameTitleGoogleFont(),
        "#default_value" => $settings->get('header.site_name.google_font'),
      ];

      $form["solo_settings"]["settings_{$region}"]["branding_typography"]["header__site_name__animation"] = [
        "#parents" => ['header', 'site_name', 'animation'],
        "#type" => "select",
        "#title" => $this->t("Select the desired CSS text animation effects for the Site Name."),
        "#description" => $this->t("Animations are sorted by name (e.g., 'Aurora Wave', 'Dreamy Bloom'). You can also use the H1, H2, H3 text in the field below to apply color to the site name and site slogan."),
        "#options" => $this->options->animateSiteName(),
        "#default_value" => $settings->get('header.site_name.animation'),
      ];

      // Accessibility Tab.
      $form["solo_settings"]["settings_{$region}"]["accessibility"] = [
        "#type" => "details",
        "#title" => $this->t("Accessibility"),
        "#group" => "header_tabs",
        "#weight" => -6,
      ];

      $form["solo_settings"]["settings_{$region}"]["accessibility"]["skip_links__header"] = [
        "#parents" => ['skip_links', 'header'],
        "#type" => "checkbox",
        "#title" => $this->t("Enable skip to header link"),
        "#default_value" => $settings->get('skip_links.header'),
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings_tab"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "header_tabs",
        "#weight" => -4,
        '#attributes' => [
          'class' => ['solo-color-panel'],
          'data-solo-panel' => 'colors',
        ],
      ];

      $this->elements->addRegionColors($form["solo_settings"]["settings_{$region}"]["color_settings_tab"], $settings, $region);

    }
  }

}
