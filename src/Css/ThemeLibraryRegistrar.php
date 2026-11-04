<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Css;

use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * Defines one library per Solo theme for its generated stylesheets.
 *
 * Uses the same library shape as paragraphs_bundles and w3css_paragraphs.
 *
 * @internal
 */
final class ThemeLibraryRegistrar {

  /**
   * The module that owns the libraries.
   */
  public const EXTENSION = 'solo_utilities';

  /**
   * Prefix of a library name; the theme machine name follows.
   */
  private const LIBRARY_PREFIX = 'theme-settings--';

  /**
   * Generated rules load last.
   *
   * The `style` attributes they replace outranked every stylesheet.
   */
  private const SETTINGS_WEIGHT = 1000;

  /**
   * Typed CSS keeps 1.x's weight, just below the generated rules.
   */
  private const CUSTOM_WEIGHT = 999;

  public function __construct(
    private readonly ThemeCssBuilder $builder,
    private readonly ThemeAssetFiles $files,
    private readonly SoloThemeDetection $themeDetection,
  ) {}

  /**
   * The library name of a theme.
   *
   * @param string $theme
   *   The theme machine name.
   */
  public function libraryName(string $theme): string {
    return self::LIBRARY_PREFIX . $theme;
  }

  /**
   * Builds the libraries of every installed Solo theme.
   *
   * @return array<string, array{
   *   header: bool,
   *   css: array<string, array<string, array<string, mixed>>>
   *   }>
   *   Library definitions keyed by library name.
   */
  public function buildLibraries(): array {
    $libraries = [];
    foreach ($this->themeDetection->chainOnDisk() as $theme) {
      if (!$this->themeDetection->isInHierarchy($theme)) {
        continue;
      }
      $css = $this->stylesheets($theme, $this->builder->build($theme));
      if ($css !== []) {
        $libraries[$this->libraryName($theme)] = [
          'header' => FALSE,
          'css' => ['theme' => $css],
        ];
      }
    }
    return $libraries;
  }

  /**
   * The stylesheets a theme's library lists, by URI.
   *
   * The public:// URI and not a URL: the library parser treats a leading slash
   * as docroot-relative, which breaks sites in a subdirectory.
   *
   * Typed CSS has its own file so that an unclosed brace in it cannot swallow
   * the generated rules in the same file, and its `@import` stays first.
   *
   * @param string $theme
   *   The theme machine name.
   * @param \Drupal\solo_utilities\Css\GeneratedAssets $assets
   *   What the theme's settings produce.
   *
   * @return array<string, array<string, mixed>>
   *   Asset options keyed by URI, in load order.
   */
  public function stylesheets(string $theme, GeneratedAssets $assets): array {
    $css = [];
    if ($assets->customCss !== '') {
      $uri = $this->files->customCssUri($theme, $assets->customCss);
      $css[$uri] = ['weight' => self::CUSTOM_WEIGHT, 'minified' => TRUE];
    }
    if ($assets->settingsCss !== '') {
      $uri = $this->files->settingsCssUri($theme, $assets->settingsCss);
      $css[$uri] = ['weight' => self::SETTINGS_WEIGHT, 'minified' => TRUE];
    }
    return $css;
  }

}
