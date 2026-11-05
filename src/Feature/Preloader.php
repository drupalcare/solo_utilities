<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Template\Attribute;
use Drupal\solo_utilities\Asset\AssetAttacher;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Security\UrlSafety;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * Decides whether this request shows the preloader, and prepares it.
 *
 * Its colors and sizes are custom properties built by ThemeCssBuilder and
 * attached with the critical CSS, so Twig prints no CSS.
 *
 * @internal
 */
final class Preloader {

  /**
   * Transition effects; anything else stored means fade.
   */
  private const TRANSITIONS = [
    'fade',
    'slide_up',
    'slide_down',
    'slide_left',
    'slide_right',
    'zoom_out',
    'blur',
  ];

  public function __construct(
    private readonly RequestContext $requestContext,
    private readonly UrlSafety $urlSafety,
    private readonly AssetAttacher $assetAttacher,
  ) {}

  /**
   * Fills preloader_show and preloader_config, and attaches what it needs.
   *
   * @param array<string, mixed> $variables
   *   The html template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocess(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings);
    $show = $this->isShown($settings, $cache);
    // Applied on both outcomes: a hidden page varies by the same facts, and 1.x
    // dropped them there, so a hidden answer could be served to everyone.
    $cache->applyTo($variables);

    $variables['preloader_show'] = $show;
    $variables['preloader_config'] = $show ? $this->config($settings) : [];
    if (!$show) {
      return;
    }

    $variables['#attached']['library'][] = 'solo/solo-preloader';
    $variables['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => [
          'name' => 'solo-preloader-active',
          'content' => 'true',
        ],
      ],
      'solo_preloader_flag',
    ];
    if (($variables['html_attributes'] ?? NULL) instanceof Attribute) {
      $variables['html_attributes']->addClass('solo-preloader-enabled');
    }

    $theme = $settings->themeName();
    $this->assetAttacher->attachPreloaderCriticalCss($variables, $theme);
    // The font loads only for a style that shows text.
    if (in_array($variables['preloader_config']['style'], ['text', 'logo_text'], TRUE)) {
      $this->assetAttacher->attachPreloaderFont($variables, $theme);
    }
  }

  /**
   * Applies force show, then the user, route and path rules.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   Receives a cache context for each fact the answer depends on.
   */
  private function isShown(BoundThemeSettings $settings, CacheableMetadata $cache): bool {
    if ($settings->bool('preloader.force_show')) {
      $cache->addCacheContexts([RequestContext::CACHE_CONTEXT_ROLES, RequestContext::CACHE_CONTEXT_ROUTE]);
      return TRUE;
    }
    if (!$settings->bool('preloader.enabled')) {
      return FALSE;
    }
    if ($settings->bool('preloader.disable_authenticated')) {
      $cache->addCacheContexts([RequestContext::CACHE_CONTEXT_ROLES]);
      if (!$this->requestContext->isAnonymous()) {
        return FALSE;
      }
    }
    if ($settings->bool('preloader.disable_admin_routes')) {
      $cache->addCacheContexts([RequestContext::CACHE_CONTEXT_ROUTE, RequestContext::CACHE_CONTEXT_PATH]);
      if ($this->requestContext->isAdminOrEditorRoute()) {
        return FALSE;
      }
    }
    $rules = $settings->string('preloader.path_rules');
    if ($rules !== '') {
      $cache->addCacheContexts([RequestContext::CACHE_CONTEXT_PATH]);
      if ($this->requestContext->matchesPathRules($rules) === FALSE) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Builds the values the preloader partial reads.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   *
   * @return array<string, mixed>
   *   The preloader_config variable, with 1.x's names, defaults and ranges.
   */
  private function config(BoundThemeSettings $settings): array {
    $force_show = $settings->bool('preloader.force_show');
    $transition = $settings->string('preloader.transition');

    return [
      'style' => $settings->string('preloader.style'),
      'spinner_show_percent' => $settings->bool('preloader.spinner_show_percent'),
      'max_display_seconds' => max(3, min(60, $this->intOr($settings, 'preloader.max_display_seconds', 8))),
      'r_bg' => $settings->string('preloader.colors.background') ?: '#ffffff',
      'r_tx' => $settings->string('preloader.colors.text') ?: '#000000',
      'logo_url' => $this->logoUrl($settings),
      'logo_width' => $this->intOr($settings, 'preloader.logo.width', 160),
      'logo_height' => $this->intOr($settings, 'preloader.logo.height', 80),
      'logo_opacity' => max(0, min(100, $this->intOr($settings, 'preloader.logo.opacity', 100))),
      'text' => $settings->string('preloader.text.value'),
      'text_font' => $settings->string('preloader.text.font'),
      'text_font_size' => $this->intOr($settings, 'preloader.text.font_size', 24),
      'text_animate_effect' => $settings->string('preloader.text.animate_effect'),
      'transition' => in_array($transition, self::TRANSITIONS, TRUE) ? $transition : 'fade',
      // Force show is for testing, so it always hides on DOM ready.
      'hide_on' => $force_show || $settings->string('preloader.hide_on') === 'dom_ready' ? 'dom_ready' : 'load',
      'force_show' => $force_show,
    ];
  }

  /**
   * The theme's logo, or the custom path once it passed the safety check.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function logoUrl(BoundThemeSettings $settings): string {
    if ($settings->bool('preloader.logo.use_theme')) {
      return $settings->string('logo.url');
    }
    return $this->urlSafety->logoUrl($settings->get('preloader.logo.url')) ?? '';
  }

  /**
   * An integer setting where zero or unset means the default, as in 1.x.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   * @param string $key
   *   The setting key.
   * @param int $default
   *   The default value.
   */
  private function intOr(BoundThemeSettings $settings, string $key, int $default): int {
    return $settings->int($key) ?: $default;
  }

}
