<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Form\SettingsOptions;
use Drupal\solo_utilities\Layout\LayoutResolver;
use Drupal\solo_utilities\Layout\RegionInventory;
use Drupal\solo_utilities\Settings\SettingsCatalog;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * Builds the theme settings form for the edited theme.
 *
 * The theme comes from the form's own arguments, never from the active theme,
 * which is the admin theme here. Nothing is written while the form is viewed.
 *
 * @internal
 */
final class SettingsFormBuilder {

  use StringTranslationTrait;

  /**
   * The form key of the element that holds every section, in source order.
   */
  private const SECTIONS_KEY = 'solo_sections';

  /**
   * Every settings section in page order, with its navigation group.
   *
   * The one home of the section order. Details use #group, never a moved array,
   * because core reads $form['logo']['settings']['logo_upload'] by path.
   */
  private const SECTION_HOME = [
    'settings_global_misc' => 'site',
    'settings_predefined_theme' => 'site',
    'settings_libraries_fonts' => 'site',
    'settings_css_classes' => 'site',
    'settings_page_wrapper' => 'site',
    'logo' => 'site',
    'favicon' => 'site',
    'theme_settings' => 'site',
    'settings_highlighted' => 'top',
    'settings_popup_login_block' => 'top',
    'settings_fixed_search_block' => 'top',
    'settings_header' => 'top',
    'settings_primary_sidebar_menu' => 'top',
    'settings_primary_menu' => 'top',
    'settings_welcome_text' => 'top',
    'settings_top_container' => 'content',
    'settings_system_messages' => 'content',
    'settings_page_title' => 'content',
    'settings_breadcrumb' => 'content',
    'settings_main_container' => 'content',
    'settings_bottom_container' => 'content',
    'settings_search' => 'content',
    'settings_footer_container' => 'footer',
    'settings_footer_menu' => 'footer',
    'settings_social_icons' => 'footer',
    'settings_credit_copyright' => 'footer',
  ];

  /**
   * The icon of each navigation part: a file in Solo's images/icons/settings.
   *
   * Keys are the keys of SECTION_HOME, the nav group keys, and the four parts
   * the template names itself. Files are Material Symbols; see their SOURCE.md.
   */
  private const NAV_ICON = [
    'site' => 'language',
    'top' => 'vertical_align_top',
    'content' => 'article',
    'footer' => 'vertical_align_bottom',
    'other' => 'extension',
    'sections' => 'toc',
    'block_layout' => 'dashboard',
    'close' => 'close',
    'settings_global_misc' => 'tune',
    'settings_predefined_theme' => 'palette',
    'settings_libraries_fonts' => 'font_download',
    'settings_css_classes' => 'list',
    'settings_page_wrapper' => 'crop_free',
    'logo' => 'branding_watermark',
    'favicon' => 'tab',
    'theme_settings' => 'toggle_on',
    'settings_highlighted' => 'campaign',
    'settings_popup_login_block' => 'login',
    'settings_fixed_search_block' => 'search',
    'settings_header' => 'toolbar',
    'settings_primary_sidebar_menu' => 'menu_open',
    'settings_primary_menu' => 'menu',
    'settings_welcome_text' => 'waving_hand',
    'settings_top_container' => 'view_column',
    'settings_system_messages' => 'feedback',
    'settings_page_title' => 'title',
    'settings_breadcrumb' => 'route',
    'settings_main_container' => 'view_sidebar',
    'settings_bottom_container' => 'view_week',
    'settings_search' => 'manage_search',
    'settings_footer_container' => 'call_to_action',
    'settings_footer_menu' => 'list',
    'settings_social_icons' => 'share',
    'settings_credit_copyright' => 'copyright',
  ];

  /**
   * The inline icons read so far, by file name, so each is read once a request.
   *
   * @var array<string, \Drupal\Component\Render\MarkupInterface|string>
   */
  private array $iconMarkup = [];

  /**
   * The region group each container section covers.
   */
  private const CONTAINER_GROUP = [
    'settings_top_container' => 'top',
    'settings_main_container' => 'main',
    'settings_bottom_container' => 'bottom',
    'settings_footer_container' => 'footer',
  ];

