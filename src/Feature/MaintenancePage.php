<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

/**
 * Prepares the maintenance page.
 *
 * Its CSS comes from Solo's global library. The library name 1.x attached here,
 * solo/templates/layout/maintenance_page, is defined nowhere, so it is not
 * carried over.
 *
 * @internal
 */
final class MaintenancePage {

  /**
   * Blanks the site name when no database is available.
   *
   * Core then says "Drupal", which does not belong on a site or update page.
   *
   * @param array<string, mixed> $variables
   *   The maintenance page template variables.
   */
  public function preprocess(array &$variables): void {
    if (empty($variables['db_is_active'])) {
      $variables['site_name'] = '';
    }
  }

}
