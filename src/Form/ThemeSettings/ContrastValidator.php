<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Checks the text and background colors a site builder sets for contrast.
 *
 * A pair is two colors that sit on each other. When both are stored the ratio
 * is a fact, and a ratio below the threshold is an error. When only one is
 * stored the other comes from the color scheme, which changes per scheme, so
 * the pair is checked against every scheme and the failures are a warning.
 * Scheme palettes are only read, never changed.
 *
 * Thresholds are WCAG 2.x: 4.5:1 for text, 3:1 for non-text parts such as the
 * back to top arrow and the preloader.
 *
 * @internal
 */
final class ContrastValidator {

  use StringTranslationTrait;

  /**
   * The minimum ratio for text.
   */
  public const MIN_TEXT = 4.5;

  /**
   * The minimum ratio for large text and non-text parts.
   */
  public const MIN_NON_TEXT = 3.0;

  /**
   * The most scheme names a warning lists before it counts the rest.
   */
  private const LISTED_SCHEMES = 5;

  /**
   * The scheme token each region group paints its background with.
   *
   * Regions that are missing here (header, menus, page wrapper) take their
   * background from rules that differ per element, so a pair with an inherited
   * background there is not checked against the schemes.
   */
  private const REGION_BACKGROUND = [
    'main_container' => '--sc-main-container-bg',
    'sidebar_first' => '--sc-main-container-bg',
    'content' => '--sc-main-container-bg',
    'sidebar_second' => '--sc-main-container-bg',
    'top_container' => '--sc-top-container-bg',
    'top_first' => '--sc-top-container-bg',
    'top_second' => '--sc-top-container-bg',
    'top_third' => '--sc-top-container-bg',
    'bottom_container' => '--sc-bottom-container-bg',
    'bottom_first' => '--sc-bottom-container-bg',
    'bottom_second' => '--sc-bottom-container-bg',
    'bottom_third' => '--sc-bottom-container-bg',
    'bottom_fourth' => '--sc-bottom-container-bg',
    'footer_container' => '--sc-footer-container-bg',
    'footer_first' => '--sc-footer-container-bg',
    'footer_second' => '--sc-footer-container-bg',
    'footer_third' => '--sc-footer-container-bg',
    'footer_menu' => '--sc-footer-menu-bg',
    'fixed_search_block' => '--sc-fixed-search-block-bg',
    'popup_login_block' => '--sc-popup-login-block-inner-bg',
    'credit_copyright' => '--sc-copyright-bg',
  ];

  /**
   * The pairs checked under regions.<region>.colors.
   *
   * Each is [foreground field, background field, scheme token of the
   * foreground, scheme token of the background]. The background token
   * "region" means the region's own background token.
   */
  private const REGION_PAIRS = [
    ['text', 'background', NULL, 'region'],
    ['heading', 'background', '--sc-head-tx', 'region'],
    ['link', 'background', '--sc-link-tx', 'region'],
    ['link_hover', 'background', '--sc-link-hover-tx', 'region'],
    ['button_text', 'button_background', '--sc-button-tx', '--sc-button-bg'],
    ['button_text_hover', 'button_background_hover', '--sc-button-hover-tx', '--sc-button-hover-bg'],
    ['menu_link_text', 'menu_link_background', '--sc-menu-tx', '--sc-menu-bg'],
    ['menu_link_text_hover', 'menu_link_background_hover', '--sc-menu-hover-tx', '--sc-menu-hover-bg'],
  ];

  /**
   * Schemes already read, by directory.
   *
   * @var array<string, array<string, array{name: string, palette: array<string, string>}>>
   */
  private static array $schemeCache = [];

  /**
   * Checks the submitted form values and reports what it finds.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The theme settings form state.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   Shows the warnings.
   * @param string $schemes_directory
   *   The directory holding Solo's scheme .yml files.
   */
  public function validateForm(FormStateInterface $form_state, MessengerInterface $messenger, string $schemes_directory): void {
    $findings = $this->validate($form_state->getValues(), self::loadSchemes($schemes_directory));
    foreach ($findings as $finding) {
      if ($finding['severity'] === 'error') {
        $form_state->setErrorByName($finding['fields'][0], $finding['message']);
      }
      else {
        $messenger->addWarning($finding['message']);
      }
    }
  }

