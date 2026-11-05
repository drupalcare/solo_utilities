<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Css;

use Drupal\solo_utilities\Security\CssValueValidator;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\solo_utilities\Settings\SettingsReader;

/**
 * Turns one theme's settings into CSS.
 *
 * Every value is validated here, at output, whatever the form accepted: config
 * can also arrive by import or by `drush cset`. A refused value emits nothing
 * and its field name is reported; it is never repaired and never logged.
 *
 * Building has no side effect, so a page can compare the result with the file
 * on disk without logging on every request.
 *
 * @internal
 */
final class ThemeCssBuilder {

  /**
   * The selector of each region: the class its element always carries.
   *
   * These replace 1.x's `style` attribute on the same element. Each class is
   * printed on that one element only in Solo's templates (`page.html.twig`,
   * `partials/`). The rules load after every theme stylesheet (GROUP in
   * ThemeLibraryRegistrar), so a selector as strong as the strongest scheme or
   * Solo rule on the same element wins; each form below is the lowest that
   * does, checked by solo/scripts/check-cascade.py (P1, P2), which reads this
   * constant. `.breadcrumb` is a generic class, so it is limited to the direct
   * child of the page wrapper. The page title matches the strongest scheme
   * rule on it (`.solo-page div.solo-page-title.solo-container--single`).
   */
  public const REGION_SELECTORS = [
    'page_wrapper' => '.solo-page',
    'popup_login_block' => '.solo-popup-login',
    'fixed_search_block' => '.solo-fixed-search',
    'header' => '.solo-page .solo-header',
    'primary_sidebar_menu' => '.solo-primary-sidebar-menu',
    'primary_menu' => '.solo-primary-menu',
    'welcome_text' => '.solo-welcome-text',
    'top_container' => '.solo-container--top',
    'top_first' => '.solo-page .solo-region-box__inner--top-first',
    'top_second' => '.solo-page .solo-region-box__inner--top-second',
    'top_third' => '.solo-page .solo-region-box__inner--top-third',
    'highlighted' => '.solo-highlighted',
    'system_messages' => '.solo-system-messages',
    'page_title' => '.solo-page div.solo-page-title.solo-container--single',
    'breadcrumb' => ':where(.solo-page) > .breadcrumb',
    'main_container' => '.solo-container--main',
    'sidebar_first' => '.solo-page .solo-region-box__inner--sidebar-first',
    'content' => '.solo-page .solo-region-box__inner--content',
    'sidebar_second' => '.solo-page .solo-region-box__inner--sidebar-second',
    'bottom_container' => '.solo-container--bottom',
    'bottom_first' => '.solo-page .solo-region-box__inner--bottom-first',
    'bottom_second' => '.solo-page .solo-region-box__inner--bottom-second',
    'bottom_third' => '.solo-page .solo-region-box__inner--bottom-third',
    'bottom_fourth' => '.solo-page .solo-region-box__inner--bottom-fourth',
    'footer_container' => '.solo-container--footer',
    'footer_first' => '.solo-page .solo-region-box__inner--footer-first',
    'footer_second' => '.solo-page .solo-region-box__inner--footer-second',
    'footer_third' => '.solo-page .solo-region-box__inner--footer-third',
    'footer_menu' => '.solo-footer-nav',
    'credit_copyright' => '.solo-copyright',
  ];

  /**
   * The custom property of each color under regions.<region>.colors.
   */
  public const COLOR_PROPERTIES = [
    'background' => '--r-bg',
    'text' => '--r-tx',
    'heading' => '--r-h1',
    'link' => '--r-lk',
    'link_hover' => '--r-lk-h',
    'border' => '--r-br',
    'form_field_background' => '--r-bg-fr',
    'menu_link_text' => '--r-tx-lk',
    'menu_link_text_hover' => '--r-tx-lk-h',
    'menu_link_background' => '--r-bg-lk',
    'menu_link_background_hover' => '--r-bg-lk-h',
    'button_text' => '--r-tx-bt',
    'button_text_hover' => '--r-tx-bt-h',
    'button_background' => '--r-bg-bt',
    'button_background_hover' => '--r-bg-bt-h',
  ];

  public function __construct(
    private readonly SettingsReader $settingsReader,
    private readonly CssValueValidator $validator,
  ) {}

