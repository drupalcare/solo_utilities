<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Template\Attribute;
use Drupal\node\NodeInterface;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Layout\LayoutResolver;
use Drupal\solo_utilities\Security\CssValueValidator;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\solo_utilities\Settings\SettingsCatalog;
use Drupal\solo_utilities\Settings\SettingsReader;

/**
 * Builds Solo's variables and attachments for the page template.
 *
 * Twig variable names keep their 1.x spelling; SettingsCatalog says where each
 * one is read from.
 *
 * @internal
 */
final class PagePreprocessor {

  /**
   * The paths that get the centered login layout.
   */
  private const LOGIN_PATHS = ['user/login', 'user/register', 'user/password'];

  public function __construct(
    private readonly SettingsCatalog $catalog,
    private readonly SettingsReader $settingsReader,
    private readonly RequestContext $requestContext,
    private readonly LayoutResolver $layoutResolver,
    private readonly BlockBranding $blockBranding,
    private readonly CssValueValidator $cssValueValidator,
    private readonly HtmlPreprocessor $htmlPreprocessor,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Fills the page template variables.
   *
   * @param array<string, mixed> $variables
   *   The page template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocess(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)
      ->addCacheableDependency($settings)
      ->addCacheContexts([RequestContext::CACHE_CONTEXT_ROUTE, RequestContext::CACHE_CONTEXT_PATH]);
    $route_node = $this->requestContext->routeEntity('node');
    $node = $route_node instanceof NodeInterface ? $route_node : NULL;
    if ($node !== NULL) {
      $cache->addCacheableDependency($node);
    }

    foreach ($this->catalog->templateVariables() as $name => $path) {
      $variables[$name] = $path === NULL ? NULL : $settings->get($path);
    }
    $this->addFooterCopyright($variables, $settings);
    $this->attachPageLibraries($variables, $settings);
    $this->addSizeClasses($variables, $settings);
    $this->addSidebarBranding($variables, $settings, $cache);
    $variables['enabled_skip_links'] = $this->htmlPreprocessor->enabledSkipLinks($settings);
    $this->addLayouts($variables, $settings, $node);
    $this->applyLoginLayout($variables, $settings);
    foreach ($this->catalog->regions() as $region) {
      // Region colors live in the generated stylesheet. Templates still print
      // these, and sub-themes may add to them.
      $variables['attributes_' . $region] = new Attribute();
    }
    $variables['site_width_class'] = $this->layoutResolver->widthClass($settings, $node);
    $this->attachPopupLogin($variables, $settings);

    $cache->applyTo($variables);
  }

  /**
   * Renders the formatted copyright when the setting asks for it.
   *
   * @param array<string, mixed> $variables
   *   The page template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function addFooterCopyright(array &$variables, BoundThemeSettings $settings): void {
    $variables['footer_copyright_formatted_rendered'] = '';
    if (empty($variables['footer_use_formatted_text'])) {
      return;
    }
    $formatted = $settings->array('footer.copyright_formatted');
    if (empty($formatted['value'])) {
      return;
    }
    $year = $this->dateFormatter->format($this->time->getRequestTime(), 'custom', 'Y');
    $variables['footer_copyright_formatted_rendered'] = [
      '#type' => 'processed_text',
      '#text' => str_replace('%year%', $year, (string) $formatted['value']),
      '#format' => $formatted['format'] ?? 'basic_html',
    ];
  }

  /**
   * Attaches the libraries and settings the page template's features need.
   *
   * @param array<string, mixed> $variables
   *   The page template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function attachPageLibraries(array &$variables, BoundThemeSettings $settings): void {
    $keyboard_menus = array_values(array_filter($settings->array('menus.keyboard_navigation')));
    if ($settings->array('menus.keyboard_navigation') !== []) {
      $variables['#attached']['drupalSettings']['solo']['keyboardMenus'] = $keyboard_menus;
    }
    if ($keyboard_menus !== []) {
      $variables['#attached']['library'][] = 'solo/solo-menu-keyboard';
    }
    $page_breakpoint = $settings->string('layout.breakpoints.page');
    if ($page_breakpoint !== '') {
      $variables['#attached']['library'][] = 'solo/solo-global-breakpoints-' . $page_breakpoint;
    }
    $menu_breakpoint = $settings->string('layout.breakpoints.menu');
    if ($menu_breakpoint !== '') {
      $variables['#attached']['library'][] = 'solo/solo-menu-breakpoints-' . $menu_breakpoint;
    }
    if (!$settings->bool('regions.system_messages.disable_dark')) {
      $variables['#attached']['library'][] = 'solo/solo-system-messages-dark';
    }
  }

  /**
   * Adds the classes derived from size and order settings.
   *
   * @param array<string, mixed> $variables
   *   The page template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function addSizeClasses(array &$variables, BoundThemeSettings $settings): void {
    $layout_order = $settings->get('layout.mobile_order');
    if ($layout_order && is_scalar($layout_order)) {
      $variables['main_layout_order'] = str_replace('_', '-', (string) $layout_order);
    }
    $sizes = [
      'name_size_s' => ['name-s-', 'header.site_name.font_size_small'],
      'name_size_l' => ['name-l-', 'header.site_name.font_size_large'],
      'title_size_s' => ['title-s-', 'page_title.font_size_small'],
      'title_size_l' => ['title-l-', 'page_title.font_size_large'],
    ];
    foreach ($sizes as $name => [$prefix, $key]) {
      $size = $settings->get($key);
      if ($size && is_scalar($size)) {
        $variables[$name] = $prefix . $size;
      }
    }
    if ($settings->bool('page_title.centered')) {
      $variables['site_pagetitle_center'] = 'pt-center';
    }
  }

  /**
   * Adds the logo and site name the sidebar menu's branding shows.
   *
   * @param array<string, mixed> $variables
   *   The page template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   The page's cache metadata.
   */
  private function addSidebarBranding(array &$variables, BoundThemeSettings $settings, CacheableMetadata $cache): void {
    if (!$settings->bool('menus.primary_sidebar.branding')) {
      return;
    }
    $variables['site_logo'] = $settings->get('logo.url');
    $variables['logo_default'] = $this->blockBranding->logoDefault($settings);
    $variables['site_name'] = $this->settingsReader->siteName();
    $variables['logo_alt'] = $settings->get('header.logo_alt') ?: 'Home';
    $cache->addCacheTags([SettingsReader::SITE_CACHE_TAG, 'config:system.theme']);
  }