  /**
   * Finds the contrast problems in a set of submitted values.
   *
   * @param array<string, mixed> $values
   *   The submitted form values.
   * @param array<string, array{name: string, palette: array<string, string>}> $schemes
   *   The schemes, from loadSchemes().
   *
   * @return list<array{severity: string, fields: list<string>, message: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   One entry per failing pair. "fields" are form element names for
   *   FormStateInterface::setErrorByName().
   */
  public function validate(array $values, array $schemes): array {
    $findings = [];
    $regions = $values['regions'] ?? [];
    if (is_array($regions)) {
      foreach ($regions as $region => $region_values) {
        $colors = is_array($region_values) ? ($region_values['colors'] ?? NULL) : NULL;
        if (!is_array($colors)) {
          continue;
        }
        foreach (self::REGION_PAIRS as [$fg, $bg, $fg_token, $bg_token]) {
          $bg_token = $bg_token === 'region' ? (self::REGION_BACKGROUND[$region] ?? NULL) : $bg_token;
          $this->checkPair($findings, $schemes, [
            'fg' => $colors[$fg] ?? NULL,
            'bg' => $colors[$bg] ?? NULL,
            'fg_field' => 'regions][' . $region . '][colors][' . $fg,
            'bg_field' => 'regions][' . $region . '][colors][' . $bg,
            'fg_token' => $fg_token,
            'bg_token' => $bg_token,
            'min' => self::MIN_TEXT,
          ]);
        }
      }
    }
    // The back to top arrow and the preloader are not necessarily text, so the
    // lower non-text ratio applies; neither has a scheme partner.
    foreach (['back_to_top', 'preloader'] as $group) {
      $colors = is_array($values[$group] ?? NULL) ? ($values[$group]['colors'] ?? NULL) : NULL;
      if (is_array($colors)) {
        $this->checkPair($findings, $schemes, [
          'fg' => $colors['text'] ?? NULL,
          'bg' => $colors['background'] ?? NULL,
          'fg_field' => $group . '][colors][text',
          'bg_field' => $group . '][colors][background',
          'fg_token' => NULL,
          'bg_token' => NULL,
          'min' => self::MIN_NON_TEXT,
        ]);
      }
    }
    return $findings;
  }

  /**
   * Reads every scheme's palette from its .yml file.
   *
   * @param string $directory
   *   The directory holding the scheme .yml files.
   *
   * @return array<string, array{name: string, palette: array<string, string>}>
   *   Scheme id to name and --sc-* palette. Unreadable files are skipped.
   */
  public static function loadSchemes(string $directory): array {
    if (isset(self::$schemeCache[$directory])) {
      return self::$schemeCache[$directory];
    }
    $schemes = [];
    $files = glob(rtrim($directory, '/') . '/*.yml');
    foreach ($files === FALSE ? [] : $files as $file) {
      $contents = file_get_contents($file);
      if ($contents === FALSE) {
        continue;
      }
      try {
        $data = Yaml::decode($contents);
      }
      catch (\Throwable) {
        continue;
      }
      if (!is_array($data) || !is_array($data['palette'] ?? NULL)) {
        continue;
      }
      $palette = array_filter($data['palette'], is_string(...));
      $schemes[(string) ($data['id'] ?? basename($file, '.yml'))] = [
        'name' => (string) ($data['name'] ?? basename($file, '.yml')),
        'palette' => $palette,
      ];
    }
    return self::$schemeCache[$directory] = $schemes;
  }

  /**
   * Returns the WCAG contrast ratio of two hex colors.
   *
   * @param string $first
   *   A 3 or 6 digit hex color with its #.
   * @param string $second
   *   Another.
   *
   * @return float|null
   *   The ratio from 1 to 21, or NULL when either is not a hex color.
   */
  public static function ratio(string $first, string $second): ?float {
    $a = self::luminance($first);
    $b = self::luminance($second);
    if ($a === NULL || $b === NULL) {
      return NULL;
    }
    return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
  }

  /**
   * Finds every hex color in a palette value, so a gradient yields its stops.
   *
   * @return list<string>
   *   The 3 and 6 digit hex colors, in order. 8 digit colors lose their alpha.
   */
  public static function hexColors(string $value): array {
    preg_match_all('/#(?:[0-9a-fA-F]{8}|[0-9a-fA-F]{6}|[0-9a-fA-F]{3})(?![0-9a-fA-F])/', $value, $matches);
    return array_map(static fn (string $hex): string => strlen($hex) === 9 ? substr($hex, 0, 7) : $hex, $matches[0]);
  }