  /**
   * Builds everything a theme's settings produce.
   *
   * @param string $theme
   *   The theme machine name.
   */
  public function build(string $theme): GeneratedAssets {
    $settings = $this->settingsReader->forTheme($theme);
    $refused = [];

    $rules = array_merge(
      $this->regionRules($settings, $refused),
      $this->backToTopRules($settings, $refused),
    );
    $preloader = $this->preloaderProperties($settings, $refused);

    return new GeneratedAssets(
      implode("\n", $rules),
      $this->typedText($settings, 'custom_code.css_file', $refused),
      $this->inlineCss($settings, $refused),
      $this->typedText($settings, 'custom_code.js_file', $refused),
      $preloader === [] ? '' : '#solo-preloader{' . implode(';', $preloader) . '}',
      $refused,
    );
  }

  /**
   * Removes comments and needless whitespace from typed CSS for a style tag.
   *
   * Quoted strings are matched first so their content is left alone.
   *
   * @param string $css
   *   The typed CSS.
   */
  public function minify(string $css): string {
    $double_quoted = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"';
    $single_quoted = "'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'";
    $comment = '/\*[^*]*\*+(?:[^/*][^*]*\*+)*/';

    // A charset rule is invalid inside a style tag.
    $result = preg_replace('/^@charset\s+[\'"](\S*?)\b[\'"];/i', '', $css);
    $result = $result === NULL ? NULL : preg_replace("<($double_quoted|$single_quoted)|$comment>Ss", '$1', $result);
    // Spaces that selectors or media conditions need are kept.
    $result = $result === NULL ? NULL : preg_replace('<
      (' . $double_quoted . '|' . $single_quoted . ')
      | \s*([@{};,])\s*
      | \s+([\)])
      | ([\(:])\s+
    >xSs', '$1$2$3$4', $result);

    // On a regex failure on a huge value, the unminified text is still correct.
    return trim($result ?? $css);
  }

  /**
   * Builds one rule per region that has a color set.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param list<string> $refused
   *   Field names refused so far.
   *
   * @return list<string>
   *   CSS rules.
   */
  private function regionRules(BoundThemeSettings $settings, array &$refused): array {
    $rules = [];
    foreach (self::REGION_SELECTORS as $region => $selector) {
      $declarations = [];
      foreach (self::COLOR_PROPERTIES as $name => $property) {
        $field = 'regions.' . $region . '.colors.' . $name;
        $value = $this->optionalValue($settings, $field);
        // Empty means "use the color scheme", as in 1.x.
        if ($value === NULL) {
          continue;
        }
        $color = $this->validator->color($value);
        if ($color === NULL) {
          $refused[] = $field;
          continue;
        }
        $declarations[] = $property . ':' . $color;
      }
      if ($declarations !== []) {
        $rules[] = $selector . '{' . implode(';', $declarations) . '}';
      }
    }
    return $rules;
  }

  /**
   * Builds the back to top rules, which 1.x printed as `style` attributes.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param list<string> $refused
   *   Field names refused so far.
   *
   * @return list<string>
   *   CSS rules; none while the feature is off.
   */
  private function backToTopRules(BoundThemeSettings $settings, array &$refused): array {
    if (!$settings->bool('back_to_top.enabled')) {
      return [];
    }
    $threshold = $this->clampedInteger($settings, 'back_to_top.scroll_threshold', 400, 200, 800, $refused);
    $rules = ['.solo-back-to-top-sentinel-wrapper{--solo-back-to-top-sentinel-height:' . $threshold . 'px}'];

    $declarations = [];
    foreach (['background' => '--r-bg', 'text' => '--r-tx'] as $name => $property) {
      $color = $this->hexOrNothing($settings, 'back_to_top.colors.' . $name, $refused);
      if ($color !== NULL) {
        $declarations[] = $property . ':' . $color;
      }
    }
    if ($declarations !== []) {
      $rules[] = '#solo-back-to-top{' . implode(';', $declarations) . '}';
    }
    return $rules;
  }

  /**
   * Builds the preloader's custom properties.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param list<string> $refused
   *   Field names refused so far.
   *
   * @return list<string>
   *   Declarations; none while the feature is off.
   */
  private function preloaderProperties(BoundThemeSettings $settings, array &$refused): array {
    if (!$settings->bool('preloader.enabled') && !$settings->bool('preloader.force_show')) {
      return [];
    }
    $style = $settings->string('preloader.style', 'spinner');

    // The opacity belongs to the background only, so it never dims the text.
    $background = $this->hexOrNothing($settings, 'preloader.colors.background', $refused);
    $opacity = $this->clampedInteger($settings, 'preloader.bg_opacity', 100, 0, 100, $refused);
    $declarations = ['--solo-preloader-bg:' . ($background === NULL ? 'transparent' : ($this->validator->rgba($background, $opacity) ?? 'transparent'))];

    $text = $this->hexOrNothing($settings, 'preloader.colors.text', $refused);
    if ($text !== NULL) {
      $declarations[] = '--r-tx:' . $text;
    }
    if ($style === 'logo' || $style === 'logo_text') {
      $declarations[] = '--solo-preloader-logo-width:' . $this->clampedInteger($settings, 'preloader.logo.width', 160, 16, 800, $refused) . 'px';
      $declarations[] = '--solo-preloader-logo-height:' . $this->clampedInteger($settings, 'preloader.logo.height', 80, 16, 600, $refused) . 'px';
      // A stored 0 means fully opaque, as in 1.x: a hidden logo is never wanted.
      $declarations[] = '--solo-preloader-logo-opacity:' . $this->validator->alphaFromPercent($this->clampedInteger($settings, 'preloader.logo.opacity', 100, 0, 100, $refused) ?: 100);
    }
    if ($style === 'text' || $style === 'logo_text') {
      $declarations[] = '--solo-preloader-text-font-size:' . $this->clampedInteger($settings, 'preloader.text.font_size', 24, 12, 100, $refused) . 'px';
      $declarations[] = '--solo-preloader-text-line-height:1.2';
    }
    return $declarations;
  }

  /**
   * Reads text the site builder typed.
   *
   * Its author holds "administer themes" and is trusted to write it, so it is
   * not filtered as code. It is only refused when it is not valid UTF-8.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param string $field
   *   The setting key.
   * @param list<string> $refused
   *   Field names refused so far.
   */
  private function typedText(BoundThemeSettings $settings, string $field, array &$refused): string {
    $text = $settings->string($field);
    if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
      $refused[] = $field;
      return '';
    }
    return $text;
  }

