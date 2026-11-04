<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Settings;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;

/**
 * The settings of one named theme, carrying their own cache metadata.
 *
 * Pass this object to CacheableMetadata::addCacheableDependency(), or read
 * getCacheTags(), so output built from a setting is invalidated when it changes.
 *
 * Built by SettingsReader::forTheme() only.
 *
 * @internal
 */
final class BoundThemeSettings implements CacheableDependencyInterface {

  public function __construct(
    private readonly ThemeSettingsProvider $provider,
    private readonly string $theme,
  ) {}

  /**
   * The machine name of the theme these settings belong to.
   */
  public function themeName(): string {
    return $this->theme;
  }

  /**
   * The stored value, or $default when the key is unset.
   *
   * @param string $key
   *   The setting key; dots address nested values.
   * @param mixed $default
   *   Returned when the stored value is NULL.
   */
  public function get(string $key, mixed $default = NULL): mixed {
    return $this->provider->getSetting($key, $this->theme) ?? $default;
  }

  /**
   * The value as a trimmed string; arrays and objects give $default.
   */
  public function string(string $key, string $default = ''): string {
    $value = $this->get($key);
    return is_scalar($value) ? trim((string) $value) : $default;
  }

  /**
   * The value as a boolean, with PHP's loose truth as 1.x read it.
   */
  public function bool(string $key, bool $default = FALSE): bool {
    return (bool) $this->get($key, $default);
  }

  /**
   * The value as an integer; anything not numeric gives $default.
   */
  public function int(string $key, int $default = 0): int {
    $value = $this->get($key);
    return is_numeric($value) ? (int) $value : $default;
  }

  /**
   * The value as an array; anything else gives $default.
   *
   * @param string $key
   *   The setting key.
   * @param array<mixed> $default
   *   Returned when the stored value is not an array.
   *
   * @return array<mixed>
   *   The stored array.
   */
  public function array(string $key, array $default = []): array {
    $value = $this->get($key);
    return is_array($value) ? $value : $default;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   *
   * The provider merges system.theme.global under the theme's own settings, so
   * a value can come from either object and both tags are needed.
   */
  public function getCacheTags(): array {
    return [
      SettingsReader::cacheTag($this->theme),
      'config:system.theme.global',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge(): int {
    return Cache::PERMANENT;
  }

}
