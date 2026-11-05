<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Css;

use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

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
   * The aggregation group of the generated stylesheets.
   *
   * A module library is parsed into CSS_AGGREGATE_DEFAULT (0), before every
   * theme stylesheet (CSS_AGGREGATE_THEME, 100), and core sorts by group
   * before weight. AssetResolver::sort() invites modules to define their own
   * group; this one loads after Solo, its schemes and any sub-theme.
   *
   * @see \Drupal\Core\Asset\LibraryDiscoveryParser::buildByExtension()
   * @see \Drupal\Core\Asset\AssetResolver::sort()
   */
  public const GROUP = 200;

  /**
   * Region colours and back to top load after every theme stylesheet.
   *
   * The `style` attributes they replace outranked every stylesheet.
   */
  private const SETTINGS_WEIGHT = 1000;

  /**
   * Typed CSS loads last of all: the site owner has the last word.
   */
  private const CUSTOM_WEIGHT = 1001;

  /**
   * The typed CSS box loads after the typed file: it was the last word before.
   */
  private const INLINE_WEIGHT = 1002;

  public function __construct(
    private readonly ThemeCssBuilder $builder,
    private readonly ThemeAssetFiles $files,
    private readonly SoloThemeDetectionInterface $themeDetection,
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
    if ($assets->settingsCss !== '') {
      $uri = $this->files->settingsCssUri($theme, $assets->settingsCss);
      $css[$uri] = ['weight' => self::SETTINGS_WEIGHT, 'minified' => TRUE];
    }
    if ($assets->customCss !== '') {
      $uri = $this->files->customCssUri($theme, $assets->customCss);
      $css[$uri] = ['weight' => self::CUSTOM_WEIGHT, 'minified' => TRUE];
    }
    if ($assets->inlineCss !== '') {
      $uri = $this->files->inlineCssUri($theme, $assets->inlineCss);
      $css[$uri] = ['weight' => self::INLINE_WEIGHT, 'minified' => TRUE];
    }
    return $css;
  }

  /**
   * Moves the generated stylesheets into their own group, after the themes.
   *
   * The parser sets every module stylesheet's group unconditionally, so the
   * group can only be set once the page's CSS is collected, before core sorts
   * it. The weights from stylesheets() still order the files inside the group.
   *
   * @param array<string, array<string, mixed>> $css
   *   The page's CSS assets keyed by data, as hook_css_alter() passes them.
   */
  public function moveAfterThemes(array &$css): void {
    foreach ($css as $key => $options) {
      if ($this->files->isGeneratedStylesheet((string) ($options['data'] ?? $key))) {
        $css[$key]['group'] = self::GROUP;
      }
    }
  }

}
