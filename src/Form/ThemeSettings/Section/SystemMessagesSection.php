<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The System Messages region section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class SystemMessagesSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // System message region.
    if ($context->hasMixRegion("system_messages")) {

      $region = "system_messages";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure system message display, background customization, and styling options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["system_messages_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-message-configuration",
      ];

      // Message Configuration Tab.
      $form["solo_settings"]["settings_{$region}"]["message_configuration"] = [
        "#type" => "details",
        "#title" => $this->t("Message configuration"),
        "#group" => "system_messages_tabs",
        "#weight" => -10,
        "#id" => "edit-message-configuration",
      ];

      $form["solo_settings"]["settings_{$region}"]["message_configuration"]["classes"] = $this->elements->cssClasses($settings, $region);

      $form["solo_settings"]["settings_{$region}"]["message_configuration"]["regions__system_messages__disable_dark"] = [
        "#parents" => ['regions', 'system_messages', 'disable_dark'],
        "#type" => "checkbox",
        "#title" => $this->t("Personalize the system messages background"),
        "#description" => $this->t("Tick this box to match the background of your system messages to your brand. This disables the default background."),
        "#default_value" => $settings->get('regions.system_messages.disable_dark'),
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings"] = [
        "#type" => "details",
        "#title" => $this->t("Color settings"),
        "#group" => "system_messages_tabs",
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
