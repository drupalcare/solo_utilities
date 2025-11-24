<?php

declare(strict_types=1);

namespace Drupal\solo_utilities;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface defining a Color Schemes Rule entity.
 */
interface ColorSchemesRuleInterface extends ConfigEntityInterface {

  /**
   * Gets the library to be loaded.
   *
   * @return string
   *   The name of the library to be loaded.
   */
  public function getLibrary();

  /**
   * Gets the conditions for the library load rule.
   *
   * @return array
   *   The conditions under which the library should be loaded.
   */
  public function getConditions();

  /**
   * Gets the status of the library load rule.
   *
   * @return bool
   *   TRUE if the rule is enabled, FALSE otherwise.
   */
  public function getStatus();

  /**
   * Gets the weight of the library load rule.
   *
   * @return int
   *   The weight of the rule for sorting purposes.
   */
  public function getWeight();

  /**
   * Gets the label of the library load rule.
   *
   * @return string
   *   The label of the rule.
   */
  public function label();

  /**
   * Gets the ID of the library load rule.
   *
   * @return string
   *   The ID of the rule.
   */
  public function id();

}
