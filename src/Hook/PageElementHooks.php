<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\solo_utilities\Feature\Breadcrumb;
use Drupal\solo_utilities\Feature\MaintenancePage;
use Drupal\solo_utilities\Settings\SettingsReader;
use Drupal\solo_utilities\Theme\SoloThemeDetection;

/**
 * Routes the breadcrumb and maintenance page hooks to their features.
 *
 * The preloader and back to top run from preprocess_html in PreprocessHooks,
 * and the block feature from preprocess_block in SiteFeatureHooks, because
 * each hook has one method in the whole module.
 *
 * @internal
 */
final class PageElementHooks {

  public function __construct(
    private readonly SoloThemeDetection $detection,
    private readonly SettingsReader $settingsReader,
    private readonly Breadcrumb $breadcrumb,
    private readonly MaintenancePage $maintenancePage,
  ) {}

  /**
   * Implements hook_preprocess_HOOK() for breadcrumb.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_breadcrumb', order: Order::Last)]
  public function preprocessBreadcrumb(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->breadcrumb->preprocess($variables, $this->settingsReader->forTheme($this->detection->activeThemeName()));
    }
  }

  /**
   * Implements hook_preprocess_HOOK() for maintenance-page.html.twig.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  #[Hook('preprocess_maintenance_page', order: Order::Last)]
  public function preprocessMaintenancePage(array &$variables): void {
    if ($this->detection->isActive()) {
      $this->maintenancePage->preprocess($variables);
    }
  }

}
