<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Url;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Render\AttributeHelper;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\solo_utilities\Settings\SettingsReader;

/**
 * The template variables of Solo's menus and local tasks.
 *
 * @internal
 */
final class MenuTemplates {

  /**
   * Link classes by the node route a local task points to.
   */
  private const NODE_ROUTE_CLASSES = [
    'entity.node.canonical' => 'view-link',
    'entity.node.edit_form' => 'edit-link',
    'entity.node.delete_form' => 'delete-link',
    'entity.node.version_history' => 'revisions-link',
  ];

  public function __construct(
    private readonly RequestContext $requestContext,
    private readonly AttributeHelper $attributes,
    private readonly BlockBranding $blockBranding,
    private readonly SettingsReader $settingsReader,
  ) {}

  /**
   * Marks a menu chosen for arrow-key navigation.
   *
   * @param array<string, mixed> $variables
   *   The menu template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocessMenu(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings);
    $enabled = array_filter($settings->array('menus.keyboard_navigation'));
    $menu_name = $variables['menu_name'] ?? NULL;
    if ($menu_name && isset($enabled[$menu_name])) {
      $variables['keyboard_enabled'] = TRUE;
    }
    $cache->applyTo($variables);
  }

  /**
   * Adds the primary menu's opening, alignment, mega menu and branding.
   *
   * @param array<string, mixed> $variables
   *   The menu template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocessPrimaryMenu(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings);
    if ($settings->bool('menus.primary.open_on_hover')) {
      $variables['click_hover'] = 'solo-menu--responsive-hover';
      if ($settings->bool('menus.primary.arrow_on_hover')) {
        $variables['remove_arrow'] = 'remove-arrow';
      }
    }
    else {
      $variables['click_hover'] = 'solo-menu--responsive-click';
    }

    $alignment = $settings->get('menus.primary.alignment');
    if ($alignment) {
      $variables['menu_alignment'] = 'solo-menu--align-' . $alignment;
    }

    $variables['expand_left'] = $settings->bool('menus.primary.expand_left') ? 'solo-menu--expand-left' : 'solo-menu--expand-right';

    if ($settings->bool('menus.primary.megamenu.enabled')) {
      $variables['megamenu'] = 'solo-menu-tree--megamenu';
      $variables['sub_mega'] = 'solo-menu-tree__submenu--mega';
      $variables['megamenu_layout'] = $settings->get('menus.primary.megamenu.layout');
      if ($settings->bool('menus.primary.megamenu.submenu_header')) {
        $variables['sub_menu_header'] = 'solo-menu-tree__submenu--header';
      }
    }

    if ($settings->bool('menus.primary.branding')) {
      $variables['primary_menu_branding'] = 'solo-menu--has-branding';
      $variables['site_logo'] = $settings->get('logo.url');
      $variables['logo_default'] = $this->blockBranding->logoDefault($settings);
      $variables['site_name'] = $this->settingsReader->siteName();
      $variables['logo_alt'] = $settings->get('header.logo_alt') ?: 'Home';
      $cache->addCacheTags([SettingsReader::SITE_CACHE_TAG, 'config:system.theme']);
    }
    $cache->applyTo($variables);
  }

  /**
   * Adds the sidebar menu's hover or click class.
   *
   * @param array<string, mixed> $variables
   *   The menu template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocessPrimarySidebarMenu(array &$variables, BoundThemeSettings $settings): void {
    $variables['click_hover_sidebar'] = $settings->bool('menus.primary_sidebar.open_on_hover') ? 'solo-menu--sidebar-hover' : 'solo-menu--sidebar-click';
    CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings)->applyTo($variables);
  }

  /**
   * Swaps the login link for the popup trigger, for anonymous users.
   *
   * @param array<string, mixed> $variables
   *   The menu template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocessAccountMenu(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)
      ->addCacheableDependency($settings)
      ->addCacheContexts([RequestContext::CACHE_CONTEXT_ROLES]);
    $popup_login = $settings->get('popup_login.enabled');
    if ($popup_login && $this->requestContext->isAnonymous()) {
      $variables['login_popup_block'] = $popup_login;
      $variables['login_text'] = $settings->get('popup_login.link_text');
      $variables['current_path'] = $this->requestContext->internalPath();
      $cache->addCacheContexts([RequestContext::CACHE_CONTEXT_PATH]);
      foreach ($variables['items'] ?? [] as $key => $item) {
        $url = $item['url'] ?? NULL;
        if ($url instanceof Url && $url->isRouted() && $url->getRouteName() === 'user.login') {
          unset($variables['items'][$key]);
        }
      }
    }
    $cache->applyTo($variables);
  }

  /**
   * Adds the tab link classes, active state, level and node route class.
   *
   * @param array<string, mixed> $variables
   *   The menu local task template variables.
   */
  public function preprocessLocalTask(array &$variables): void {
    $classes = ['tabs__link', 'js-tabs-link'];
    if (($variables['is_active'] ?? NULL) === TRUE) {
      $classes[] = 'is-active';
    }
    if (isset($variables['element']['#level'])) {
      $variables['level'] = $variables['element']['#level'];
    }
    $url = $variables['element']['#link']['url'] ?? NULL;
    if ($url instanceof Url && $url->isRouted() && isset(self::NODE_ROUTE_CLASSES[$url->getRouteName()])) {
      $classes[] = self::NODE_ROUTE_CLASSES[$url->getRouteName()];
    }
    $variables['link']['#options'] ??= [];
    $this->attributes->addClasses($variables['link']['#options'], $classes);
  }

}
