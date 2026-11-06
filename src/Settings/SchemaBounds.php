<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Settings;

/**
 * The one reader of the bounds Solo's settings schema declares.
 *
 * The schema (solo_theme_settings, generated from scripts/settings-gen/solo-2.0-constraints.yml)
 * owns every numeric range and text length. The settings form and the CSS
 * builder ask here instead of carrying a number of their own.
 *
 * A stored value outside its bound is clamped when it is read for display or
 * for CSS and is never rewritten, so a site that stored 70 under a 60 limit can
 * still open and save its settings form.
 *
 * @internal
 */
final class SchemaBounds {

  public function __construct(
    private readonly SettingsCatalog $catalog,
  ) {}

  /**
   * Returns the declared range of an integer setting.
   *
   * @param string $path
   *   A dotted settings path.
   *
   * @return array{min: int|null, max: int|null}
   *   The bounds; NULL for a side the schema does not limit.
   */
  public function range(string $path): array {
    $range = $this->catalog->constraintsAt($path)['Range'] ?? [];
    return [
      'min' => $this->wholeNumber($range['min'] ?? NULL),
      'max' => $this->wholeNumber($range['max'] ?? NULL),
    ];
  }

  /**
   * Returns the declared maximum length of a text setting.
   *
   * @param string $path
   *   A dotted settings path.
   *
   * @return int|null
   *   The length, or NULL when the schema declares none.
   */
  public function maxLength(string $path): ?int {
    $max = $this->wholeNumber($this->catalog->constraintsAt($path)['Length']['max'] ?? NULL);
    return $max !== NULL && $max > 0 ? $max : NULL;
  }

  /**
   * Holds an integer to the declared range of a setting.
   *
   * @param string $path
   *   A dotted settings path.
   * @param int $value
   *   The value read.
   *
   * @return int
   *   The value, moved to the nearest bound when it lies outside.
   */
  public function clamp(string $path, int $value): int {
    ['min' => $min, 'max' => $max] = $this->range($path);
    if ($min !== NULL && $value < $min) {
      return $min;
    }
    if ($max !== NULL && $value > $max) {
      return $max;
    }
    return $value;
  }

  /**
   * Gives a form element the bounds its setting declares.
   *
   * Sets #min, #max and #maxlength, and clamps the opening #default_value so
   * an out-of-range stored value does not block the next save.
   *
   * @param array<string, mixed> $element
   *   The form element.
   * @param string $path
   *   The dotted settings path the element posts to.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function applyTo(array $element, string $path): array {
    ['min' => $min, 'max' => $max] = $this->range($path);
    if ($min !== NULL) {
      $element['#min'] = $min;
    }
    if ($max !== NULL) {
      $element['#max'] = $max;
    }
    $default = $element['#default_value'] ?? NULL;
    if (($min !== NULL || $max !== NULL) && (is_int($default) || (is_string($default) && preg_match('/^-?\d{1,18}$/D', $default) === 1))) {
      $element['#default_value'] = $this->clamp($path, (int) $default);
    }

    $length = $this->maxLength($path);
    if ($length !== NULL) {
      $element['#maxlength'] = $length;
      if (is_string($default) && mb_strlen($default) > $length) {
        $element['#default_value'] = mb_substr($default, 0, $length);
      }
    }
    return $element;
  }

  /**
   * Casts a declared bound, refusing anything that is not a whole number.
   */
  private function wholeNumber(mixed $value): ?int {
    return is_int($value) ? $value : NULL;
  }

}
