<?php

declare(strict_types=1);

namespace Drupal\solo_utilities;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the color_schemes_rule entity type.
 *
 * Each check also accepts the deprecated 1.x permission name, so a role keeps
 * access until drush updatedb moves its grant. The old names go in 3.0.0.
 *
 * @internal
 */
class ColorSchemesRuleAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermissions($account, [
          'view color schemes rules',
          'view Color Schemes Rules',
        ], 'OR');

      case 'update':
        return AccessResult::allowedIfHasPermissions($account, [
          'edit color schemes rules',
          'edit Color Schemes Rules',
        ], 'OR');

      case 'delete':
        return AccessResult::allowedIfHasPermissions($account, [
          'delete color schemes rules',
          'delete Color Schemes Rules',
        ], 'OR');

      case 'administer':
        return AccessResult::allowedIfHasPermissions($account, [
          'administer color schemes rules',
          'administer Color Schemes Rules',
        ], 'OR');
    }

    // Return neutral access result if the operation is not explicitly handled.
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   * @param array<string, mixed> $context
   *   The creation context.
   * @param string|null $entity_bundle
   *   The entity bundle.
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermissions($account, [
      'create color schemes rules',
      'create Color Schemes Rules',
    ], 'OR');
  }

}