  /**
   * Constructs the builder.
   *
   * @param \Drupal\solo_utilities\Theme\SoloThemeDetection $themeDetection
   *   Names the edited theme.
   * @param \Drupal\solo_utilities\Settings\SettingsReader $settingsReader
   *   Reads the edited theme's settings.
   * @param \Drupal\solo_utilities\Settings\SettingsCatalog $catalog
   *   Names the region groups.
   * @param \Drupal\solo_utilities\Layout\RegionInventory $regionInventory
   *   Lists the occupied regions of the edited theme.
   * @param \Drupal\solo_utilities\Form\SettingsOptions $options
   *   Holds the region labels.
   * @param \Drupal\solo_utilities\Form\ThemeSettings\SettingsFormValidator $validator
   *   The validate handler.
   * @param \Drupal\solo_utilities\Form\ThemeSettings\SettingsFormSubmitter $submitter
   *   The submit handler.
   * @param iterable<\Drupal\solo_utilities\Form\ThemeSettings\Section\SettingsSectionInterface> $sections
   *   The sections, highest tag priority first.
   * @param \Drupal\Core\Extension\ExtensionPathResolver $pathResolver
   *   Finds Solo's folder, where the navigation's icons are.
   */
  public function __construct(
    private readonly SoloThemeDetection $themeDetection,
    private readonly SettingsReader $settingsReader,
    private readonly SettingsCatalog $catalog,
    private readonly RegionInventory $regionInventory,
    private readonly SettingsOptions $options,
    private readonly SettingsFormValidator $validator,
    private readonly SettingsFormSubmitter $submitter,
    private readonly iterable $sections,
    private readonly ExtensionPathResolver $pathResolver,
  ) {}

  /**
   * Adds every section and both handlers to the form.
   *
   * @param array<string, mixed> $form
   *   The system_theme_settings form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Its state.
   */
  public function build(array &$form, FormStateInterface $form_state): void {
    $theme = $this->themeDetection->editedThemeName($form_state);
    $settings = $this->settingsReader->forTheme($theme);

    // Handlers are appended, so the submit runs after core's save. They are
    // serialisable objects because this form has uploads and can be cached.
    $form['#validate'][] = [$this->validator, 'validate'];
    $form['#submit'][] = [$this->submitter, 'submit'];

    $form['#attached']['library'][] = 'solo/solo-form-theme-settings';

    [$mix, $groups] = $this->occupiedRegions($theme);

    $context = new SettingsFormContext($theme, $settings, $mix, $groups);
    foreach ($this->sections as $section) {
      $section->build($form, $context);
    }
    $this->arrangeSections($form, $groups);

    $posted = [];
    $this->collectPosted($form, $posted);
    $this->offWhenNotOnForm($form, $posted);
    $this->keepUnpostedChildren($form, $posted, $theme);
  }

  /**
   * Sorts the occupied regions of a theme into the 1.x form's groups.
   *
   * @return array{0: array<string, string|\Stringable>, 1: array<string, array<string, string|\Stringable>>}
   *   The regions outside the groups, then the regions of each group.
   */
  private function occupiedRegions(string $theme): array {
    $grouped = [];
    foreach (LayoutResolver::GROUP_REGIONS as $group => $regions) {
      $grouped += array_fill_keys($regions, $group);
    }
    $labels = $this->options->groupLabels();

    $mix = [];
    $groups = array_fill_keys(array_keys(LayoutResolver::GROUP_REGIONS), []);
    foreach ($this->regionInventory->regionsWithBlocks($theme) as $region) {
      if (isset($grouped[$region])) {
        $group = $grouped[$region];
        $groups[$group]['container'] = $labels[$group]['container'];
        $groups[$group][$region] = $labels[$group]['regions'][$region];
      }
      else {
        $mix[$region] = ucwords(str_replace('_', ' ', $region));
      }
    }
    return [$mix, $groups];
  }

  /**
   * The id of a section's details element, the value core would generate.
   *
   * @param string $key
   *   The section's form key.
   */
  public static function sectionId(string $key): string {
    return 'edit-' . str_replace('_', '-', $key);
  }

