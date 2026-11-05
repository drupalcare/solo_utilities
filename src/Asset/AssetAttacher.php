<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Asset;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\Markup;
use Drupal\solo_utilities\ColorSchemesNegotiator;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Css\GeneratedAssets;
use Drupal\solo_utilities\Css\ThemeAssetFiles;
use Drupal\solo_utilities\Css\ThemeCssBuilder;
use Drupal\solo_utilities\Css\ThemeLibraryRegistrar;
use Drupal\solo_utilities\Security\CssValueValidator;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetection;
use Psr\Log\LoggerInterface;

/**
 * Turns settings into attached libraries and head tags, with cache metadata.
 *
 * Every public method takes the theme by name and does nothing for a theme
 * outside the Solo chain.
 *
 * @internal
 */
final class AssetAttacher {

  /**
   * Icon library per stored choice.
   */
  private const ICON_LIBRARIES = [
    '1' => 'font-awesome-4-in',
    '2' => 'font-awesome-4-ex',
    '3' => 'font-awesome-5-ex',
    '4' => 'font-awesome-6-ex',
  ];

  /**
   * Preloader rules that must paint before any stylesheet loads.
   */
  private const PRELOADER_CRITICAL_CSS = 'html.solo-preloader-enabled #solo-preloader{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;opacity:1;visibility:visible;background-color:var(--solo-preloader-bg,transparent);color:var(--r-tx,#333)}html.solo-preloader-enabled #solo-preloader .solo-preloader__inner{display:flex;flex-direction:column;align-items:center;gap:1rem}html.solo-preloader-enabled #solo-preloader .visually-hidden{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}';