  /**
   * Adds the layout class and region count of each region group.
   *
   * @param array<string, mixed> $variables
   *   The page template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   * @param \Drupal\node\NodeInterface|null $node
   *   The node the page shows, if any.
   */
  private function addLayouts(array &$variables, BoundThemeSettings $settings, ?NodeInterface $node): void {
    $page = is_array($variables['page'] ?? NULL) ? $variables['page'] : [];
    foreach ($this->layoutResolver->groups() as $group) {
      $variables['count_' . $group] = $this->layoutResolver->filledCount($group, $page);
      $variables[$group . '_layout'] = $this->layoutResolver->columnLayout($settings, $group, $page, $node?->bundle());
    }
  }

  /**
   * Hides the sidebars on login, register and password pages when asked.
   *
   * @param array<string, mixed> $variables
   *   The page template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function applyLoginLayout(array &$variables, BoundThemeSettings $settings): void {
    if ($settings->bool('layout.centered_login_page') && in_array(ltrim($this->requestContext->internalPath(), '/'), self::LOGIN_PATHS, TRUE)) {
      unset($variables['page']['sidebar_first'], $variables['page']['sidebar_second']);
      $variables['#attached']['library'][] = 'solo/solo-user-login-custom';
    }
  }

  /**
   * Passes the popup login's behaviour to its script.
   *
   * @param array<string, mixed> $variables
   *   The page template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function attachPopupLogin(array &$variables, BoundThemeSettings $settings): void {
    if (!$settings->bool('popup_login.enabled')) {
      return;
    }
    $variables['#attached']['drupalSettings']['solo']['popupLogin'] = [
      'enabled' => TRUE,
      'useInlineStyles' => $settings->bool('popup_login.use_inline_styles'),
      'animationDuration' => $settings->int('popup_login.animation_duration') ?: 300,
      'closeOnEscape' => $settings->bool('popup_login.close_on_escape'),
      'closeOnOutsideClick' => $settings->bool('popup_login.close_on_outside_click'),
      'focusTrap' => $settings->bool('popup_login.focus_trap'),
      'announceToScreenReaders' => $settings->bool('popup_login.announce_to_screen_readers'),
      'returnFocusOnClose' => $settings->bool('popup_login.return_focus_on_close'),
      'customTriggers' => $settings->string('popup_login.custom_triggers'),
      'zIndex' => $settings->int('popup_login.z_index') ?: 10000,
      'overlayOpacity' => $settings->int('popup_login.overlay_opacity') ?: 50,
      'backgroundColorRgb' => $this->cssValueValidator->rgbTriplet($settings->get('regions.popup_login_block.colors.background')) ?? '',
    ];
  }

}