  /**
   * Places each section in source order and fills the navigation from them.
   *
   * With no script every section stays open, one after another, and the
   * navigation is a list of jump links. The script then shows one at a time.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param array<string, array<string, string|\Stringable>> $groups
   *   The occupied regions of each group.
   */
  private function arrangeSections(array &$form, array $groups): void {
    $titles = $this->navGroups();
    $rows = array_fill_keys(array_keys($titles), []);
    $weight = 0;
    foreach (self::SECTION_HOME as $key => $group) {
      $weight++;
      if (isset($form['solo_settings'][$key])) {
        $section = &$form['solo_settings'][$key];
      }
      elseif (isset($form[$key]) && ($form[$key]['#access'] ?? TRUE)) {
        $section = &$form[$key];
      }
      else {
        $label = $this->regionLabel($key);
        if ($label !== NULL) {
          $rows[$group][] = [
            'icon' => $this->navIcon($key),
            'label' => $label,
            'target' => NULL,
            'detail' => NULL,
            'regions' => [],
          ];
        }
        continue;
      }
      $section['#group'] = self::SECTIONS_KEY;
      $section['#weight'] = $weight;
      $section['#open'] = TRUE;
      $section['#id'] = self::sectionId($key);
      $section['#attributes']['data-solo-section'] = $key;
      $hint = $this->sectionHint($key);
      if (empty($section['#description']) && $hint !== '') {
        $section['#description'] = $hint;
      }
      $rows[$group][] = [
        'icon' => $this->navIcon($key),
        'label' => (string) ($section['#title'] ?? ''),
        'target' => self::sectionId($key),
        'detail' => $this->navDetail($key),
        'regions' => $this->containerRegions($key, $groups),
      ];
      unset($section);
    }

    // The navigation sorts first, then the sections, then anything another
    // module adds, then core's actions at their weight of 100.
    $form['solo_settings']['#weight'] = -100;
    $form[self::SECTIONS_KEY] = [
      '#type' => 'container',
      '#weight' => -90,
      '#attributes' => ['class' => ['solo-sections']],
    ];

    $nav = [];
    foreach ($titles as $group => $title) {
      if ($rows[$group]) {
        $nav[] = ['title' => $title, 'icon' => $this->navIcon($group), 'rows' => $rows[$group]];
      }
    }
    $form['solo_settings']['settings_blueprint']['#context']['groups'] = $nav;
    foreach (['sections', 'block_layout', 'other', 'close'] as $part) {
      $form['solo_settings']['settings_blueprint']['#context']['icons'][$part] = $this->navIcon($part);
    }
  }

  /**
   * The inline icon of a navigation part, hidden from assistive technology.
   *
   * The name comes only from NAV_ICON and the file only from Solo's own folder,
   * never from input or settings, which is why it may be printed unescaped. The
   * color comes from the stylesheet: fill follows the text around the icon.
   *
   * @param string $key
   *   A key of NAV_ICON.
   *
   * @return \Drupal\Component\Render\MarkupInterface|string
   *   The SVG, or an empty string when the file is missing or is not an SVG.
   */
  private function navIcon(string $key): MarkupInterface|string {
    $name = self::NAV_ICON[$key] ?? NULL;
    if ($name === NULL) {
      return '';
    }
    if (!isset($this->iconMarkup[$name])) {
      $file = $this->pathResolver->getPath('theme', 'solo') . '/images/icons/settings/' . $name . '.svg';
      $svg = is_file($file) ? (string) file_get_contents($file) : '';
      $this->iconMarkup[$name] = str_starts_with($svg, '<svg ')
        ? Markup::create('<svg class="solo-icon" aria-hidden="true" focusable="false" ' . substr($svg, 5))
        : '';
    }
    return $this->iconMarkup[$name];
  }

  /**
   * The navigation groups, in order.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The heading of each group, keyed as in SECTION_HOME.
   */
  private function navGroups(): array {
    return [
      'site' => $this->t('Site-wide'),
      'top' => $this->t('Top of the page'),
      'content' => $this->t('Page content'),
      'footer' => $this->t('Footer'),
    ];
  }

