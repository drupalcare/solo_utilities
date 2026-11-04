<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Security\UrlSafety;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * Builds Solo's variables and attachments for the html template.
 *
 * Generated and typed CSS and JavaScript are attached by AssetAttacher.
 *
 * @internal
 */
final class HtmlPreprocessor {

  /**
   * Twig's name of each skip link, and the setting that turns it on.
   */
  private const SKIP_LINKS = [
    'skip_header_content' => 'skip_links.header',
    'skip_navigation_content' => 'skip_links.navigation',
    'skip_main_content' => 'skip_links.main',
    'skip_footer_content' => 'skip_links.footer',
  ];

  /**
   * Menu speeds in milliseconds; anything else stored means normal.
   */
  private const MENU_SPEEDS = [
    'normal' => ['slideUpSpeed' => 350, 'slideDownSpeed' => 500, 'megaMenuSpeed' => 800],
    'fast' => ['slideUpSpeed' => 150, 'slideDownSpeed' => 250, 'megaMenuSpeed' => 500],
    'slow' => ['slideUpSpeed' => 500, 'slideDownSpeed' => 700, 'megaMenuSpeed' => 1400],
  ];

  public function __construct(
    private readonly BodyClasses $bodyClasses,
    private readonly RequestContext $requestContext,
    private readonly UrlSafety $urlSafety,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Fills the html template variables.
   *
   * @param array<string, mixed> $variables
   *   The html template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocess(array &$variables, BoundThemeSettings $settings): void {
    $variables['solo_path'] = $this->requestContext->basePath() . '/' . $this->extensionPathResolver->getPath('theme', 'solo');
    $this->bodyClasses->apply($variables, $settings);

    $variables['site_css_injector'] = $settings->get('custom_code.inline_css');
    $variables['site_css_dynamic'] = $settings->get('custom_code.css_file');
    $variables['site_import_google_font'] = $settings->get('typography.imported_google_font');
    $this->attachImportedFont($variables, $settings);

    if ($this->moduleHandler->moduleExists('cookies')) {
      $variables['#attached']['library'][] = 'solo/solo-cookies';
    }
    $variables['enabled_skip_links'] = $this->enabledSkipLinks($settings);

    $speeds = self::MENU_SPEEDS[$settings->string('menus.toggle_speed')] ?? self::MENU_SPEEDS['normal'];
    $variables['#attached']['drupalSettings']['solo'] = array_merge($variables['#attached']['drupalSettings']['solo'] ?? [], $speeds);

    CacheableMetadata::createFromRenderArray($variables)
      ->addCacheableDependency($settings)
      ->addCacheContexts([RequestContext::CACHE_CONTEXT_ROUTE, RequestContext::CACHE_CONTEXT_PATH])
      ->applyTo($variables);
  }

  /**
   * Lists the skip links the settings turn on.
   *
   * @return array<string, true>
   *   Twig's skip link names.
   */
  public function enabledSkipLinks(BoundThemeSettings $settings): array {
    $enabled = [];
    foreach (self::SKIP_LINKS as $name => $key) {
      if ($settings->bool($key)) {
        $enabled[$name] = TRUE;
      }
    }
    return $enabled;
  }

  /**
   * Links the imported Google font stylesheet, when its URL is allowed.
   *
   * @param array<string, mixed> $variables
   *   The html template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function attachImportedFont(array &$variables, BoundThemeSettings $settings): void {
    $stored = $settings->string('typography.imported_google_font');
    if ($stored === '') {
      return;
    }
    $url = $this->urlSafety->fontStylesheetUrl($stored);
    if ($url === NULL) {
      $this->loggerFactory->get('solo_utilities')->warning('Solo theme @theme: setting @field was refused and emits nothing.', [
        '@theme' => $settings->themeName(),
        '@field' => 'typography.imported_google_font',
      ]);
      return;
    }
    $variables['#attached']['html_head'][] = [
      [
        '#tag' => 'link',
        '#attributes' => [
          'href' => $url,
          'rel' => 'stylesheet',
          'type' => 'text/css',
        ],
      ],
      'google_font',
    ];
  }

}