  /**
   * Adds a finding when a pair fails its threshold.
   *
   * @param list<array<string, mixed>> $findings
   *   The findings so far.
   * @param array<string, array{name: string, palette: array<string, string>}> $schemes
   *   The schemes.
   * @param array<string, mixed> $pair
   *   The pair: stored values, field names, scheme tokens and the minimum.
   */
  private function checkPair(array &$findings, array $schemes, array $pair): void {
    $fg = $this->storedHex($pair['fg']);
    $bg = $this->storedHex($pair['bg']);
    // A stored value that is not a plain hex (a var() reference, rgba) cannot
    // be measured here, so its pair is left alone.
    if ($fg === FALSE || $bg === FALSE) {
      return;
    }
    $min = (float) $pair['min'];
    $fields = [(string) $pair['fg_field'], (string) $pair['bg_field']];
    $names = ['@fg' => $this->fieldLabel($fields[0]), '@bg' => $this->fieldLabel($fields[1])];

    if ($fg !== NULL && $bg !== NULL) {
      $ratio = self::ratio($fg, $bg);
      if ($ratio !== NULL && $ratio < $min) {
        $findings[] = [
          'severity' => 'error',
          'fields' => $fields,
          'message' => $this->t('The contrast of @fg (@fg_hex) on @bg (@bg_hex) is @ratio:1, below the required @min:1. Choose colors that differ more in lightness.', $names + [
            '@fg_hex' => $fg,
            '@bg_hex' => $bg,
            '@ratio' => number_format($ratio, 2),
            '@min' => number_format($min, 1),
          ]),
        ];
      }
      return;
    }
    if ($fg === NULL && $bg === NULL) {
      return;
    }
    // One side is stored; the other comes from each scheme.
    $set = $fg ?? $bg;
    $token = $fg === NULL ? $pair['fg_token'] : $pair['bg_token'];
    if (!is_string($token)) {
      return;
    }
    $failing = [];
    foreach ($schemes as $scheme) {
      $worst = NULL;
      foreach (self::hexColors($scheme['palette'][$token] ?? '') as $hex) {
        $ratio = self::ratio((string) $set, $hex);
        if ($ratio !== NULL && ($worst === NULL || $ratio < $worst)) {
          $worst = $ratio;
        }
      }
      if ($worst !== NULL && $worst < $min) {
        $failing[] = $scheme['name'];
      }
    }
    if ($failing === []) {
      return;
    }
    sort($failing);
    $listed = array_slice($failing, 0, self::LISTED_SCHEMES);
    $findings[] = [
      'severity' => 'warning',
      'fields' => $fields,
      'message' => $this->formatPlural(
        count($failing),
        'The @set_field (@set_hex) is set but @other_field comes from the color scheme, and the contrast is below @min:1 on the scheme @schemes. Set @other_field too, or pick a different color.',
        'The @set_field (@set_hex) is set but @other_field comes from the color scheme, and the contrast is below @min:1 on @count schemes, among them @schemes. Set @other_field too, or pick a different color.',
        [
          '@set_field' => $fg === NULL ? $names['@bg'] : $names['@fg'],
          '@other_field' => $fg === NULL ? $names['@fg'] : $names['@bg'],
          '@set_hex' => $set,
          '@min' => number_format($min, 1),
          '@schemes' => implode(', ', $listed),
        ],
      ),
    ];
  }

  /**
   * Reads a stored color.
   *
   * @return string|false|null
   *   The hex color, NULL when empty (inherits), FALSE when it is set but not
   *   a plain hex color.
   */
  private function storedHex(mixed $value): string|false|null {
    if ($value === NULL || $value === '') {
      return NULL;
    }
    if (!is_string($value)) {
      return FALSE;
    }
    $value = trim($value);
    return preg_match('/^#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{3})$/D', $value) === 1 ? $value : FALSE;
  }

  /**
   * Turns a field name into words for a message.
   */
  private function fieldLabel(string $field): string {
    $parts = explode('][', $field);
    return (string) str_replace('_', ' ', implode(' ', array_filter($parts, static fn (string $p): bool => $p !== 'colors')));
  }

  /**
   * Computes the WCAG relative luminance of a hex color.
   */
  private static function luminance(string $hex): ?float {
    $hex = ltrim($hex, '#');
    if (preg_match('/^(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/D', $hex) !== 1) {
      return NULL;
    }
    if (strlen($hex) === 3) {
      $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $linear = [];
    foreach (str_split($hex, 2) as $channel) {
      $c = hexdec($channel) / 255;
      $linear[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
  }

}
