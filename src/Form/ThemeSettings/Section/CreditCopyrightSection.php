<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Copyright and Credit section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class CreditCopyrightSection implements SettingsSectionInterface {

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
    $region = "credit_copyright";

    $form["solo_settings"]["settings_{$region}"] = [
      "#type" => "details",
      "#title" => $this->t("Copyright & credit"),
      "#description" => $this->t("Configure copyright text, footer links, and styling for credit/copyright section."),
    ];

    // Create vertical tabs container.
    $form["solo_settings"]["settings_{$region}"]["copyright_tabs"] = [
      "#type" => "vertical_tabs",
      "#default_tab" => "edit-copyright-content",
    ];

    // Copyright & Link Settings Tab.
    $form["solo_settings"]["settings_{$region}"]["copyright_content"] = [
      "#type" => "details",
      "#title" => $this->t("Copyright & link settings"),
      "#group" => "copyright_tabs",
      "#weight" => -10,
      "#id" => "edit-copyright-content",
    ];

    $form["solo_settings"]["settings_{$region}"]["copyright_content"]["classes"] = $this->elements->cssClasses($settings, $region);

    // Toggle: simple fields vs one formatted text field (HTML, e.g. ICP).
    $form["solo_settings"]["settings_{$region}"]["copyright_content"]["footer__use_formatted_copyright"] = [
      "#parents" => ['footer', 'use_formatted_copyright'],
      "#type" => "checkbox",
      "#title" => $this->t("Use a single formatted text field for copyright and credit"),
      "#description" => $this->t("One field for copyright. When checked, four fields hide."),
      "#default_value" => $settings->get('footer.use_formatted_copyright'),
    ];

    $form["solo_settings"]["settings_{$region}"]["copyright_content"]["footer__copyright"] = [
      "#parents" => ['footer', 'copyright'],
      "#type" => "textfield",
      "#title" => $this->t("Enter copyright text"),
      "#description" => $this->t("Type %year% instead of an actual number for a dynamic copyright year. (Copyright © %year% Company Name - All rights reserved)."),
      "#default_value" => $settings->get('footer.copyright'),
      "#states" => [
        "visible" => [
          ":input[name=\"footer[use_formatted_copyright]\"]" => ["checked" => FALSE],
        ],
      ],
    ];

    $allowed_formats = $this->options->textFormats();
    $footer_formatted = $settings->get('footer.copyright_formatted');
    $form["solo_settings"]["settings_{$region}"]["copyright_content"]["footer__copyright_formatted"] = [
      "#parents" => ['footer', 'copyright_formatted'],
      "#type" => "text_format",
      "#title" => $this->t("Copyright and credit (formatted)"),
      "#description" => $this->t("Enter copyright, credit, and any footer legal text (e.g. ICP license, approval number). You can use the chosen text format for links and basic HTML. Type %year% for the current year."),
      "#default_value" => ($footer_formatted ?? [])["value"] ?? "",
      "#format" => ($footer_formatted ?? [])["format"] ?? "basic_html",
      "#allowed_formats" => $allowed_formats,
      "#states" => [
        "visible" => [
          ":input[name=\"footer[use_formatted_copyright]\"]" => ["checked" => TRUE],
        ],
      ],
    ];

    $form["solo_settings"]["settings_{$region}"]["copyright_content"]["footer__credit_link__url"] = [
      "#parents" => ['footer', 'credit_link', 'url'],
      "#type" => "textfield",
      "#title" => $this->t("Enter footer link URL"),
      "#description" => $this->t("Enter the URL for the footer link."),
      "#default_value" => $settings->get('footer.credit_link.url'),
      "#states" => [
        "visible" => [
          ":input[name=\"footer[use_formatted_copyright]\"]" => ["checked" => FALSE],
        ],
      ],
    ];

    $form["solo_settings"]["settings_{$region}"]["copyright_content"]["footer__credit_link__label"] = [
      "#parents" => ['footer', 'credit_link', 'label'],
      "#type" => "textfield",
      "#title" => $this->t("Enter footer link label"),
      "#description" => $this->t("Enter the Label for the footer link[Footer Link Label][Footer Link Text]."),
      "#default_value" => $settings->get('footer.credit_link.label'),
      "#states" => [
        "visible" => [
          ":input[name=\"footer[use_formatted_copyright]\"]" => ["checked" => FALSE],
        ],
      ],
    ];

    $form["solo_settings"]["settings_{$region}"]["copyright_content"]["footer__credit_link__text"] = [
      "#parents" => ['footer', 'credit_link', 'text'],
      "#type" => "textfield",
      "#title" => $this->t("Enter footer link text"),
      "#description" => $this->t("Enter the text for the footer link. This field is required if a URL is provided."),
      "#default_value" => $settings->get('footer.credit_link.text'),
      "#states" => [
        "visible" => [
          ":input[name=\"footer[use_formatted_copyright]\"]" => ["checked" => FALSE],
        ],
      ],
    ];

    // Color Settings Tab.
    $form["solo_settings"]["settings_{$region}"]["color_settings"] = [
      "#type" => "details",
      "#title" => $this->t("Color settings"),
      "#group" => "copyright_tabs",
      "#weight" => -8,
      '#attributes' => [
        'class' => ['solo-color-panel'],
        'data-solo-panel' => 'colors',
      ],
    ];

    $this->elements->addRegionColors($form["solo_settings"]["settings_{$region}"]["color_settings"], $settings, $region);
  }

}
