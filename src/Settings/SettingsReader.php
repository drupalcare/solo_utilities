<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Settings;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;

/**
 * The only caller of ThemeSettingsProvider in this module.
 *
 * Every read names its theme. ThemeSettingsProvider::getSetting() without a
 * name falls back to the active theme, which is the admin theme on the settings
 * form: the silent wrong-theme bug. A read here cannot be written that way.
 *
 * @internal
 */
final class SettingsReader {

  /**
   * The cache tag of the site's name.
   */
  public const SITE_CACHE_TAG = 'config:system.site';

  public function __construct(
    private readonly ThemeSettingsProvider $provider,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SettingsCatalog $catalog,
  ) {}

  /**
   * The name of the site, which Solo's branding shows.
   */
  public function siteName(): mixed {
    return $this->configFactory->get('system.site')->get('name');
  }

  /**
   * The settings of a named theme.
   *
   * @param string $theme
   *   The theme machine name.
   *
   * @throws \InvalidArgumentException
   *   When the name is empty. A caller that may hold no theme must check first:
   *   reading on would answer from the wrong theme without any error.
   */
  public function forTheme(string $theme): BoundThemeSettings {
    if ($theme === '') {
      throw new \InvalidArgumentException('Theme settings can only be read for a named theme.');
    }
    return new BoundThemeSettings($this->provider, $theme, $this->catalog);
  }

  /**
   * The cache tag of a theme's stored settings.
   */
  public static function cacheTag(string $theme): string {
    return 'config:' . $theme . '.settings';
  }

}