  public function __construct(
    private readonly SettingsReader $settingsReader,
    private readonly SoloThemeDetection $themeDetection,
    private readonly ThemeCssBuilder $builder,
    private readonly ThemeAssetFiles $files,
    private readonly ThemeLibraryRegistrar $registrar,
    private readonly CssValueValidator $validator,
    private readonly RequestContext $requestContext,
    private readonly ColorSchemesNegotiator $colorSchemesNegotiator,
    private readonly LibraryDiscoveryInterface $libraryDiscovery,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Attaches the generated stylesheets (typed inline CSS included) and script.
   *
   * When the file a page needs is absent, it is rebuilt, and this response gets
   * the rules inline so the page keeps its look and stays 200 even when the
   * disk refuses the write.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   */
  public function attachGeneratedAssets(array &$build, string $theme): void {
    if (!$this->themeDetection->isInHierarchy($theme)) {
      return;
    }
    $settings = $this->settingsReader->forTheme($theme);
    $this->addCacheTags($build, $settings);
    $assets = $this->builder->build($theme);

    $this->attachStylesheets($build, $theme, $assets);
    $this->attachCustomScript($build, $theme, $assets);
  }

  /**
   * Attaches the libraries that settings choose.
   *
   * Covers fonts, the icon library, W3.CSS, reveal on scroll, colored tabs and
   * the color scheme.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   */
  public function attachSettingLibraries(array &$build, string $theme): void {
    if (!$this->themeDetection->isInHierarchy($theme)) {
      return;
    }
    $settings = $this->settingsReader->forTheme($theme);
    $this->addCacheTags($build, $settings);

    $source = $settings->bool('typography.fonts.local') ? 'in' : 'ex';
    $this->attachFont($build, $theme, $settings, 'typography.fonts.global', $source);
    $this->attachFont($build, $theme, $settings, 'typography.fonts.heading', $source);
    $this->attachFont($build, $theme, $settings, 'typography.fonts.special', 'ex');
    $this->attachFont($build, $theme, $settings, 'header.site_name.google_font', 'ex');
    $this->attachFont($build, $theme, $settings, 'page_title.google_font', 'ex');

    $this->attachColorScheme($build, $theme, $settings);

    $icon_library = self::ICON_LIBRARIES[$settings->string('typography.fonts.icon_library')] ?? NULL;
    if ($icon_library !== NULL) {
      $this->attachSoloLibrary($build, $theme, 'typography.fonts.icon_library', $icon_library);
    }
    if ($settings->bool('typography.load_w3css')) {
      $this->attachSoloLibrary($build, $theme, 'typography.load_w3css', 'w3-css');
    }
    if ($settings->bool('color_scheme.colored_system_tabs')) {
      $this->attachSoloLibrary($build, $theme, 'color_scheme.colored_system_tabs', 'solo-color-coded-system-tabs');
    }
    if ($settings->bool('layout.reveal_on_scroll')) {
      $build['#cache']['contexts'][] = RequestContext::CACHE_CONTEXT_ROUTE;
      if (!$this->requestContext->isAdminRoute()) {
        $this->attachSoloLibrary($build, $theme, 'layout.reveal_on_scroll', 'solo-full-opacity');
        $build['#attached']['drupalSettings']['solo']['fullOpacity'] ??= [
          'animationHeightRatio' => 0.15,
          'fadeInDuration' => '1s',
          'fadeOutDuration' => '0.5s',
          'throttleDelay' => 16,
          'debounceDelay' => 250,
          'useIntersectionObserver' => TRUE,
        ];
      }
    }
  }

  /**
   * Attaches the preloader's critical CSS to the document head.
   *
   * The named exception to "CSS goes in a file": it must paint before any
   * stylesheet. The caller decides whether this request shows the preloader.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   */
  public function attachPreloaderCriticalCss(array &$build, string $theme): void {
    if (!$this->themeDetection->isInHierarchy($theme)) {
      return;
    }
    $this->addCacheTags($build, $this->settingsReader->forTheme($theme));
    $this->attachStyleTag($build, 'solo_preloader_critical', self::PRELOADER_CRITICAL_CSS . $this->builder->build($theme)->preloaderCss);
  }

  /**
   * Attaches the font of the preloader text, when one is chosen.
   *
   * The caller decides whether this request shows text.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   */
  public function attachPreloaderFont(array &$build, string $theme): void {
    if ($this->themeDetection->isInHierarchy($theme)) {
      $this->attachFont($build, $theme, $this->settingsReader->forTheme($theme), 'preloader.text.font', 'ex');
    }
  }

  /**
   * Attaches the theme's library, or inline rules when it cannot be used yet.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   * @param \Drupal\solo_utilities\Css\GeneratedAssets $assets
   *   What the theme's settings produce.
   */
  private function attachStylesheets(array &$build, string $theme, GeneratedAssets $assets): void {
    $wanted = array_keys($this->registrar->stylesheets($theme, $assets));
    if ($wanted === []) {
      return;
    }
    if ($this->libraryIsCurrent($theme, $wanted)) {
      $build['#attached']['library'][] = ThemeLibraryRegistrar::EXTENSION . '/' . $this->registrar->libraryName($theme);
      return;
    }

    // The file is absent or the cached library names another one. A library
    // cannot be used in this response, so the next request picks it up.
    $this->files->rebuild($theme);
    $this->files->invalidateLibraries();

    if ($assets->customCss !== '') {
      if ($this->validator->isSafeStylesheet($assets->customCss)) {
        $this->attachStyleTag($build, 'solo_custom_css', $this->builder->minify($assets->customCss));
      }
      else {
        // In a file `<` is valid CSS, but it can end a style tag. The file is
        // used from the next request on, so only this response goes without.
        $this->logRefused($theme, 'custom_code.css_file');
      }
    }
    if ($assets->settingsCss !== '') {
      $this->attachStyleTag($build, 'solo_settings_css', $assets->settingsCss);
    }
    // For this one response the typed box prints in the head, before every
    // stylesheet, as it did before it had a file. The next request uses the
    // file. The id stays so a site's own selector on it keeps working.
    if ($assets->inlineCss !== '') {
      $this->attachStyleTag($build, 'inline_css', $assets->inlineCss, ['id' => 'solo-css-injector']);
    }
  }

  /**
   * Tells whether the registered library holds exactly these files, on disk.
   *
   * @param string $theme
   *   The theme machine name.
   * @param list<string> $wanted
   *   The URIs the settings call for.
   */
  private function libraryIsCurrent(string $theme, array $wanted): bool {
    foreach ($wanted as $uri) {
      if (!$this->files->exists($uri)) {
        return FALSE;
      }
    }
    $library = $this->libraryDiscovery->getLibraryByName(ThemeLibraryRegistrar::EXTENSION, $this->registrar->libraryName($theme));
    if (!is_array($library)) {
      return FALSE;
    }
    $registered = array_column($library['css'] ?? [], 'data');
    sort($registered);
    sort($wanted);
    return $registered === $wanted;
  }

  /**
   * Attaches the typed script as a deferred tag.
   *
   * Not a library: locale's scan of JavaScript assets refuses a public:// URI
   * (recorded in solo.libraries.yml). The hashed name replaces 1.x's query
   * string, as new content has a new URL.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   * @param \Drupal\solo_utilities\Css\GeneratedAssets $assets
   *   What the theme's settings produce.
   */
  private function attachCustomScript(array &$build, string $theme, GeneratedAssets $assets): void {
    if ($assets->customJs === '') {
      return;
    }
    $uri = $this->files->customJsUri($theme, $assets->customJs);
    if (!$this->files->exists($uri) && !$this->files->rebuild($theme)) {
      return;
    }
    $this->attachSoloLibrary($build, $theme, 'custom_code.js_file', 'solo-js-dynamic');
    $build['#attached']['html_head'][] = [
      [
        '#type' => 'html_tag',
        '#tag' => 'script',
        '#attributes' => [
          'src' => $this->fileUrlGenerator->generateString($uri),
          'defer' => TRUE,
        ],
      ],
      'solo_js_dynamic',
    ];
  }

  /**
   * Attaches the color scheme: a matching rule wins over the stored choice.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   */
  private function attachColorScheme(array &$build, string $theme, BoundThemeSettings $settings): void {
    $build['#cache']['tags'][] = 'config:color_schemes_rule_list';
    $negotiated = $this->colorSchemesNegotiator->evaluateRules();
    if (is_string($negotiated) && $negotiated !== '') {
      $this->attachSoloLibrary($build, $theme, 'color_scheme.name', $negotiated);
      return;
    }
    if ($settings->string('color_scheme.category') === 'none') {
      return;
    }
    $name = $settings->string('color_scheme.name');
    if ($name !== '') {
      $this->attachSoloLibrary($build, $theme, 'color_scheme.name', $name);
    }
  }

  /**
   * Attaches one font library from a stored font name.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param string $field
   *   The setting key.
   * @param string $source
   *   The library family: in for local, ex for external.
   */
  private function attachFont(array &$build, string $theme, BoundThemeSettings $settings, string $field, string $source): void {
    // The stored name starts with a four character prefix that 1.x dropped.
    $font = substr($settings->string($field), 4);
    if ($font !== '') {
      $this->attachSoloLibrary($build, $theme, $field, 'solo-gf' . $source . $font);
    }
  }

  /**
   * Attaches a library of Solo's, refusing a name Solo does not define.
   *
   * An unknown library is dropped here so that a stale setting cannot reach the
   * asset resolver.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $theme
   *   The theme machine name.
   * @param string $field
   *   The setting that chose it, for the log.
   * @param string $library
   *   The library name.
   */
  private function attachSoloLibrary(array &$build, string $theme, string $field, string $library): void {
    if (preg_match('/^[A-Za-z0-9_-]+$/D', $library) !== 1 || $this->libraryDiscovery->getLibraryByName('solo', $library) === FALSE) {
      $this->logRefused($theme, $field);
      return;
    }
    $build['#attached']['library'][] = 'solo/' . $library;
  }

  /**
   * Attaches a style tag to the document head.
   *
   * @param array<string, mixed> $build
   *   A render array to attach to.
   * @param string $key
   *   The unique head key.
   * @param string $css
   *   CSS with no closing style tag: every caller holds generated values or
   *   checked it with CssValueValidator::isSafeStylesheet().
   * @param array<string, string> $attributes
   *   The style tag's attributes.
   */
  private function attachStyleTag(array &$build, string $key, string $css, array $attributes = []): void {
    $build['#attached']['html_head'][] = [
      [
        '#type' => 'html_tag',
        '#tag' => 'style',
        '#attributes' => $attributes,
        '#value' => Markup::create($css),
      ],
      $key,
    ];
  }

  /**
   * Adds the settings' cache tags to a render array.
   *
   * @param array<string, mixed> $build
   *   A render array.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   */
  private function addCacheTags(array &$build, BoundThemeSettings $settings): void {
    $build['#cache']['tags'] = array_values(array_unique(array_merge($build['#cache']['tags'] ?? [], $settings->getCacheTags())));
  }

  /**
   * Logs a refused setting by name, never by value.
   *
   * @param string $theme
   *   The theme machine name.
   * @param string $field
   *   The setting key.
   */
  private function logRefused(string $theme, string $field): void {
    $this->logger->warning('Solo theme @theme: setting @field was refused and emits nothing.', [
      '@theme' => $theme,
      '@field' => $field,
    ]);
  }

}