  /**
   * The label of a section that is absent because its region holds no block.
   *
   * @param string $key
   *   The section's form key.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The label, or NULL when an absent section is not a region without blocks.
   */
  private function regionLabel(string $key): ?\Stringable {
    return match ($key) {
      'settings_highlighted' => $this->t('Highlighted'),
      'settings_popup_login_block' => $this->t('Popup Login Block'),
      'settings_fixed_search_block' => $this->t('Fixed Search Block'),
      'settings_header' => $this->t('Header'),
      'settings_primary_sidebar_menu' => $this->t('Primary Sidebar Menu'),
      'settings_primary_menu' => $this->t('Primary Menu'),
      'settings_welcome_text' => $this->t('Welcome Text'),
      'settings_top_container' => $this->t('Top Containers'),
      'settings_system_messages' => $this->t('System Messages'),
      'settings_page_title' => $this->t('Page Title'),
      'settings_breadcrumb' => $this->t('Breadcrumb'),
      'settings_main_container' => $this->t('Main Containers'),
      'settings_bottom_container' => $this->t('Bottom Containers'),
      'settings_footer_container' => $this->t('Footer Containers'),
      'settings_footer_menu' => $this->t('Footer Menu'),
      default => NULL,
    };
  }

  /**
   * The small line under a navigation item whose name does not say enough.
   *
   * @param string $key
   *   The section's form key.
   */
  private function navDetail(string $key): string|\Stringable|null {
    return match ($key) {
      'settings_global_misc', 'settings_page_wrapper' => $this->sectionHint($key),
      'settings_popup_login_block', 'settings_fixed_search_block', 'settings_primary_sidebar_menu' => $this->t('Floats over the page'),
      default => NULL,
    };
  }

  /**
   * The regions a container section covers, with those holding no block marked.
   *
   * @param string $key
   *   The section's form key.
   * @param array<string, array<string, string|\Stringable>> $groups
   *   The occupied regions of each group.
   *
   * @return list<array{label: string|\Stringable, empty: bool}>
   *   One entry per region; none when the section is not a container.
   */
  private function containerRegions(string $key, array $groups): array {
    if (!isset(self::CONTAINER_GROUP[$key])) {
      return [];
    }
    $group = self::CONTAINER_GROUP[$key];
    $regions = [];
    foreach ($this->options->groupLabels()[$group]['regions'] as $region => $label) {
      $regions[] = ['label' => $label, 'empty' => !isset($groups[$group][$region])];
    }
    return $regions;
  }

  /**
   * The description of a section that has none of its own.
   *
   * @param string $key
   *   The section's form key.
   */
  private function sectionHint(string $key): string|\Stringable {
    return match ($key) {
      'settings_global_misc' => $this->t('Layout, typography, comments, preloader, back to top'),
      'settings_predefined_theme' => $this->t('Palettes by category, applied across the site'),
      'settings_libraries_fonts' => $this->t('W3.CSS library, font libraries, site and heading fonts'),
      'settings_css_classes' => $this->t('W3.CSS classes to copy into a field'),
      'settings_page_wrapper' => $this->t('Classes, animation and color of the outer frame'),
      'settings_highlighted' => $this->t('Hero sections, special announcements, promotional banners'),
      'settings_popup_login_block' => $this->t('User authentication, member areas, subscription forms'),
      'settings_fixed_search_block' => $this->t('Always-accessible search, product search'),
      'settings_header' => $this->t('Logo placement, contact info, social media icons'),
      'settings_primary_sidebar_menu' => $this->t('Mobile-friendly navigation, off-canvas menus'),
      'settings_primary_menu' => $this->t('Main site navigation, mega menus, dropdown categories'),
      'settings_welcome_text' => $this->t('Hero content, value propositions, mission statements'),
      'settings_top_container' => $this->t('Key metrics, service highlights, calls to action'),
      'settings_system_messages' => $this->t('User feedback, form confirmations, error notifications'),
      'settings_page_title' => $this->t('Page identification and its typography'),
      'settings_breadcrumb' => $this->t('User orientation and site hierarchy'),
      'settings_main_container' => $this->t('Sidebars and your primary content'),
      'settings_bottom_container' => $this->t('Company info, quick links, support, news'),
      'settings_search' => $this->t('Search filters, display options, content types'),
      'settings_footer_container' => $this->t('Essential links, contact forms, newsletters'),
      'settings_footer_menu' => $this->t('Menu configuration, accessibility, color'),
      'settings_social_icons' => $this->t('General settings and platform links'),
      'settings_credit_copyright' => $this->t('Copyright line and credit link'),
      default => '',
    };
  }

