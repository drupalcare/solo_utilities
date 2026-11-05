<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\block\BlockInterface;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;

/**
 * Prepares block templates: logo, logo text, menu region and page title flag.
 *
 * @internal
 */
final class BlockBranding {

  public function __construct(
    private readonly RequestContext $requestContext,
    private readonly SoloThemeDetectionInterface $themeDetection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Fills the block template variables.
   *
   * @param array<string, mixed> $variables
   *   The block template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocess(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings);
    if (($variables['plugin_id'] ?? NULL) === 'page_title_block') {
      $variables['has_page_title'] = !empty($this->requestContext->pageTitle());
      $cache->addCacheContexts([RequestContext::CACHE_CONTEXT_ROUTE]);
    }

    // A block placed by Layout Builder has no id; an id can also be an array.
    $id = $variables['elements']['#id'] ?? NULL;
    $block = is_string($id) && $id !== '' ? $this->entityTypeManager->getStorage('block')->load($id) : NULL;
    if ($block instanceof BlockInterface) {
      if (($variables['base_plugin_id'] ?? NULL) === 'system_menu_block') {
        $variables['content']['#attributes']['region'] = $block->getRegion();
      }
      $variables['logo_default'] = $this->logoDefault($settings);
      $variables['logo_alt'] = $settings->get('header.logo_alt') ?: 'Home';
      $cache->addCacheTags(['config:system.theme']);
    }
    $cache->applyTo($variables);
  }

  /**
   * Whether the shipped logo is drawn: only by Solo itself as the default theme.
   *
   * A sub-theme never gets it. The caller must carry config:system.theme.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   *
   * @return int
   *   1 or 0, as the templates compare it with 1.
   */
  public function logoDefault(BoundThemeSettings $settings): int {
    $is_solo_default = $settings->themeName() === 'solo' && $this->themeDetection->defaultThemeName() === 'solo';
    return $is_solo_default && $settings->get('logo.use_default') == 1 ? 1 : 0;
  }

}
