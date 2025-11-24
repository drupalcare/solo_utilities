<?php

declare(strict_types=1);

namespace Drupal\solo_utilities;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the color_schemes_rule entity type.
 */
class ColorSchemesRuleAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermission($account, 'view Color Schemes Rules');

      case 'update':
        return AccessResult::allowedIfHasPermission($account, 'edit Color Schemes Rules');

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'delete Color Schemes Rules');

      case 'administer':
        return AccessResult::allowedIfHasPermission($account, 'administer Color Schemes Rules');
    }

    // Return neutral access result if the operation is not explicitly handled.
    return AccessResult::neutral();
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return AccessResult::allowedIfHasPermission($account, 'create Color Schemes Rules');
  }

}