  /**
   * Lists the nested stored paths the form's elements post to.
   *
   * @param array<string, mixed> $element
   *   A form element.
   * @param array<string, list<string>> $posted
   *   Collected #parents, keyed by dotted path.
   */
  private function collectPosted(array $element, array &$posted): void {
    foreach ($element as $key => $child) {
      if (!is_array($child) || str_starts_with((string) $key, '#')) {
        continue;
      }
      if (isset($child['#parents']) && count($child['#parents']) > 1) {
        $posted[implode('.', $child['#parents'])] = $child['#parents'];
      }
      $this->collectPosted($child, $posted);
    }
  }

  /**
   * Posts "off" for a group's per-type layout when its section is not shown.
   *
   * A group with no occupied region has no section; 1.x posted 0 for this
   * toggle in that case, and the submit handler then clears the overrides.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param array<string, list<string>> $posted
   *   Collected #parents, keyed by dotted path.
   */
  private function offWhenNotOnForm(array &$form, array &$posted): void {
    foreach ($this->catalog->regionGroups() as $group) {
      $path = 'region_groups.' . $group . '.per_content_type_enabled';
      if (!isset($posted[$path])) {
        $parents = explode('.', $path);
        $form['solo_settings']['stored_settings'][$path] = [
          '#type' => 'value',
          '#parents' => $parents,
          '#value' => 0,
        ];
        $posted[$path] = $parents;
      }
    }
  }

  /**
   * Posts every stored child no element posts, in each group the form saves.
   *
   * Core saves each top-level value whole and replaces what was stored, so the
   * children of a region whose section is not shown would otherwise be deleted.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param array<string, list<string>> $posted
   *   Collected #parents, keyed by dotted path.
   * @param string $theme
   *   The edited theme.
   */
  private function keepUnpostedChildren(array &$form, array $posted, string $theme): void {
    $settings = $this->settingsReader->forTheme($theme);
    $tops = array_unique(array_map(static fn(array $parents): string => $parents[0], array_values($posted)));
    foreach ($tops as $top) {
      $this->keepStored($form, [$top], $settings->get($top), $posted);
    }
  }

  /**
   * Adds a value element for each stored leaf below a path no element posts.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param list<string> $parents
   *   The path being walked.
   * @param mixed $stored
   *   The stored value at that path.
   * @param array<string, list<string>> $posted
   *   Collected #parents, keyed by dotted path.
   */
  private function keepStored(array &$form, array $parents, mixed $stored, array $posted): void {
    $path = implode('.', $parents);
    if (isset($posted[$path])) {
      return;
    }
    if (is_array($stored) && $stored !== [] && $this->leadsToPosted($path, $posted)) {
      foreach ($stored as $key => $value) {
        $this->keepStored($form, [...$parents, (string) $key], $value, $posted);
      }
      return;
    }
    if ($stored !== NULL) {
      $form['solo_settings']['stored_settings'][$path] = [
        '#type' => 'value',
        '#parents' => $parents,
        '#value' => $stored,
      ];
    }
  }

  /**
   * Whether an element posts somewhere below a path.
   *
   * @param string $path
   *   A dotted path.
   * @param array<string, list<string>> $posted
   *   Collected #parents, keyed by dotted path.
   */
  private function leadsToPosted(string $path, array $posted): bool {
    foreach (array_keys($posted) as $posted_path) {
      if (str_starts_with($posted_path, $path . '.')) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
