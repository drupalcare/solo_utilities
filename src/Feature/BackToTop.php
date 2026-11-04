<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * Decides whether this request shows the back to top button, and prepares it.
 *
 * The sentinel height and the colors are rules in the generated stylesheet,
 * built by ThemeCssBuilder, so Twig prints no CSS.
 *
 * @internal
 */
final class BackToTop {

  /**
   * Positions; the first is used for anything else stored.
   */
  private const POSITIONS = ['bottom-right', 'bottom-left'];

  /**
   * Styles; the first is used for anything else stored.
   */
  private const STYLES = ['solid', 'outline'];

  /**
   * Icons; the first is used for anything else stored.
   */
  private const ICONS = ['arrow-up', 'chevron-up', 'arrow-minimal'];

  public function __construct(
    private readonly RequestContext $requestContext,
  ) {}

  /**
   * Fills back_to_top_show and back_to_top_config, and attaches the library.
   *
   * @param array<string, mixed> $variables
   *   The html template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocess(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings);
    $show = $this->isShown($settings, $cache);
    $cache->applyTo($variables);

    $variables['back_to_top_show'] = $show;
    $variables['back_to_top_config'] = $show ? $this->config($settings) : [];
    if ($show) {
      $variables['#attached']['library'][] = 'solo/solo-back-to-top';
    }
  }

  /**
   * Applies the on or off switch, then the route and user rules.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   Receives a cache context for each fact the answer depends on.
   */
  private function isShown(BoundThemeSettings $settings, CacheableMetadata $cache): bool {
    if (!$settings->bool('back_to_top.enabled')) {
      return FALSE;
    }
    if ($settings->bool('back_to_top.disable_admin_routes')) {
      $cache->addCacheContexts([RequestContext::CACHE_CONTEXT_ROUTE, RequestContext::CACHE_CONTEXT_PATH]);
      if ($this->requestContext->isAdminOrEditorRoute()) {
        return FALSE;
      }
    }
    if ($settings->bool('back_to_top.disable_authenticated')) {
      $cache->addCacheContexts([RequestContext::CACHE_CONTEXT_ROLES]);
      if (!$this->requestContext->isAnonymous()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Builds the values the back to top partial reads.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   *
   * @return array<string, mixed>
   *   The back_to_top_config variable, with 1.x's names and ranges.
   */
  private function config(BoundThemeSettings $settings): array {
    return [
      'scroll_threshold' => max(200, min(800, $settings->int('back_to_top.scroll_threshold', 400))),
      'position' => $this->allowed($settings->string('back_to_top.position'), self::POSITIONS),
      'style' => $this->allowed($settings->string('back_to_top.style'), self::STYLES),
      'icon' => $this->allowed($settings->string('back_to_top.icon'), self::ICONS),
      'hide_small_screens' => $settings->bool('back_to_top.hide_small_screens'),
    ];
  }

  /**
   * The value when it is listed, otherwise the first listed one.
   *
   * @param string $value
   *   The stored value.
   * @param list<string> $allowed
   *   The accepted values; the first is the default.
   */
  private function allowed(string $value, array $allowed): string {
    return in_array($value, $allowed, TRUE) ? $value : $allowed[0];
  }

}
