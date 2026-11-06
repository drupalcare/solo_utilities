<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Security;

/**
 * Decides whether a stored value may reach CSS. Fails closed.
 *
 * Every method returns the safe form of the value, or NULL. NULL means "emit
 * nothing"; the caller knows which field it was reading and logs that field's
 * name, never the value. Nothing is repaired: stripping characters from a
 * hostile value can produce a different hostile value.
 *
 * Every pattern carries the D modifier. Without it `$` also matches before a
 * final newline.
 *
 * @internal
 */
final class CssValueValidator {

  /**
   * Units a length may use.
   */
  private const LENGTH_UNITS = 'px|rem|em|%|vh|vw|vmin|vmax|ch';

  /**
   * One family name: bare, or wrapped in matching quotes.
   */
  private const FONT_NAME = '(?:[A-Za-z0-9 _-]+|\'[A-Za-z0-9 _-]+\'|"[A-Za-z0-9 _-]+")';

  /**
   * A CSS color value: hex, rgb() or rgba(), var(--name), or a safe keyword.
   */
  public function color(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    $keyword = strtolower($value);
    if ($keyword === 'transparent' || $keyword === 'currentcolor') {
      return $keyword;
    }
    return $this->hexColor($value) ?? $this->rgbFunction($value) ?? $this->customPropertyReference($value);
  }

  /**
   * A hex color of 3, 4, 6 or 8 digits.
   */
  public function hexColor(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return preg_match('/^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D', $value) === 1 ? $value : NULL;
  }

  /**
   * A color as an "r, g, b" triplet, from a 3 or 6 digit hex or a triplet.
   *
   * 1.x turned #fff into 255,15,0, as it read two digits from a short hex. A
   * short hex is expanded first here. The leading "#" is optional, as in 1.x.
   */
  public function rgbTriplet(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    if (preg_match('/^#?([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/D', $value, $short) === 1) {
      $value = '#' . $short[1] . $short[1] . $short[2] . $short[2] . $short[3] . $short[3];
    }
    if (preg_match('/^#?([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/D', $value, $hex) === 1) {
      return sprintf('%d, %d, %d', hexdec($hex[1]), hexdec($hex[2]), hexdec($hex[3]));
    }
    if (preg_match('/^(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})$/D', $value, $parts) !== 1) {
      return NULL;
    }
    $channels = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
    return max($channels) > 255 ? NULL : implode(', ', $channels);
  }

  /**
   * An opacity percentage (0 to 100) as a CSS alpha value.
   */
  public function alphaFromPercent(mixed $value): ?string {
    $percent = $this->integerInRange($value, 0, 100);
    if ($percent === NULL) {
      return NULL;
    }
    return match ($percent) {
      0 => '0',
      100 => '1',
      default => sprintf('0.%02d', $percent),
    };
  }

  /**
   * An rgba() value from a color and an opacity percentage.
   */
  public function rgba(mixed $color, mixed $percent): ?string {
    $triplet = $this->rgbTriplet($color);
    $alpha = $this->alphaFromPercent($percent);
    return $triplet === NULL || $alpha === NULL ? NULL : 'rgba(' . $triplet . ', ' . $alpha . ')';
  }

  /**
   * A non-negative length with a unit, or a bare zero.
   */
  public function length(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    if ($value === '0') {
      return $value;
    }
    return preg_match('/^(?:\d{1,6}(?:\.\d{1,4})?|\.\d{1,4})(?:' . self::LENGTH_UNITS . ')$/D', $value) === 1 ? $value : NULL;
  }

  /**
   * An integer within a range, from an int or a string of digits.
   */
  public function integerInRange(mixed $value, int $min, int $max): ?int {
    if (is_string($value) && preg_match('/^-?\d{1,18}$/D', trim($value)) === 1) {
      $value = (int) trim($value);
    }
    return is_int($value) && $value >= $min && $value <= $max ? $value : NULL;
  }

  /**
   * One CSS class name.
   */
  public function className(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/D', $value) === 1 ? $value : NULL;
  }

  /**
   * Space-separated class names; one bad name refuses the whole list.
   *
   * @return list<string>|null
   *   The names, or NULL. An empty value gives an empty list.
   */
  public function classList(mixed $value): ?array {
    if (!is_string($value)) {
      return NULL;
    }
    $names = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($names as $name) {
      if ($this->className($name) === NULL) {
        return NULL;
      }
    }
    return $names;
  }

  /**
   * A custom property name, such as --solo-fb.
   */
  public function customPropertyName(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return preg_match('/^--[A-Za-z0-9_-]+$/D', $value) === 1 ? $value : NULL;
  }

  /**
   * A font-family list of plain or quoted names.
   */
  public function fontFamily(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return preg_match('/^' . self::FONT_NAME . '(?:\s*,\s*' . self::FONT_NAME . ')*$/D', $value) === 1 ? $value : NULL;
  }

  /**
   * The value when it is exactly one of a closed list.
   *
   * @param mixed $value
   *   The stored value.
   * @param list<string> $allowed
   *   The accepted values.
   */
  public function oneOf(mixed $value, array $allowed): ?string {
    return is_string($value) && in_array($value, $allowed, TRUE) ? $value : NULL;
  }

  /**
   * Whether typed CSS can go in a style tag or a CSS file.
   *
   * A closing style tag is the only way out of a style tag, so a plain `<`, as
   * in `@media (width < 600px)`, stays valid. This does not make typed CSS safe
   * as CSS: its author holds "administer themes" and is trusted to write it.
   */
  public function isSafeStylesheet(string $css): bool {
    return stripos($css, '</style') === FALSE && mb_check_encoding($css, 'UTF-8');
  }

  /**
   * An rgb() or rgba() value of plain numbers.
   */
  private function rgbFunction(string $value): ?string {
    if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(?:0|1|0?\.\d{1,4}|1\.0{1,4})\s*)?\)$/D', $value, $parts) !== 1) {
      return NULL;
    }
    return max((int) $parts[1], (int) $parts[2], (int) $parts[3]) > 255 ? NULL : $value;
  }

  /**
   * A var(--name) reference, with no fallback.
   */
  private function customPropertyReference(string $value): ?string {
    return preg_match('/^var\(\s*--[A-Za-z0-9_-]+\s*\)$/D', $value) === 1 ? $value : NULL;
  }

}
