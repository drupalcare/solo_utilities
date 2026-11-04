<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings\Section;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsElementFactory;
use Drupal\solo_utilities\Form\ThemeSettings\SettingsFormContext;

/**
 * The Primary Menu section.
 *
 * Labels, descriptions and order are those of Solo 1.x.
 *
 * @internal
 */
final class PrimaryMenuSection implements SettingsSectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsElementFactory $elements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(array &$form, SettingsFormContext $context): void {
    $settings = $context->settings;
    // Primary Menu region.
    if ($context->hasMixRegion("primary_menu")) {

      $region = "primary_menu";
      $label = $context->mixRegions[$region];

      $form["solo_settings"]["settings_{$region}"] = [
        "#type" => "details",
        "#title" => $label,
        "#description" => $this->t("Configure the primary menu behavior, styling, and layout options."),
      ];

      // Create vertical tabs container.
      $form["solo_settings"]["settings_{$region}"]["primary_menu_tabs"] = [
        "#type" => "vertical_tabs",
        "#default_tab" => "edit-menu-layout-alignment",
      ];

      // Menu Layout & Alignment Tab.
      $form["solo_settings"]["settings_{$region}"]["menu_layout_alignment"] = [
        "#type" => "details",
        "#title" => $this->t("Menu Layout & Alignment"),
        "#group" => "primary_menu_tabs",
        "#weight" => -10,
        "#id" => "edit-menu-layout-alignment",
      ];

      // An empty alignment falls back on the justify option. A stored "center" flag
      // is folded into the alignment by the settings migration.
      $menu_alignment = $settings->string('menus.primary.alignment');
      if ($menu_alignment === "") {
        $menu_alignment = $settings->bool('menus.primary.justify_content') ? "justify" : "none";
      }

      // Define the select field with full descriptions.
      $form["solo_settings"]["settings_{$region}"]["menu_layout_alignment"]["menus__primary__alignment"] = [
        "#parents" => ['menus', 'primary', 'alignment'],
        "#type" => "select",
        "#title" => $this->t("Main menu block alignment (large screens only)"),
        "#description" => $this->t("Aligns the entire main menu block within its parent container on large screens, not its children. Left by default."),
        "#options" => [
          "none" => $this->t("Left aligned (default) - Positions menu block to the left"),
          "center" => $this->t("Center aligned - Centers menu block horizontally"),
          "justify" => $this->t("Justify content - Stretches menu block to fill container"),
          "right" => $this->t("Right aligned - Positions menu block to the right"),
        ],
        "#default_value" => $menu_alignment,
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_layout_alignment"]["menus__primary__branding"] = [
        "#parents" => ['menus', 'primary', 'branding'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to display a clickable logo next to the Main Menu."),
        "#description" => $this->t("To also display the site name, add the class show-sitename to the field (CSS Classes Only)."),
        "#default_value" => $settings->get('menus.primary.branding'),
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_layout_alignment"]["classes"] = $this->elements->cssClasses($settings, $region);

      $form["solo_settings"]["settings_{$region}"]["menu_layout_alignment"]["notice_primary_menu_depth_info"] = [
        "#markup" => $this->t("<details class='solo-help'><summary>Menu depth support</summary><div class='solo-help__body'><p>The Solo theme supports <strong>up to 4 levels of nested menu items</strong> in the primary menu block. Menus behave consistently across all levels and support both hover and click interactions on large screens.</p><h3>Example structure</h3><ul class='solo-tree'><li>Fruit<ul><li>Apple<ul><li>Red Apple<ul><li>Gala</li><li>Delish</li><li>Green</li></ul></li></ul></li></ul></li></ul></div></details>"),
      ];

      // Menu Behavior Tab.
      $form["solo_settings"]["settings_{$region}"]["menu_behavior"] = [
        "#type" => "details",
        "#title" => $this->t("Menu Behavior"),
        "#group" => "primary_menu_tabs",
        "#weight" => -8,
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_behavior"]["menus__primary__open_on_hover"] = [
        "#parents" => ['menus', 'primary', 'open_on_hover'],
        "#type" => "checkbox",
        "#title" => $this->t("Open dropdowns on hover (large screens only)"),
        "#description" => $this->t("Shows the main menu dropdowns when hovering over them with the mouse, instead of clicking."),
        "#default_value" => $settings->get('menus.primary.open_on_hover'),
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_behavior"]["menus__primary__expand_left"] = [
        "#parents" => ['menus', 'primary', 'expand_left'],
        "#type" => "checkbox",
        "#title" => $this->t("Open dropdowns on the opposite side (large screens only)"),
        "#description" => $this->t("LTR languages: the dropdown opens on the left instead of the default right. RTL languages: on the right instead of the default left."),
        "#default_value" => $settings->get('menus.primary.expand_left'),
      ];

      // Menu Styling Tab.
      $form["solo_settings"]["settings_{$region}"]["menu_styling"] = [
        "#type" => "details",
        "#title" => $this->t("Menu Styling"),
        "#group" => "primary_menu_tabs",
        "#weight" => -6,
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_styling"]["menus__primary__border"] = [
        "#parents" => ['menus', 'primary', 'border'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box if you prefer to add border for each menu item"),
        "#default_value" => $settings->get('menus.primary.border'),
      ];

      $form["solo_settings"]["settings_{$region}"]["menu_styling"]["menus__primary__arrow_on_hover"] = [
        "#parents" => ['menus', 'primary', 'arrow_on_hover'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to hide dropdown arrows on hover menus. (Large screen only)"),
        "#description" => $this->t("Removes the arrow icons from parent menu items when dropdowns expand on hover, reducing visual clutter and saving space, especially for menus with many items."),
        "#default_value" => $settings->get('menus.primary.arrow_on_hover'),
        "#states" => [
          "visible" => [
            ":input[name='menus[primary][open_on_hover]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Mega Menu Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["mega_menu_settings"] = [
        "#type" => "details",
        "#title" => $this->t("Mega Menu Settings"),
        "#group" => "primary_menu_tabs",
        "#weight" => -4,
      ];

      $form["solo_settings"]["settings_{$region}"]["mega_menu_settings"]["menus__primary__megamenu__enabled"] = [
        "#parents" => ['menus', 'primary', 'megamenu', 'enabled'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to transform the main menu dropdowns into a Mega Menu."),
        "#description" => $this->t("Requires a three-level menu. The second level defines the columns of the mega menu. With 'Tick this box to display the second level as headers' selected, the second level shows as a header for the third-level items."),
        "#default_value" => $settings->get('menus.primary.megamenu.enabled'),
      ];

      $form["solo_settings"]["settings_{$region}"]["mega_menu_settings"]["menus__primary__megamenu__layout"] = $this->elements->megamenuLayout($settings);

      $form["solo_settings"]["settings_{$region}"]["mega_menu_settings"]["menus__primary__megamenu__submenu_header"] = [
        "#parents" => ['menus', 'primary', 'megamenu', 'submenu_header'],
        "#type" => "checkbox",
        "#title" => $this->t("Tick this box to display the second level as headers."),
        "#default_value" => $settings->get('menus.primary.megamenu.submenu_header'),
        "#states" => [
          "visible" => [
            ":input[name='menus[primary][megamenu][enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Accessibility Tab.
      $form["solo_settings"]["settings_{$region}"]["accessibility"] = [
        "#type" => "details",
        "#title" => $this->t("Accessibility"),
        "#group" => "primary_menu_tabs",
        "#weight" => -2,
      ];

      $form["solo_settings"]["settings_{$region}"]["accessibility"]["skip_links__navigation"] = [
        "#parents" => ['skip_links', 'navigation'],
        "#type" => "checkbox",
        "#title" => $this->t("Enable skip to main navigation link"),
        "#default_value" => $settings->get('skip_links.navigation'),
      ];

      $form["solo_settings"]["settings_{$region}"]["developer_tools"] = [
        "#type" => "details",
        "#title" => $this->t("Developer Tools"),
        "#group" => "primary_menu_tabs",
        "#weight" => 100,
      ];

      $form["solo_settings"]["settings_{$region}"]["developer_tools"]["menus__debug__enabled"] = [
        "#parents" => ['menus', 'debug', 'enabled'],
        "#type" => "checkbox",
        "#title" => $this->t("Enable Solo Menu Debug Mode"),
        "#description" => $this->t("<strong>For developers only.</strong> Enables debugging tools: state change tracking and conflict detection, keyboard navigation debugging, ARIA compliance checking, performance monitoring, visual debug indicators (green badge in corner) and console debugging commands. See <em>Debug console reference</em> below the fields.<div class='solo-note solo-note--warning'><strong>Warning:</strong> This adds ~35KB of extra JavaScript. <strong>Always disable before production deployment.</strong></div>"),
        "#default_value" => $settings->get('menus.debug.enabled'),
      ];

      // NEW: Verbosity control.
      $form["solo_settings"]["settings_{$region}"]["developer_tools"]["menus__debug__verbosity"] = [
        "#parents" => ['menus', 'debug', 'verbosity'],
        "#type" => "select",
        "#title" => $this->t("Debug Verbosity Level"),
        "#description" => $this->t("Controls how much information is logged to the console. All data is still collected regardless of verbosity. <dl class='solo-deflist'><dt>Minimal</dt><dd>Only errors and conflicts (recommended for most debugging).</dd><dt>Normal</dt><dd>Errors, conflicts, and major events like menu open/close.</dd><dt>Verbose</dt><dd>Everything including system events and state changes.</dd></dl>"),
        "#options" => [
          "minimal" => $this->t("Minimal - Errors and conflicts only"),
          "normal" => $this->t("Normal - Major events (recommended)"),
          "verbose" => $this->t("Verbose - Everything"),
        ],
        "#default_value" => $settings->get('menus.debug.verbosity') ?? "normal",
        "#states" => [
          "visible" => [
            ":input[name='menus[debug][enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      // Helpful info box.
      $form["solo_settings"]["settings_{$region}"]["developer_tools"]["notice_debug_info"] = [
        "#input" => FALSE,
        "#type" => "item",
        "#description" => $this->t("<div class='solo-note solo-note--tip'><strong>Debug mode best practices</strong><ul><li>Start with 'Normal' verbosity.</li><li>Use 'Minimal' for conflict detection only.</li><li>Use 'Verbose' only when diagnosing specific issues.</li><li>Always run <code>soloAriaCleanup.report()</code> before <code>.run()</code>.</li><li>Check <code>soloValidateState()</code> periodically during debugging.</li></ul></div>"),
        "#states" => [
          "visible" => [
            ":input[name='menus[debug][enabled]']" => ["checked" => TRUE],
          ],
        ],
      ];

      $form["solo_settings"]["settings_{$region}"]["developer_tools"]["help_debug_commands"] = [
        "#markup" => $this->t("<details class='solo-help'><summary>Debug console reference</summary><div class='solo-help__body'><h3>Quick start</h3><ol><li>Check the debug mode box and set verbosity level.</li><li>Save and clear cache.</li><li>Visit your site and open browser console (F12).</li><li>Click dropdown menus several times.</li><li>Run <code>soloReport.show()</code> to see unified report.</li><li>For detailed analysis: <code>soloDebug.conflicts()</code>.</li></ol><h3>Available console commands</h3><table class='solo-reference'><thead><tr><th scope='col'>Command</th><th scope='col'>What it does</th></tr></thead><tbody><tr><th scope='rowgroup' colspan='2'>Primary commands (most users)</th></tr><tr><th scope='row'><code>soloReport.show()</code></th><td>Display unified debug report</td></tr><tr><th scope='row'><code>soloReport.copy()</code></th><td>Copy report to clipboard</td></tr></tbody><tbody><tr><th scope='rowgroup' colspan='2'>Technical debugging (developers)</th></tr><tr><th scope='row'><code>soloMenuReport()</code></th><td>State manager debug report</td></tr><tr><th scope='row'><code>soloDebug.logs()</code></th><td>View all state changes</td></tr><tr><th scope='row'><code>soloDebug.conflicts()</code></th><td>View detected conflicts</td></tr><tr><th scope='row'><code>soloDebug.components()</code></th><td>Changes by component</td></tr><tr><th scope='row'><code>soloDebug.animations()</code></th><td>Animation calls</td></tr><tr><th scope='row'><code>soloDebug.clear()</code></th><td>Clear debug logs</td></tr></tbody><tbody><tr><th scope='rowgroup' colspan='2'>Keyboard navigation</th></tr><tr><th scope='row'><code>soloKeyboardDebug.checkMenuState()</code></th><td>Inspect menu state</td></tr><tr><th scope='row'><code>soloKeyboardDebug.testKeyboardNav()</code></th><td>Test keyboard navigation</td></tr></tbody><tbody><tr><th scope='rowgroup' colspan='2'>ARIA compliance (manual only)</th></tr><tr><th scope='row'><code>soloAriaCleanup.report()</code></th><td>Accessibility report</td></tr><tr><th scope='row'><code>soloAriaCleanup.validate()</code></th><td>Validate structure</td></tr><tr><th scope='row'><code>soloAriaCleanup.run()</code></th><td>Fix issues (run AFTER report)</td></tr></tbody><tbody><tr><th scope='rowgroup' colspan='2'>State validation (on demand)</th></tr><tr><th scope='row'><code>soloValidateState()</code></th><td>Check state health</td></tr></tbody></table></div></details>"),
      ];

      // Color Settings Tab.
      $form["solo_settings"]["settings_{$region}"]["color_settings_tab"] = [
        "#type" => "details",
        "#title" => $this->t("Color Settings"),
        "#group" => "primary_menu_tabs",
        "#weight" => 0,
        '#attributes' => [
          'class' => ['solo-color-panel'],
          'data-solo-panel' => 'colors',
        ],
      ];

      $this->elements->addRegionColors($form["solo_settings"]["settings_{$region}"]["color_settings_tab"], $settings, $region);

    }
  }

}
