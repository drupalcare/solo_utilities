<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Page Wrapper section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class PageWrapperSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Page wrapper settings.
    $form["solo_settings"]["settings_page_wrapper"] = [
      "#type" => "details",
      "#title" => $this->t("Page Wrapper"),
      "#description"   => $this->t("The div around all regions of the site: its settings have a global effect. Its CSS should probably come from a trusted user, such as an admin or developer."),
    ];

    // Create vertical tabs container.
    $form["solo_settings"]["settings_page_wrapper"]["page_wrapper_tabs"] = [
      "#type" => "vertical_tabs",
      "#default_tab" => "edit-css-classes-styling",
    ];

    // CSS Classes & Styling Tab.
    $form["solo_settings"]["settings_page_wrapper"]["css_classes_styling"] = [
      "#type" => "details",
      "#title" => $this->t("CSS Classes & Styling"),
      "#group" => "page_wrapper_tabs",
      "#weight" => -10,
      "#id" => "edit-css-classes-styling",
    ];

    $form["solo_settings"]["settings_page_wrapper"]["css_classes_styling"]["regions__page_wrapper__classes"] = [
      "#parents" => ['regions', 'page_wrapper', 'classes'],
      "#type" => "textfield",
      "#title" => $this->t("Custom CSS Classes"),
      "#description" => $this->t("Use predefined utility classes to control layout, spacing and behavior. See <em>Utility class reference</em> below the field."),
      "#default_value" => $settings->get('regions.page_wrapper.classes'),
    ];

    // The tips use em, not code: the copy script adds a button to every li > code.
    $form["solo_settings"]["settings_page_wrapper"]["css_classes_styling"]["help_utility_classes"] = [
      "#markup" => $this->t("<details class='solo-help'><summary>Utility class reference</summary><div class='solo-help__body'><h3>Element-only classes</h3><p>These apply styles directly to the element only (not its children). Ideal for WYSIWYG fields, layout builder, and block settings.</p><ul class='solo-class-list'><li><code>dis-box-shadow</code>: Removes box shadow for a flat look</li><li><code>dis-border</code>: Removes border</li><li><code>dis-border-radius</code>: Removes rounded corners</li><li><code>dis-margin</code>: Removes all margins</li><li><code>dis-padding</code>: Removes all padding</li><li><code>dis-text-transform</code>: Preserves original text case</li><li><code>solo-align-left</code>: Aligns element left using margin auto</li><li><code>solo-align-center</code>: Centers element horizontally</li><li><code>solo-align-right</code>: Aligns element right using margin auto</li><li><code>solo-display-none</code>: Hides the element entirely</li><li><code>solo-visually-hidden</code>: Hides visually but remains accessible to screen readers</li><li><code>solo-no-wrap</code>: Prevents text wrapping</li><li><code>solo-break-word</code>: Allows long words to break</li><li><code>solo-truncate</code>: Truncates text with ellipsis</li><li><code>solo-overflow-hidden</code>: Hides overflowing content</li><li><code>solo-overflow-auto</code>: Enables scroll if content overflows</li><li><code>solo-mobile-hide</code>: Hides on small screens</li><li><code>solo-desktop-hide</code>: Hides on larger screens</li><li><code>inline-radio-checkbox</code>: Displays checkboxes and radio buttons inline rather than stacked, improving compactness in forms</li></ul><h3>Region-wide text alignment</h3><p>These apply <code>text-align</code> to all child elements. Best used for regions or wrappers in theme settings.</p><ul class='solo-class-list'><li><code>solo-text-left</code>: Aligns all nested text left</li><li><code>solo-text-center</code>: Aligns all nested text center</li><li><code>solo-text-right</code>: Aligns all nested text right</li></ul><h3>Disable theme features</h3><p>Useful for disabling built-in animations or styling behavior.</p><ul class='solo-class-list'><li><code>image-auto</code>: Resets linked images to original size</li><li><code>dis-anim-link</code>: Disables hover animation on menu links</li><li><code>dis-anim-submit</code>: Disables button wiggle animation</li></ul><h3>Usage tips</h3><ul><li>Use these classes in the 'CSS Classes' field of regions, blocks, views, layout sections, or paragraphs.</li><li>Most classes apply <em>!important</em> to ensure consistency across themes and modules.</li><li>For text alignment, use <em>solo-text-*</em> for children, and <em>solo-align-*</em> for block alignment.</li></ul><div class='solo-note solo-note--tip'><strong>Note:</strong> Avoid overusing utility classes in deeply nested structures to maintain maintainability and design clarity.</div></div></details>"),
    ];

    // Animation Settings Tab.
    $form["solo_settings"]["settings_page_wrapper"]["animation_settings"] = [
      "#type" => "details",
      "#title" => $this->t("Animation Settings"),
      "#group" => "page_wrapper_tabs",
      "#weight" => -8,
    ];

    $form["solo_settings"]["settings_page_wrapper"]["animation_settings"]["regions__page_wrapper__opacity"] = [
      "#parents" => ['regions', 'page_wrapper', 'opacity'],
      "#type" => "checkbox",
      "#title" => $this->t("Enable Smooth Page Load Animation"),
      "#description" => $this->t("Gradually increases the page's visibility from transparent to fully opaque when it is loaded."),
      "#default_value" => $settings->get('regions.page_wrapper.opacity'),
    ];

    // Color Settings Tab.
    $form["solo_settings"]["settings_page_wrapper"]["color_settings_tab"] = [
      "#type" => "details",
      "#title" => $this->t("Color Settings"),
      "#group" => "page_wrapper_tabs",
      "#weight" => -6,
      '#attributes' => [
        'class' => ['solo-color-panel'],
        'data-solo-panel' => 'colors',
      ],
    ];

    $region = "page_wrapper";

    $this->elements->addRegionColors($form["solo_settings"]["settings_page_wrapper"]["color_settings_tab"], $settings, $region);
  }

}
