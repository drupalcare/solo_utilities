<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Access;

use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides an access check for the Solo Utilities admin route.
 *
 * @internal
 */
class ColorSchemesAccessCheck implements AccessCheckInterface {

  /**
   * Constructs the access check.
   *
   * @param \Drupal\solo_utilities\Theme\SoloThemeDetectionInterface $themeDetection
   *   The Solo theme detection.
   */
  public function __construct(protected SoloThemeDetectionInterface $themeDetection) {
  }

  /**
   * Checks access based on whether Solo or its sub-themes are enabled.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route object.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result, varying by the default theme and, once that is Solo,
   *   by the account's permissions.
   */
  public function access(Route $route, AccountInterface $account): AccessResultInterface {
    // The answer depends on system.theme:default and on which themes are
    // installed (the Solo hierarchy is read from installed themes).
    $theme_dependency = (new CacheableMetadata())
      ->addCacheTags(['config:system.theme', 'config:core.extension']);

    if (!$this->isSoloOrSubThemeEnabled()) {
      return AccessResult::forbidden()->addCacheableDependency($theme_dependency);
    }

    $permissions = AccessResult::allowedIfHasPermissions($account, [
      'administer color schemes rules',
      'view color schemes rules',
      'edit color schemes rules',
      'delete color schemes rules',
      // Deprecated 1.x names, accepted until drush updatedb moves the grants.
      'administer Color Schemes Rules',
      'view Color Schemes Rules',
      'edit Color Schemes Rules',
      'delete Color Schemes Rules',
    ], 'OR');
    if ($permissions->isAllowed()) {
      return $permissions->addCacheableDependency($theme_dependency);
    }

    return AccessResult::forbidden()
      ->cachePerPermissions()
      ->addCacheableDependency($theme_dependency);
  }

  /**
   * Determines if the access check applies to a given route.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route object.
   *
   * @return bool
   *   TRUE if the access check should be applied, FALSE otherwise.
   */
  public function applies(Route $route) {
    $path = $route->getPath();
    // Apply this check to both overview and color-schemes-rules paths.
    return strpos($path, '/admin/config/system/solo_utilities') === 0 ||
           strpos($path, '/admin/config/solo_utilities/color-schemes-rules') === 0;
  }

  /**
   * Helper function to check if Solo or its sub-themes are enabled.
   *
   * @return bool
   *   TRUE if Solo or its sub-themes are enabled, FALSE otherwise.
   */
  protected function isSoloOrSubThemeEnabled() {
    // Theme scope: default. These routes render under the ADMIN theme, so the
    // active theme is never Solo here; asking isActive() would lock every
    // user out of the Color Schemes admin pages.
    return $this->themeDetection->isDefault();
  }

}
