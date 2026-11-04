<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Social Media Links section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class SocialIconsSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    $form["solo_settings"]["settings_social_icons"] = [
      "#type" => "details",
      "#title" => $this->t("Social Media Links"),
      "#description" => $this->t("Configure social media icon display, styling, and platform links."),
    ];

    // Create vertical tabs container.
    $form["solo_settings"]["settings_social_icons"]["social_media_tabs"] = [
      "#type" => "vertical_tabs",
      "#default_tab" => "edit-general-settings",
    ];

    // General Settings Tab.
    $form["solo_settings"]["settings_social_icons"]["general_settings"] = [
      "#type" => "details",
      "#title" => $this->t("General Settings"),
      "#group" => "social_media_tabs",
      "#weight" => -10,
      "#id" => "edit-general-settings",
    ];

    $form["solo_settings"]["settings_social_icons"]["general_settings"]["social_icons__size"] = [
      "#parents" => ['social_icons', 'size'],
      "#type" => "select",
      "#title" => $this->t("The size of the social media icons can be altered from here."),
      "#default_value" => $settings->get('social_icons.size'),
      "#options" => [
        "sm-icons-small" => $this->t("Small - Default"),
        "sm-icons-medium"  => $this->t("Medium icons"),
        "sm-icons-large"   => $this->t("Large icons"),
      ],
    ];

    $form["solo_settings"]["settings_social_icons"]["general_settings"]["social_icons__color"] = [
      "#parents" => ['social_icons', 'color'],
      "#type" => "textfield",
      // Long enough for a var() reference; 7 only fitted a hex color, which made
      // the "enter var(--name)" advice impossible to follow.
      "#maxlength" => 40,
      "#size" => 20,
      "#title" => $this->t("Change Social Media Default Colors"),
      "#description" => $this->t("A hex color such as #1877f2, an rgb() value, transparent, currentcolor, or a custom property reference such as var(--solo-fb). A bare property name like --solo-fb will not work: browsers ignore it and the icons stay gray. Leave empty to use each network's default color."),
      "#default_value" => $settings->get('social_icons.color'),
      // No browser "pattern": SettingsFormValidator (CssValueValidator::color)
      // is the one rule, so the browser cannot refuse what the server accepts.
      "#wrapper_attributes" => [
        "data-drupal-selector" => "solo-color-picker",
      ],
    ];

    $form["solo_settings"]["settings_social_icons"]["general_settings"]["social_icons__enabled"] = [
      "#parents" => ['social_icons', 'enabled'],
      "#type" => "checkbox",
      "#title" => $this->t("Show Social Icons"),
      "#default_value" => $settings->get('social_icons.enabled'),
      "#description" => $this->t("Select this option to display the Social Icon. Deselect it to conceal it."),
    ];

    // Social Platform Links Tab.
    $form["solo_settings"]["settings_social_icons"]["platform_links"] = [
      "#type" => "details",
      "#title" => $this->t("Social Platform Links"),
      "#group" => "social_media_tabs",
      "#weight" => -8,
    ];

    $social_icons = [
      "facebook" => $this->t("Facebook"),
      "twitter" => $this->t("Formerly (Twitter) X"),
      "bluesky" => $this->t("Bluesky"),
      "pinterest" => $this->t("Pinterest"),
      "linkedin" => $this->t("Linkedin"),
      "instagram" => $this->t("Instagram"),
      "youtube" => $this->t("Youtube"),
      "mastodon" => $this->t("Mastodon"),
      "reddit" => $this->t("Reddit"),
      "whatsapp" => $this->t("WhatsApp"),
      "telegram" => $this->t("Telegram"),
      "snapchat" => $this->t("Snapchat"),
      "threads" => $this->t("Threads"),
      "tiktok" => $this->t("TikTok"),
      "drupal" => $this->t("Drupal"),
      "rss" => $this->t("RSS"),
      "email" => $this->t("Email"),
    ];

    foreach ($social_icons as $key => $value) {
      $form["solo_settings"]["settings_social_icons"]["platform_links"]["link_" . $key] = [
        "#parents" => ['social_icons', 'links', $key],
        "#type" => "textfield",
        "#title" => $value,
        "#default_value" => $settings->get("social_icons.links.{$key}"),
      ];
    }
  }

}
