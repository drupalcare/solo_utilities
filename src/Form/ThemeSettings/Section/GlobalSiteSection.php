<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * The Global Site section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class GlobalSiteSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
    private readonly SettingsOptions $options,
    private readonly SoloThemeDetection $themeDetection,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    $form["solo_settings"]["settings_global_misc"] = [
      "#type" => "details",
      "#title" => $this->t("Global Site"),
    ];

    // Create vertical tabs container.
    $form["solo_settings"]["settings_global_misc"]["global_misc_tabs"] = [
      "#type" => "vertical_tabs",
      "#default_tab" => "edit-layout-dimensions",
    ];

    $this->buildLayoutDimensions($form, $settings);
    $this->buildTypography($form, $settings);
    $this->buildBlockVisibility($form, $settings);
    $this->buildComments($form, $settings);
    $this->buildCustomCode($form, $settings);
    $this->buildInterfaceBehavior($form, $settings);
    $this->buildAccessibility($form, $settings);
    $this->buildPreloader($form, $settings);
    $this->buildBackToTop($form, $settings);
    $this->buildMenuTemplates($form, $settings);
  }

  /**
   * Adds the Layout and Dimensions tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildLayoutDimensions(array &$form, BoundThemeSettings $settings): void {
    // Layout & Dimensions Tab.
    $form["solo_settings"]["settings_global_misc"]["layout_dimensions"] = [
      "#type" => "details",
      "#title" => $this->t("Layout & Dimensions"),
      "#group" => "global_misc_tabs",
      "#weight" => -10,
      "#id" => "edit-layout-dimensions",
    ];

    $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["layout__width__use_pixels"] = [
      "#parents" => ['layout', 'width', 'use_pixels'],
      "#type" => "checkbox",
      "#title" => $this->t("Enable PX-Based Global Website Width"),
      "#default_value" => $settings->get('layout.width.use_pixels'),
      "#description" => $this->t("Switches the website's width from rem units to fixed pixel-based widths. <a href='https://alaahaddad.com/node/70' target='_blank' rel='noopener'>Learn more</a>."),
    ];

    $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["layout__width__global"] = [
      "#parents" => ['layout', 'width', 'global'],
      "#title" => $this->t("Global Website Width - Solo Theme"),
      "#type" => "select",
      "#options" => $this->options->siteWidth(),
      "#default_value" => $settings->get('layout.width.global') ?? "sw-1600",
      "#description" => $this->t("Adjust the entire website's width, in pixels or rem units."),
    ];

    // Content type specific width settings.
    $content_types = $this->options->contentTypes();
    $content_types_width = $this->options->contentTypeWidth();
    if ($this->themeDetection->isDefault()) {
      // Checkbox to enable custom widths for each content type.
      $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["layout__width__per_content_type_enabled"] = [
        "#parents" => ['layout', 'width', 'per_content_type_enabled'],
        "#type" => "checkbox",
        "#title" => $this->t("Enable custom widths for each content type"),
        "#default_value" => $settings->get('layout.width.per_content_type_enabled'),
      ];

      // Collapsible details element for content type specific width settings.
      $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["content_type_widths"] = [
        "#type" => "details",
        "#title" => $this->t("Content Type Widths"),
        "#open" => TRUE,
        "#states" => [
          "visible" => [
            ":input[name='layout[width][per_content_type_enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      $site_widths = $settings->array('layout.width.per_content_type');
      $global_width = $settings->get('layout.width.global') ?? "sw-1600";
      foreach ($content_types as $type => $label) {
        $override = $site_widths[$type] ?? NULL;
        $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["content_type_widths"]["width_" . $type] = [
          "#parents" => ['layout', 'width', 'per_content_type', $type],
          "#title" => $this->t("Width for @content_type", ["@content_type" => $label]),
          "#type" => "select",
          "#options" => $content_types_width,
          "#default_value" => $override ?? $global_width,
          "#description" => $this->t("Adjust the width for the @content_type content type, in pixels or rem units.", ["@content_type" => $label]),
          "#states" => [
            "visible" => [
              ":input[name='layout[width][per_content_type_enabled]']" => ["checked" => TRUE],
            ],
          ],
        ];
      }

      // Checkbox to enable custom widths for individual nodes.
      $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["layout__width__per_node_enabled"] = [
        "#parents" => ['layout', 'width', 'per_node_enabled'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to enable custom widths for individual nodes or for each content type."),
        "#default_value" => $settings->get('layout.width.per_node_enabled'),
        "#description" => $this->t("Adds a dropdown to the node edit form for choosing a custom width per node. 'None' uses the default width. Individual node widths take priority over content type widths, which take priority over the global width."),
      ];
    }
    else {
      // Display a message if the module is not enabled or the Solo theme or
      // its sub-themes are not active.
      $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["notice_enable_custom_node_width_message"] = [
        "#input" => FALSE,
        "#type" => "item",
        "#title" => $this->t("Enable custom widths for individual nodes or each content type."),
        "#markup" => $this->t("<div class='solo-note solo-note--warning'>Requires the <a href='https://www.drupal.org/project/solo_utilities' target='_blank' rel='noopener'>Solo Utilities module</a>, installed and enabled, and Solo or one of its sub-themes in active use: the default theme, or a parent, grandparent or other part of the active theme hierarchy.</div>"),
      ];
    }

    $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["layout__breakpoints__page"] = [
      "#parents" => ['layout', 'breakpoints', 'page'],
      "#title" => $this->t("Website's Breakpoints - Solo Theme"),
      "#type" => "select",
      "#options" => $this->options->siteBreakpoints(),
      "#default_value" => $settings->get('layout.breakpoints.page') ?? "sb-992",
      "#description" => $this->t("Use them to adjust the global layout for a particular screen or device size."),
    ];

    $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["layout__collapse_order"] = [
      "#parents" => ['layout', 'collapse_order'],
      "#type" => "checkbox",
      "#title" => $this->t("Tick this box to modify the layout collapse order."),
      "#description"   => $this->t("Collapses grouped regions into a responsive grid on smaller screens. Applies only if the group contains exactly three or four regions."),
      "#default_value" => $settings->get('layout.collapse_order'),
    ];

    $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["layout__breakpoints__menu"] = [
      "#parents" => ['layout', 'breakpoints', 'menu'],
      "#title" => $this->t("Menu's Breakpoints - Solo Theme"),
      "#type" => "select",
      "#options" => $this->options->menuBreakpoints(),
      "#default_value" => $settings->get('layout.breakpoints.menu') ?? "mn-992",
      "#description" => $this->t("Adjust the global layout for any responsive menu on a specific screen or device size."),
    ];

    $form["solo_settings"]["settings_global_misc"]["layout_dimensions"]["layout__regions_gap"] = [
      "#parents" => ['layout', 'regions_gap'],
      "#title" => $this->t("Website Region's Spaces"),
      "#type" => "select",
      "#options" => $this->options->siteGlobalRegionsGap(),
      "#default_value" => $settings->get('layout.regions_gap') ?? "16",
      "#description" => $this->t("Adjust the entire website's padding, in pixels or rem units."),
    ];
  }

  /**
   * Adds the Typography and Content tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildTypography(array &$form, BoundThemeSettings $settings): void {
    // Typography & Content Tab.
    $form["solo_settings"]["settings_global_misc"]["typography_content"] = [
      "#type" => "details",
      "#title" => $this->t("Typography & Content"),
      "#group" => "global_misc_tabs",
      "#weight" => -8,
    ];

    $form["solo_settings"]["settings_global_misc"]["typography_content"]["typography__font_size"] = [
      "#parents" => ['typography', 'font_size'],
      "#title" => $this->t("Website's Font Size"),
      "#type" => "select",
      "#options" => $this->options->siteGlobalFontSize(),
      "#default_value" => $settings->get('typography.font_size') ?? "16",
      "#description" => $this->t("Adjust the entire website's font size, in pixels or rem units."),
    ];

    $form["solo_settings"]["settings_global_misc"]["typography_content"]["typography__node_date_format"] = [
      "#parents" => ['typography', 'node_date_format'],
      "#title" => $this->t("Choose the node date format."),
      "#type" => "select",
      "#options" => $this->options->siteNodeDateFormat(),
      "#default_value" => $settings->get('typography.node_date_format') ?? "solo_date_l",
      "#description" => $this->t("Select a custom format for the node creation date. Choose '- None -' to use the default format provided by Drupal core or contributed modules."),
    ];

    $form["solo_settings"]["settings_global_misc"]["typography_content"]["typography__imported_google_font"] = [
      "#parents" => ['typography', 'imported_google_font'],
      "#type" => "textfield",
      "#title" => $this->t("Import Google Font"),
      "#description" => $this->t("The Google Fonts URL. See <em>How to import a Google font</em> below the fields."),
      "#default_value" => $settings->get('typography.imported_google_font'),
    ];

    $form["solo_settings"]["settings_global_misc"]["typography_content"]["typography__reading_mode_content_types"] = [
      "#parents" => ['typography', 'reading_mode_content_types'],
      "#type" => "select",
      "#title" => $this->t("Reading Mode (45rem/720px)"),
      "#default_value" => $settings->get('typography.reading_mode_content_types') ?? "",
      "#options" => $this->options->contentTypes(),
      "#multiple" => TRUE,
      "#description" => $this->t("Sets the maximum width of the content region to 45rem/720px for the chosen content types, for both Text Formatted Long and Text Formatted Long with Summary. Default/Full view mode only. A common guideline for comfortable reading is 60 to 75 characters per line."),
    ];

    $form["solo_settings"]["settings_global_misc"]["typography_content"]["help_google_font"] = [
      "#markup" => $this->t("<details class='solo-help'><summary>How to import a Google font</summary><div class='solo-help__body'><ol><li>Obtain the Google Fonts URL by visiting Google Fonts and copying the URL. For example, <code>https://fonts.googleapis.com/css2?family=Teko:wght@300;400;500;600;700&display=swap</code>.</li><li>Add the CSS rules that specify the font families. For instance, <code>h1, h2, h3 {font-family: 'Teko', sans-serif !important;}</code>.</li></ol><div class='solo-note solo-note--warning'><strong>Note:</strong> If you are using Global Site Fonts or Heading Fonts, you must include <code>!important</code> in your CSS.</div></div></details>"),
    ];
  }

  /**
   * Adds the Block and Visibility tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildBlockVisibility(array &$form, BoundThemeSettings $settings): void {
    // Block & Visibility Tab.
    $form["solo_settings"]["settings_global_misc"]["block_visibility"] = [
      "#type" => "details",
      "#title" => $this->t("Block & Visibility"),
      "#group" => "global_misc_tabs",
      "#weight" => -6,
    ];

    $form["solo_settings"]["settings_global_misc"]["block_visibility"]["blocks__title_visibility_enabled"] = [
      "#parents" => ['blocks', 'title_visibility_enabled'],
      "#type" => "checkbox",
      "#title" => $this->t("Enable custom block title visibility"),
      "#description" => $this->t("Allows control over each block's title visibility: visible, visually hidden (for screen readers), or completely removed from HTML output."),
      "#default_value" => $settings->get('blocks.title_visibility_enabled'),
    ];
  }

  /**
   * Adds the Comments tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildComments(array &$form, BoundThemeSettings $settings): void {
    // Comments Tab.
    $form["solo_settings"]["settings_global_misc"]["comments"] = [
      "#type" => "details",
      "#title" => $this->t("Comments"),
      "#group" => "global_misc_tabs",
      "#weight" => -5,
    ];

    // Accessibility notice.
    $form["solo_settings"]["settings_global_misc"]["comments"]["accessibility_notice"] = [
      "#type" => "container",
      "#attributes" => [
        "class" => ["solo-note", "solo-note--tip"],
      ],
      "content" => [
        "#markup" => "<strong>" . $this->t("Accessibility Tip:") . "</strong> " . $this->t("For better screen reader navigation, enable 'Display comment subject before author information', so users can navigate comments by heading (typically the H key in screen readers)."),
      ],
    ];

    // Comment display order fieldset.
    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_display_order"] = [
      "#type" => "fieldset",
      "#title" => $this->t("Display Order"),
      "#description" => $this->t("Control the order of comment elements for improved accessibility."),
    ];

    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_display_order"]["comments__heading_first"] = [
      "#parents" => ['comments', 'heading_first'],
      "#type" => "checkbox",
      "#title" => $this->t("Display comment subject before author information"),
      "#description" => $this->t("The comment subject (heading) appears before the author name and date, so screen reader users can navigate comments by heading. <strong>Recommended for accessibility compliance.</strong>"),
      "#default_value" => $settings->get('comments.heading_first') ?? TRUE,
    ];

    // Comment form position fieldset.
    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_form_position_settings"] = [
      "#type" => "fieldset",
      "#title" => $this->t("Comment Form Position"),
      "#description" => $this->t("Control where the comment form appears relative to the list of existing comments."),
    ];

    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_form_position_settings"]["comments__form_position"] = [
      "#parents" => ['comments', 'form_position'],
      "#type" => "radios",
      "#title" => $this->t("Comment form position"),
      "#description" => $this->t("Whether the 'Add new comment' form appears before or after the existing comments. After is useful when visitors should read the discussion before adding their own comment."),
      "#default_value" => $settings->get('comments.form_position') ?? "before",
      "#options" => [
        "before" => $this->t("Before comments (default)"),
        "after" => $this->t("After comments"),
      ],
    ];

    // Comment visibility fieldset.
    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_visibility"] = [
      "#type" => "fieldset",
      "#title" => $this->t("Element Visibility"),
      "#description" => $this->t("Choose which comment metadata elements to display."),
    ];

    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_visibility"]["comments__show_author"] = [
      "#parents" => ['comments', 'show_author'],
      "#type" => "checkbox",
      "#title" => $this->t("Show author name"),
      "#default_value" => $settings->get('comments.show_author') ?? TRUE,
    ];

    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_visibility"]["comments__show_date"] = [
      "#parents" => ['comments', 'show_date'],
      "#type" => "checkbox",
      "#title" => $this->t("Show comment date"),
      "#default_value" => $settings->get('comments.show_date') ?? TRUE,
    ];

    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_visibility"]["comments__show_picture"] = [
      "#parents" => ['comments', 'show_picture'],
      "#type" => "checkbox",
      "#title" => $this->t("Show user picture"),
      "#default_value" => $settings->get('comments.show_picture') ?? TRUE,
    ];

    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_visibility"]["comments__show_permalink"] = [
      "#parents" => ['comments', 'show_permalink'],
      "#type" => "checkbox",
      "#title" => $this->t("Show permalink"),
      "#default_value" => $settings->get('comments.show_permalink') ?? TRUE,
    ];

    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_visibility"]["comments__show_new_indicator"] = [
      "#parents" => ['comments', 'show_new_indicator'],
      "#type" => "checkbox",
      "#title" => $this->t("Show new comment indicator"),
      "#description" => $this->t("Displays a 'New' badge for comments posted since the user's last visit."),
      "#default_value" => $settings->get('comments.show_new_indicator') ?? TRUE,
    ];

    // Comment date format fieldset.
    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_date_settings"] = [
      "#type" => "fieldset",
      "#title" => $this->t("Date Format"),
    ];

    $date_format_options = $this->options->commentDateFormats();
    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_date_settings"]["comments__date_format"] = [
      "#parents" => ['comments', 'date_format'],
      "#type" => "select",
      "#title" => $this->t("Comment date format"),
      "#description" => $this->t("Select the format for displaying comment dates. You can create custom date formats at <a href='/admin/config/regional/date-time'>Date and time formats</a>."),
      "#options" => $date_format_options,
      "#default_value" => $settings->get('comments.date_format') ?? 'solo_relative',
    ];

    // Comment title settings fieldset.
    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_title_settings"] = [
      "#type" => "fieldset",
      "#title" => $this->t("Subject/Title Settings"),
    ];

    $form["solo_settings"]["settings_global_misc"]["comments"]["comment_title_settings"]["comments__title_tag"] = [
      "#parents" => ['comments', 'title_tag'],
      "#type" => "select",
      "#title" => $this->t("Heading level"),
      "#description" => $this->t("The HTML heading tag for comment subjects. Choose based on your page structure for proper document outline and accessibility."),
      "#options" => [
        "h2" => $this->t("H2 - Second level"),
        "h3" => $this->t("H3 - Third level (default)"),
        "h4" => $this->t("H4 - Fourth level"),
        "h5" => $this->t("H5 - Fifth level"),
        "h6" => $this->t("H6 - Sixth level"),
      ],
      "#default_value" => $settings->get('comments.title_tag') ?? "h3",
    ];
  }

  /**
   * Adds the Custom Code tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildCustomCode(array &$form, BoundThemeSettings $settings): void {
    // Custom Code Tab.
    $form["solo_settings"]["settings_global_misc"]["custom_code"] = [
      "#type" => "details",
      "#title" => $this->t("Custom Code"),
      "#group" => "global_misc_tabs",
      "#weight" => -4,
    ];

    $form["solo_settings"]["settings_global_misc"]["custom_code"]["custom_code__inline_css"] = [
      "#parents" => ['custom_code', 'inline_css'],
      "#type" => "textarea",
      "#title" => $this->t("CSS Injector"),
      "#description" => $this->t("Injects custom CSS into the page head on every page. Best for small snippets and quick tests. Changes take effect immediately and are stored in the theme settings."),
      "#default_value" => $settings->get('custom_code.inline_css'),
    ];

    $form["solo_settings"]["settings_global_misc"]["custom_code"]["custom_code__css_file"] = [
      "#parents" => ['custom_code', 'css_file'],
      "#type" => "textarea",
      "#title" => $this->t("CSS Dynamic"),
      "#description" => $this->t("Writes your CSS to a dedicated file loaded across your site and cached by browsers, instead of inlining it on every page. Best for substantial customizations and performance on larger sites. Saved in the public file system, in the folder of the theme being edited: @path. The part after custom. changes whenever the code changes, so browsers always load the current file.", ['@path' => 'sites/*/files/solo/THEME/custom.HASH.css']),
      "#default_value" => $settings->get('custom_code.css_file'),
    ];

    $form["solo_settings"]["settings_global_misc"]["custom_code"]["custom_code__js_file"] = [
      "#parents" => ['custom_code', 'js_file'],
      "#type" => "textarea",
      "#title" => $this->t("JavaScript Dynamic"),
      "#description" => $this->t("Writes your JavaScript to a dedicated file loaded across your site and cached by browsers, which helps reduce page load times. Your code can use Drupal's core libraries without including them: jQuery, AJAX, Drupal behaviors, drupalSettings and once. Saved in the public file system, in the folder of the theme being edited: @path. The part after custom. changes whenever the code changes, so browsers always load the current file.", ['@path' => 'sites/*/files/solo/THEME/custom.HASH.js']),
      "#default_value" => $settings->get('custom_code.js_file'),
    ];
  }

  /**
   * Adds the Interface Behavior tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildInterfaceBehavior(array &$form, BoundThemeSettings $settings): void {
    // Interface Behavior Tab.
    $form["solo_settings"]["settings_global_misc"]["interface_behavior"] = [
      "#type" => "details",
      "#title" => $this->t("Interface Behavior"),
      "#group" => "global_misc_tabs",
      "#weight" => -2,
    ];

    $form["solo_settings"]["settings_global_misc"]["interface_behavior"]["layout__centered_login_page"] = [
      "#parents" => ['layout', 'centered_login_page'],
      "#type" => "checkbox",
      "#title" => $this->t("Tick this box to modify the layout for the login, register, and password reset pages."),
      "#description"   => $this->t("The login form will be centered, with the left and right sides concealed."),
      "#default_value" => $settings->get('layout.centered_login_page'),
    ];

    $form["solo_settings"]["settings_global_misc"]["interface_behavior"]["layout__flip_header_menu"] = [
      "#parents" => ['layout', 'flip_header_menu'],
      "#type" => "checkbox",
      "#title" => $this->t("Tick this box to flip the main menu order. It will be displayed before the header."),
      "#default_value" => $settings->get('layout.flip_header_menu'),
    ];

    $form["solo_settings"]["settings_global_misc"]["interface_behavior"]["layout__reveal_on_scroll"] = [
      "#parents" => ['layout', 'reveal_on_scroll'],
      "#type" => "checkbox",
      "#title" => $this->t("Tick this box to animate opacity while scrolling down."),
      "#description"   => $this->t("Hidden blocks stay unseen when the page opens and animate into view as you scroll down. Disabled by default."),
      "#default_value" => $settings->get('layout.reveal_on_scroll'),
    ];

    $form["solo_settings"]["settings_global_misc"]["interface_behavior"]["layout__inline_field_items"] = [
      "#parents" => ['layout', 'inline_field_items'],
      "#type" => "checkbox",
      "#title" => $this->t("Tick this box to display multiple taxonomy term field items on the same line."),
      "#description"   => $this->t("By default, a field with multiple values shows them one beneath the other, which can use a lot of vertical space. This shows them side by side on the same line, separated by spaces."),
      "#default_value" => $settings->get('layout.inline_field_items'),
    ];

    $form["solo_settings"]["settings_global_misc"]["interface_behavior"]["color_scheme__colored_system_tabs"] = [
      "#parents" => ['color_scheme', 'colored_system_tabs'],
      "#type" => "checkbox",
      "#title" => $this->t("Tick this box to enhanced navigation with color-coded system tabs"),
      "#description"   => $this->t("Assigns custom colors to core system tab links, such as View, Edit, Delete and Revisions."),
      "#default_value" => $settings->get('color_scheme.colored_system_tabs'),
    ];

    $form["solo_settings"]["settings_global_misc"]["interface_behavior"]["menus__toggle_speed"] = [
      "#parents" => ['menus', 'toggle_speed'],
      "#type" => "select",
      "#title" => $this->t("Menu Animation Speed"),
      "#description" => $this->t("Controls how fast the menu opens/closes. Applies to all menus."),
      "#default_value" => $settings->get('menus.toggle_speed') ?? "normal",
      "#options" => [
        "fast" => $this->t("Fast"),
        "normal" => $this->t("Normal"),
        "slow" => $this->t("Slow"),
      ],
    ];
  }

  /**
   * Adds the Accessibility tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildAccessibility(array &$form, BoundThemeSettings $settings): void {
    $menu_options = $this->options->menus();

    // Accessbility Tab.
    $form["solo_settings"]["settings_global_misc"]["accessibility"] = [
      "#type" => "details",
      "#title" => $this->t("Accessibility"),
      "#group" => "global_misc_tabs",
      "#weight" => -1,
    ];

    $form["solo_settings"]["settings_global_misc"]["accessibility"]["menus__keyboard_navigation"] = [
      "#parents" => ['menus', 'keyboard_navigation'],
      '#type' => 'checkboxes',
      '#title' => $this->t('Arrow-key navigation for menus'),
      '#description' => $this->t('Every Solo menu already works with the keyboard: Tab moves through the links, Enter or Space opens and closes a submenu, and Escape closes it. Select menus that should also move between items with the arrow keys, Home and End. Only applies to menus using Solo templates.'),
      '#default_value' => $settings->get('menus.keyboard_navigation') ?? [],
      '#options' => $menu_options,
    ];
  }

  /**
   * Adds the Preloader tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildPreloader(array &$form, BoundThemeSettings $settings): void {
    // Preloader tab.
    $form["solo_settings"]["settings_global_misc"]["preloader"] = [
      "#type" => "details",
      "#title" => $this->t("Preloader"),
      "#group" => "global_misc_tabs",
      "#weight" => 99,
      "#description" => $this->t("A full-page overlay that shows while the page is loading and hides when the page has fully loaded."),
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["preloader__enabled"] = [
      "#parents" => ['preloader', 'enabled'],
      '#type' => 'checkbox',
      '#title' => $this->t('Enable preloader'),
      '#default_value' => $settings->get('preloader.enabled') ?? FALSE,
      '#description' => $this->t('Show a brief loading overlay. Disabled by default.'),
      '#id' => 'edit-preloader-enabled',
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["visibility"] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Visibility'),
      '#attributes' => ['class' => ['solo-preloader-visibility']],
      '#states' => ['visible' => [':input#edit-preloader-enabled' => ['checked' => TRUE]]],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["visibility"]["preloader__max_display_seconds"] = [
      "#parents" => ['preloader', 'max_display_seconds'],
      '#type' => 'number',
      '#title' => $this->t('Max display time (seconds)'),
      '#weight' => -20,
      '#default_value' => $settings->get('preloader.max_display_seconds') ?? 8,
      '#min' => 3,
      '#max' => 60,
      '#step' => 1,
      '#field_suffix' => $this->t('sec'),
      '#description' => $this->t("Fallback only: the preloader hides as soon as the page has loaded (for example, at 2 seconds on a 2-second load). This value is the maximum time to show it if the load event never fires or is delayed."),
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["visibility"]["preloader__hide_on"] = [
      "#parents" => ['preloader', 'hide_on'],
      '#type' => 'radios',
      '#title' => $this->t('Hide preloader when'),
      '#weight' => -19,
      '#default_value' => $settings->get('preloader.hide_on') ?? 'dom_ready',
      '#options' => [
        'dom_ready' => $this->t('Page appears (recommended)'),
        'load' => $this->t('Page fully loaded (images, scripts, etc.)'),
      ],
      '#description' => $this->t('When the preloader disappears. "Page appears" hides it when the HTML and layout are ready, so visitors can start reading while images load. "Page fully loaded" waits for all resources (current behavior).'),
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["visibility"]["preloader__force_show"] = [
      "#parents" => ['preloader', 'force_show'],
      '#type' => 'checkbox',
      '#title' => $this->t('Force show for testing'),
      '#default_value' => $settings->get('preloader.force_show') ?? FALSE,
      '#description' => $this->t('Show the preloader on every page for 30 seconds (logged in, admin, any path). Use this to test logo, text size, and colors. Uncheck when done.'),
      '#weight' => -10,
      '#id' => 'edit-preloader-force-show',
      '#attributes' => ['class' => ['solo-preloader-force-show']],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["visibility"]["preloader__disable_authenticated"] = [
      "#parents" => ['preloader', 'disable_authenticated'],
      '#type' => 'checkbox',
      '#title' => $this->t('Disable for authenticated users'),
      '#default_value' => $settings->get('preloader.disable_authenticated') ?? TRUE,
      '#description' => $this->t('Do not show the preloader to logged-in users. Recommended for editors.'),
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["visibility"]["preloader__disable_admin_routes"] = [
      "#parents" => ['preloader', 'disable_admin_routes'],
      '#type' => 'checkbox',
      '#title' => $this->t('Disable on admin routes'),
      '#default_value' => $settings->get('preloader.disable_admin_routes') ?? TRUE,
      '#description' => $this->t('Do not show on /admin, layout builder, content editing, etc.'),
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["visibility"]["preloader__path_rules"] = [
      "#parents" => ['preloader', 'path_rules'],
      '#type' => 'textarea',
      '#title' => $this->t('Path include/exclude rules'),
      '#default_value' => $settings->get('preloader.path_rules') ?? '',
      '#description' => $this->t('One path per line. Leave empty to use the default visibility above. <dl class="solo-deflist"><dt>Exclude</dt><dd>Prefix with a minus sign to hide the preloader on that path (e.g. <code>-/contact</code>, <code>-/about</code>).</dd><dt>Include only</dt><dd>List paths without a prefix to show the preloader only on those paths (e.g. <code>/</code> for front page only, or <code>/</code> and <code>/products</code>).</dd></dl>'),
      '#rows' => 4,
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Appearance'),
      '#attributes' => ['class' => ['solo-preloader-appearance']],
      '#states' => ['visible' => [':input#edit-preloader-enabled' => ['checked' => TRUE]]],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__style"] = [
      "#parents" => ['preloader', 'style'],
      '#type' => 'select',
      '#title' => $this->t('Style'),
      '#id' => 'edit-preloader-style',
      '#options' => [
        'spinner' => $this->t('Spinner (recommended for performance)'),
        'progress_bar' => $this->t('Progress bar'),
        'text' => $this->t('Text'),
        'logo' => $this->t('Logo'),
        'logo_text' => $this->t('Logo + text'),
      ],
      '#default_value' => $settings->get('preloader.style') ?? 'spinner',
      '#description' => $this->t('Spinner is lightest and shows immediately; progress bar shows an animated bar centered on the page; text and logo can wait on fonts or images.'),
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__transition"] = [
      "#parents" => ['preloader', 'transition'],
      '#type' => 'select',
      '#title' => $this->t('Transition effect'),
      '#options' => [
        'fade' => $this->t('Fade (default)'),
        'slide_up' => $this->t('Slide up'),
        'slide_down' => $this->t('Slide down'),
        'slide_left' => $this->t('Slide left'),
        'slide_right' => $this->t('Slide right'),
        'zoom_out' => $this->t('Zoom out'),
        'blur' => $this->t('Blur'),
      ],
      '#default_value' => $settings->get('preloader.transition') ?? 'fade',
      '#description' => $this->t('How the preloader disappears when the page is ready. All effects include a fade; slide/zoom/blur add motion.'),
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__spinner_show_percent"] = [
      "#parents" => ['preloader', 'spinner_show_percent'],
      '#type' => 'checkbox',
      '#title' => $this->t('Show percentage (0%–100%) inside spinner'),
      '#default_value' => $settings->get('preloader.spinner_show_percent') ?? FALSE,
      '#description' => $this->t('Estimated progress only (not real load %). Adds one timer and a small text update; minimal extra load.'),
      '#states' => ['visible' => [':input#edit-preloader-style' => ['value' => 'spinner']]],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__text__value"] = [
      "#parents" => ['preloader', 'text', 'value'],
      '#type' => 'textfield',
      '#title' => $this->t('Text'),
      '#id' => 'edit-preloader-text',
      '#default_value' => $settings->get('preloader.text.value') ?? '',
      '#description' => $this->t('Shown when style is "Text" or "Logo + text".'),
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'text'],
            ['value' => 'logo_text'],
          ],
        ],
      ],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__text__font"] = [
      "#parents" => ['preloader', 'text', 'font'],
      '#type' => 'select',
      '#title' => $this->t('Text Font'),
      '#options' => $this->options->nameTitleGoogleFont(),
      '#default_value' => $settings->get('preloader.text.font') ?? '',
      '#description' => $this->t('Optional. For fastest display, leave at "None"—custom fonts load from the network and can delay the preloader.'),
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'text'],
            ['value' => 'logo_text'],
          ],
        ],
      ],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__text__animate_effect"] = [
      "#parents" => ['preloader', 'text', 'animate_effect'],
      '#type' => 'select',
      '#title' => $this->t('Text animation effect'),
      '#description' => $this->t('Gradient text animation. Same options as Site Name and Page Title. For best performance leave at "None"—animation CSS can delay the preloader. Choose an effect only if you want the look.'),
      '#options' => $this->options->animateSiteName(),
      '#default_value' => $settings->get('preloader.text.animate_effect') ?? '',
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'text'],
            ['value' => 'logo_text'],
          ],
        ],
      ],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__text__font_size"] = [
      "#parents" => ['preloader', 'text', 'font_size'],
      '#type' => 'number',
      '#title' => $this->t('Text size (px)'),
      '#default_value' => $settings->get('preloader.text.font_size') ?? 24,
      '#min' => 12,
      '#max' => 124,
      '#step' => 1,
      '#field_suffix' => 'px',
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'text'],
            ['value' => 'logo_text'],
          ],
        ],
      ],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__logo__use_theme"] = [
      "#parents" => ['preloader', 'logo', 'use_theme'],
      '#type' => 'checkbox',
      '#title' => $this->t('Use logo supplied by the theme'),
      '#default_value' => $settings->get('preloader.logo.use_theme') ?? TRUE,
      '#description' => $this->t('When checked, the preloader uses the same logo as the site (theme settings). Uncheck to set a custom path below.'),
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'logo'],
            ['value' => 'logo_text'],
          ],
        ],
      ],
      '#id' => 'edit-preloader-logo-use-theme',
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__logo__url"] = [
      "#parents" => ['preloader', 'logo', 'url'],
      '#type' => 'textfield',
      '#title' => $this->t('Path to custom logo'),
      '#id' => 'edit-preloader-logo-url',
      '#default_value' => $settings->get('preloader.logo.url') ?? '',
      '#description' => $this->t('Examples: logo.svg (for a file in the public filesystem), public://logo.svg, or themes/contrib/solo/logo.svg. Only used when "Use logo supplied by the theme" is unchecked.'),
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'logo'],
            ['value' => 'logo_text'],
          ],
          ':input#edit-preloader-logo-use-theme' => ['checked' => FALSE],
        ],
      ],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__logo__width"] = [
      "#parents" => ['preloader', 'logo', 'width'],
      '#type' => 'number',
      '#title' => $this->t('Logo width (px)'),
      '#default_value' => $settings->get('preloader.logo.width') ?? 160,
      '#min' => 16,
      '#max' => 800,
      '#step' => 1,
      '#field_suffix' => 'px',
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'logo'],
            ['value' => 'logo_text'],
          ],
        ],
      ],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__logo__height"] = [
      "#parents" => ['preloader', 'logo', 'height'],
      '#type' => 'number',
      '#title' => $this->t('Logo height (px)'),
      '#default_value' => $settings->get('preloader.logo.height') ?? 80,
      '#min' => 16,
      '#max' => 600,
      '#step' => 1,
      '#field_suffix' => 'px',
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'logo'],
            ['value' => 'logo_text'],
          ],
        ],
      ],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"]["preloader__logo__opacity"] = [
      "#parents" => ['preloader', 'logo', 'opacity'],
      '#type' => 'number',
      '#title' => $this->t('Logo opacity (%)'),
      '#default_value' => $settings->get('preloader.logo.opacity') ?? 100,
      '#min' => 0,
      '#max' => 100,
      '#step' => 1,
      '#field_suffix' => '%',
      '#states' => [
        'visible' => [
          ':input#edit-preloader-style' => [
            ['value' => 'logo'],
            ['value' => 'logo_text'],
          ],
        ],
      ],
    ];

    $preloader_app = &$form["solo_settings"]["settings_global_misc"]["preloader"]["appearance"];
    // Order: text color, background color, background opacity (opacity is merged with bg as rgba in CSS).
    $preloader_app["preloader__colors__text"] = $this->elements->colorSwatch($settings, 'preloader.colors.text', $this->t('Text'));
    $preloader_app["preloader__colors__text"]['#attributes']['class'] = array_merge(
      $preloader_app["preloader__colors__text"]['#attributes']['class'] ?? [],
      ['solo-preloader-color-field']
    );
    $preloader_app["preloader__colors__background"] = $this->elements->colorSwatch($settings, 'preloader.colors.background', $this->t('Background'));
    $preloader_app["preloader__colors__background"]['#attributes']['class'] = array_merge(
      $preloader_app["preloader__colors__background"]['#attributes']['class'] ?? [],
      ['solo-preloader-color-field']
    );
    $preloader_app["preloader__bg_opacity"] = [
      "#parents" => ['preloader', 'bg_opacity'],
      '#type' => 'number',
      '#title' => $this->t('Background opacity'),
      '#min' => 0,
      '#max' => 100,
      '#default_value' => $settings->get('preloader.bg_opacity') ?? 100,
      '#description' => $this->t('0–100. Combined with background color as rgba.'),
      '#id' => 'edit-preloader-bg-opacity',
      '#states' => ['visible' => [':input#edit-preloader-enabled' => ['checked' => TRUE]]],
    ];

    $form["solo_settings"]["settings_global_misc"]["preloader"]["help_performance"] = [
      "#markup" => $this->t("<details class='solo-help'><summary>Preloader performance</summary><div class='solo-help__body'><p><strong>For best performance</strong>, keep the preloader light: use the <em>Spinner</em> style and leave text font and animation at default (none).</p><p>Custom Google fonts and gradient text animation add extra requests and can delay the preloader from appearing. You can still use them if you prefer the look; they will apply after their resources load.</p></div></details>"),
    ];
  }

  /**
   * Adds the Back to top tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildBackToTop(array &$form, BoundThemeSettings $settings): void {
    // Back to top: Enable, Visibility, Position, Style.
    $form["solo_settings"]["settings_global_misc"]["back_to_top"] = [
      '#type' => 'details',
      '#title' => $this->t('Back to top'),
      '#group' => 'global_misc_tabs',
      '#weight' => 99,
      '#description' => $this->t('Fixed button that scrolls to top when clicked. Default: off.'),
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["back_to_top__enabled"] = [
      "#parents" => ['back_to_top', 'enabled'],
      '#type' => 'checkbox',
      '#title' => $this->t('Enable back to top'),
      '#default_value' => $settings->get('back_to_top.enabled') ?? FALSE,
      '#description' => $this->t('Show the button after the user scrolls down.'),
      '#id' => 'edit-back-to-top-enabled',
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["visibility"] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Visibility'),
      '#states' => ['visible' => [':input#edit-back-to-top-enabled' => ['checked' => TRUE]]],
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["visibility"]["back_to_top__disable_admin_routes"] = [
      "#parents" => ['back_to_top', 'disable_admin_routes'],
      '#type' => 'checkbox',
      '#title' => $this->t('Disable on admin routes'),
      '#default_value' => $settings->get('back_to_top.disable_admin_routes') ?? TRUE,
      '#description' => $this->t('Recommended. Editors should not see the button in the admin UI.'),
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["visibility"]["back_to_top__disable_authenticated"] = [
      "#parents" => ['back_to_top', 'disable_authenticated'],
      '#type' => 'checkbox',
      '#title' => $this->t('Disable for authenticated users'),
      '#default_value' => $settings->get('back_to_top.disable_authenticated') ?? FALSE,
      '#description' => $this->t('Optional. Some site owners prefer a cleaner UX for editors.'),
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["visibility"]["back_to_top__hide_small_screens"] = [
      "#parents" => ['back_to_top', 'hide_small_screens'],
      '#type' => 'checkbox',
      '#title' => $this->t('Hide on small screens (&lt;768px)'),
      '#default_value' => $settings->get('back_to_top.hide_small_screens') ?? FALSE,
      '#description' => $this->t('Optional. Mobile users often do not need it.'),
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["visibility"]["back_to_top__scroll_threshold"] = [
      "#parents" => ['back_to_top', 'scroll_threshold'],
      '#type' => 'number',
      '#title' => $this->t('Show after scroll distance (px)'),
      '#default_value' => $settings->get('back_to_top.scroll_threshold') ?? 400,
      '#min' => 200,
      '#max' => 800,
      '#step' => 50,
      '#description' => $this->t('Button appears after the user scrolls this many pixels down (200–800).'),
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["position"] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Position'),
      '#states' => ['visible' => [':input#edit-back-to-top-enabled' => ['checked' => TRUE]]],
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["position"]["back_to_top__position"] = [
      "#parents" => ['back_to_top', 'position'],
      '#type' => 'select',
      '#title' => $this->t('Placement'),
      '#options' => [
        'bottom-right' => $this->t('Bottom right'),
        'bottom-left' => $this->t('Bottom left'),
      ],
      '#default_value' => $settings->get('back_to_top.position') ?? 'bottom-right',
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["style"] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Style'),
      '#states' => ['visible' => [':input#edit-back-to-top-enabled' => ['checked' => TRUE]]],
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["style"]["back_to_top__style"] = [
      "#parents" => ['back_to_top', 'style'],
      '#type' => 'select',
      '#title' => $this->t('Button style'),
      '#options' => [
        'solid' => $this->t('Solid circle'),
        'outline' => $this->t('Minimal outline'),
      ],
      '#default_value' => $settings->get('back_to_top.style') ?? 'solid',
    ];

    $form["solo_settings"]["settings_global_misc"]["back_to_top"]["style"]["back_to_top__icon"] = [
      "#parents" => ['back_to_top', 'icon'],
      '#type' => 'select',
      '#title' => $this->t('Icon'),
      '#options' => [
        'arrow-up' => $this->t('Arrow up'),
        'chevron-up' => $this->t('Chevron up'),
        'arrow-minimal' => $this->t('Arrow (minimal)'),
      ],
      '#default_value' => $settings->get('back_to_top.icon') ?? 'arrow-up',
    ];

    $back_to_top_style = &$form["solo_settings"]["settings_global_misc"]["back_to_top"]["style"];
    $back_to_top_style["back_to_top__colors__background"] = $this->elements->colorSwatch($settings, 'back_to_top.colors.background', $this->t('Background (optional)'));
    $back_to_top_style["back_to_top__colors__text"] = $this->elements->colorSwatch($settings, 'back_to_top.colors.text', $this->t('Icon color'));
  }

  /**
   * Adds the Menu template assignment tab.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   */
  private function buildMenuTemplates(array &$form, BoundThemeSettings $settings): void {
    // One row per menu; the row's own value posts its menu id.
    $saved_by_menu = [];
    foreach ($settings->array('menus.template_assignments') as $row) {
      if (!empty($row['menu_id']) && !empty($row['template'])) {
        $saved_by_menu[$row['menu_id']] = $row['template'];
      }
    }

    $form["solo_settings"]["settings_global_misc"]["menu_template_assignment"] = [
      "#type" => "details",
      "#title" => $this->t("Menu template assignment"),
      "#group" => "global_misc_tabs",
      "#weight" => 100,
      "#id" => "edit-menu-template-assignment",
      "#description" => $this->t("Assign which Twig template is used when a menu is rendered. Every menu is listed; choose a template per menu or leave as default. Save configuration to apply."),
    ];

    $template_select_options = $this->options->menuTemplates($settings->themeName());

    $form["solo_settings"]["settings_global_misc"]["menu_template_assignment"]["menus__template_assignments"] = [
      "#parents" => ['menus', 'template_assignments'],
      '#type' => 'table',
      '#header' => [$this->t('Menu name'), $this->t('Template name'), ''],
      '#empty' => $this->t('No menus found.'),
      '#tree' => TRUE,
      '#prefix' => '<div class="solo-menu-template-assignment-table-wrapper">',
      '#suffix' => '</div>',
    ];

    foreach ($this->options->menus() as $menu_id => $menu_label) {
      $form["solo_settings"]["settings_global_misc"]["menu_template_assignment"]["menus__template_assignments"][$menu_id]['menu_name'] = [
        '#type' => 'item',
        '#input' => FALSE,
        '#title' => $this->t('Menu'),
        '#title_display' => 'invisible',
        '#markup' => $menu_label,
      ];
      $form["solo_settings"]["settings_global_misc"]["menu_template_assignment"]["menus__template_assignments"][$menu_id]['template'] = [
        '#type' => 'select',
        '#title' => $this->t('Template'),
        '#title_display' => 'invisible',
        '#options' => $template_select_options,
        '#default_value' => $saved_by_menu[$menu_id] ?? '',
      ];
      $form["solo_settings"]["settings_global_misc"]["menu_template_assignment"]["menus__template_assignments"][$menu_id]['menu_id'] = [
        '#type' => 'value',
        '#value' => $menu_id,
      ];
    }
  }

}