  /**
   * Reads the typed CSS box, checked and minified.
   *
   * It was a style tag, where text that can end the tag must not pass, so the
   * same check applies now that it is a file: a refused box emits nothing and
   * its field name is reported.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param list<string> $refused
   *   Field names refused so far.
   */
  private function inlineCss(BoundThemeSettings $settings, array &$refused): string {
    $css = $settings->string('custom_code.inline_css');
    if ($css === '') {
      return '';
    }
    if (!$this->validator->isSafeStylesheet($css)) {
      $refused[] = 'custom_code.inline_css';
      return '';
    }
    return $this->minify($css);
  }

  /**
   * Reads a setting that may be unset.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param string $field
   *   The setting key.
   *
   * @return mixed
   *   The value, or NULL for an unset or empty one.
   */
  private function optionalValue(BoundThemeSettings $settings, string $field): mixed {
    $value = $settings->get($field);
    return $value === '' ? NULL : $value;
  }

  /**
   * A hex color setting; 1.x accepted hex only in these fields.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param string $field
   *   The setting key.
   * @param list<string> $refused
   *   Field names refused so far.
   *
   * @return string|null
   *   The color, or NULL when unset or refused.
   */
  private function hexOrNothing(BoundThemeSettings $settings, string $field, array &$refused): ?string {
    $value = $this->optionalValue($settings, $field);
    if ($value === NULL) {
      return NULL;
    }
    $color = $this->validator->hexColor($value);
    if ($color === NULL) {
      $refused[] = $field;
    }
    return $color;
  }

  /**
   * An integer setting held to the range 1.x enforced.
   *
   * Clamping an integer yields another integer, so it cannot carry anything
   * hostile. A value that is not an integer is refused and the default is used.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param string $field
   *   The setting key.
   * @param int $default
   *   Used when the value is unset or refused.
   * @param int $min
   *   The lowest value output.
   * @param int $max
   *   The highest value output.
   * @param list<string> $refused
   *   Field names refused so far.
   */
  private function clampedInteger(BoundThemeSettings $settings, string $field, int $default, int $min, int $max, array &$refused): int {
    $value = $this->optionalValue($settings, $field);
    if ($value === NULL) {
      return $default;
    }
    $integer = $this->validator->integerInRange($value, PHP_INT_MIN, PHP_INT_MAX);
    if ($integer === NULL) {
      $refused[] = $field;
      return $default;
    }
    return max($min, min($max, $integer));
  }

}
