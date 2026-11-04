<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Access;

use Drupal\Core\Access\AccessCheckInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\solo_utilities\Theme\SoloThemeDetection;
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
   * @param \Drupal\solo_utilities\Theme\SoloThemeDetection $themeDetection
   *   The Solo theme detection.
   */
  public function __construct(protected SoloThemeDetection $themeDetection) {
  }

  /**
   * Checks access based on whether Solo or its sub-themes are enabled.
   *
   * @param \Symfony\Component\Routing\Route $route
   *   The route object.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResult
   *   The access result.
   */
  public function access(Route $route, AccountInterface $account) {
    // Check if the Solo theme or its sub-themes are enabled.
    if (!$this->isSoloOrSubThemeEnabled()) {
      // \Drupal::logger('solo_utilities')->notice('Access denied:
      // Solo or sub-theme is not enabled.');
      return AccessResult::forbidden();
    }

    // Check for user permissions.
    if ($account->hasPermission('administer Color Schemes Rules') ||
        $account->hasPermission('view Color Schemes Rules') ||
        $account->hasPermission('edit Color Schemes Rules') ||
        $account->hasPermission('delete Color Schemes Rules')) {
      return AccessResult::allowed();
    }

    // \Drupal::logger('solo_utilities')->notice('Access denied: No sufficient
    // permissions for user @user.', ['@user' => $account->getDisplayName()]);
    return AccessResult::forbidden();
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
