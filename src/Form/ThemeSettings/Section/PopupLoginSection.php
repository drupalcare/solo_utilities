<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Popup Login Block section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class PopupLoginSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Popup login block region.
    if ($context->hasMixRegion("popup_login_block")) {

      $region = "popup_login_block";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure the popup login functionality, styling, and advanced behavior options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["popup_login_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-popup-configuration",
      ];

      // Popup Configuration Tab.
      $form["solo_settings"]["settings_{$region}"]["popup_configuration"] = [
        "#type" => "details",
        "#title" => $this->t("Popup Configuration"),
        "#group" => "popup_login_tabs",
        "#weight" => -10,
        "#id" => "edit-popup-configuration",
      ];

      $form["solo_settings"]["settings_{$region}"]["popup_configuration"]["classes"] = $this->elements->cssClasses($settings, $region);

      $form["solo_settings"]["settings_{$region}"]["popup_configuration"]["popup_login__enabled"] = [
        "#parents" => ['popup_login', 'enabled'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to login via a popup block."),
        "#description" => $this->t("Instead of redirecting users to a separate login page, a popup block with the login fields appears on the current page. <strong><a href='/admin/structure/block'>The User Login Block Must Be Enabled</a></strong>."),
        "#default_value" => $settings->get('popup_login.enabled'),
      ];

      $form["solo_settings"]["settings_{$region}"]["popup_configuration"]["popup_login__link_text"] = [
        "#parents" => ['popup_login', 'link_text'],
        "#type" => "textfield",
        "#title" => $this->t("You can change the login word."),
        "#default_value" => $settings->get('popup_login.link_text') ?? "Login",
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Advanced Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["advanced_settings"] = [
        "#type" => "details",
        "#title" => $this->t("Advanced Settings"),
        "#group" => "popup_login_tabs",
        "#weight" => -8,
      ];

      // Animation duration - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["advanced_settings"]["popup_login__animation_duration"] = [
        "#parents" => ['popup_login', 'animation_duration'],
        "#type" => "number",
        "#title" => $this->t("Animation duration (milliseconds)"),
        "#description" => $this->t("Duration of open/close animations in milliseconds. Default: 300ms"),
        "#default_value" => $settings->get('popup_login.animation_duration') ?? 300,
        "#min" => 0,
        "#max" => 5000,
        "#step" => 50,
        "#field_suffix" => $this->t("ms"),
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Z-index setting - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["advanced_settings"]["popup_login__z_index"] = [
        "#parents" => ['popup_login', 'z_index'],
        "#type" => "number",
        "#title" => $this->t("Z-index"),
        "#description" => $this->t("The z-index value for the popup overlay. Adjust if the popup appears behind other elements. Default: 10000"),
        "#default_value" => $settings->get('popup_login.z_index') ?? 10000,
        "#min" => 1,
        "#max" => 999999,
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Overlay opacity - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["advanced_settings"]["popup_login__overlay_opacity"] = [
        "#parents" => ['popup_login', 'overlay_opacity'],
        "#type" => "number",
        "#title" => $this->t("Overlay opacity"),
        "#description" => $this->t("The opacity of the background overlay (0-100). Default: 50%"),
        "#default_value" => $settings->get('popup_login.overlay_opacity') ?? 50,
        "#min" => 0,
        "#max" => 100,
        "#field_suffix" => "%",
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Custom trigger selectors - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["advanced_settings"]["popup_login__custom_triggers"] = [
        "#parents" => ['popup_login', 'custom_triggers'],
        "#type" => "textfield",
        "#title" => $this->t("Custom trigger selectors"),
        "#description" => $this->t("Additional CSS selectors for elements that should trigger the login popup. Separate multiple selectors with commas. Example: .my-login-button, #custom-login-link"),
        "#default_value" => $settings->get('popup_login.custom_triggers') ?? "",
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Style method setting - This controls ONLY how display property is applied.
      $form["solo_settings"]["settings_{$region}"]["advanced_settings"]["popup_login__use_inline_styles"] = [
        "#parents" => ['popup_login', 'use_inline_styles'],
        "#type" => "checkbox",
        "#title" => $this->t("Use inline styles for display property"),
        "#description" => $this->t("How popup visibility is managed: checked uses <code>style.display</code> directly, unchecked uses CSS classes. Animation, z-index and other settings always work via CSS variables. Only change this if you experience conflicts with other modules."),
        "#default_value" => $settings->get('popup_login.use_inline_styles') ?? FALSE,
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Accessibility & Behavior Tab.
      $form["solo_settings"]["settings_{$region}"]["accessibility_behavior"] = [
        "#type" => "details",
        "#title" => $this->t("Accessibility & Behavior"),
        "#group" => "popup_login_tabs",
        "#weight" => -6,
      ];

      // Close on escape key - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["accessibility_behavior"]["popup_login__close_on_escape"] = [
        "#parents" => ['popup_login', 'close_on_escape'],
        "#type" => "checkbox",
        "#title" => $this->t("Close on Escape key"),
        "#description" => $this->t("Allow users to close the popup by pressing the Escape key. Recommended for accessibility."),
        "#default_value" => $settings->get('popup_login.close_on_escape') ?? TRUE,
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Close on outside click - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["accessibility_behavior"]["popup_login__close_on_outside_click"] = [
        "#parents" => ['popup_login', 'close_on_outside_click'],
        "#type" => "checkbox",
        "#title" => $this->t("Close on outside click"),
        "#description" => $this->t("Close the popup when clicking outside of the login form. Recommended for better UX."),
        "#default_value" => $settings->get('popup_login.close_on_outside_click') ?? TRUE,
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Focus trap - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["accessibility_behavior"]["popup_login__focus_trap"] = [
        "#parents" => ['popup_login', 'focus_trap'],
        "#type" => "checkbox",
        "#title" => $this->t("Enable focus trap"),
        "#description" => $this->t("Trap keyboard focus within the popup for better accessibility. Recommended for WCAG 2.1 compliance."),
        "#default_value" => $settings->get('popup_login.focus_trap') ?? TRUE,
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Screen reader announcements - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["accessibility_behavior"]["popup_login__announce_to_screen_readers"] = [
        "#parents" => ['popup_login', 'announce_to_screen_readers'],
        "#type" => "checkbox",
        "#title" => $this->t("Announce to screen readers"),
        "#description" => $this->t("Announce popup state changes to screen reader users for better accessibility. Recommended for WCAG 2.1 compliance."),
        "#default_value" => $settings->get('popup_login.announce_to_screen_readers') ?? TRUE,
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Return focus on close - ALWAYS VISIBLE when popup is enabled.
      $form["solo_settings"]["settings_{$region}"]["accessibility_behavior"]["popup_login__return_focus_on_close"] = [
        "#parents" => ['popup_login', 'return_focus_on_close'],
        "#type" => "checkbox",
        "#title" => $this->t("Return focus on close"),
        "#description" => $this->t("Return keyboard focus to the element that triggered the popup when it closes. Recommended for accessibility."),
        "#default_value" => $settings->get('popup_login.return_focus_on_close') ?? TRUE,
        "#states" => [
          "visible" => [
            ":input[name='popup_login[enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings_tab"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "popup_login_tabs",
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
