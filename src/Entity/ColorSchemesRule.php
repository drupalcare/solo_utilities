<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Entity;

use Drupal\Core\Condition\ConditionPluginCollection;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityWithPluginCollectionInterface;
use Drupal\solo_utilities\ColorSchemesRuleInterface;

/**
 * Defines the Color Schemes Rule entity.
 *
 * @ConfigEntityType(
 *   id = "color_schemes_rule",
 *   label = @Translation("Color Schemes Rule"),
 *   module = "solo_utilities",
 *   handlers = {
 *     "access" = "Drupal\solo_utilities\ColorSchemesRuleAccessControlHandler",
 *     "list_builder" = "Drupal\solo_utilities\ColorSchemesRuleListBuilder",
 *     "form" = {
 *       "add" = "Drupal\solo_utilities\Form\ColorSchemesRuleForm",
 *       "edit" = "Drupal\solo_utilities\Form\ColorSchemesRuleForm",
 *       "delete" = "Drupal\solo_utilities\Form\ColorSchemesRuleDeleteForm"
 *     }
 *   },
 *   config_prefix = "rule",
 *   admin_permission = "administer site configuration",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "status" = "status",
 *     "weight" = "weight"
 *   },
 *   config_export = {
 *     "uuid",
 *     "id",
 *     "label",
 *     "weight",
 *     "status",
 *     "library",
 *     "conditions",
 *     "theme_category",
 *     "predefined_theme",
 *     "conjunction",
 *   },
 *   links = {
 *     "collection" = "/admin/config/solo_utilities/color-schemes-rules",
 *     "add-form" = "/admin/config/solo_utilities/color-schemes-rules/add",
 *     "edit-form" = "/admin/config/solo_utilities/color-schemes-rules/edit/{color_schemes_rule}",
 *     "delete-form" = "/admin/config/solo_utilities/color-schemes-rules/delete/{color_schemes_rule}"
 *   }
 * )
 */
class ColorSchemesRule extends ConfigEntityBase implements ColorSchemesRuleInterface, EntityWithPluginCollectionInterface {

  /**
   * The ID of the library load rule entity.
   *
   * @var string
   */
  protected $id;

  /**
   * The UUID of the library load rule entity.
   *
   * @var string
   */
  protected $uuid;

  /**
   * The label of the library load rule entity.
   *
   * @var string
   */
  protected $label;

  /**
   * The status of the library load rule entity.
   *
   * @var bool
   */
  protected $status;

  /**
   * The weight of the library load rule entity.
   *
   * @var int
   */
  protected $weight;

  /**
   * The library to be loaded.
   *
   * @var string
   */
  protected $library;

  /**
   * The conditions for the library load rule.
   *
   * @var array
   */
  protected $conditions = [];

  /**
   * The theme category for this rule.
   *
   * @var string
   */
  protected $theme_category;

  /**
   * The predefined theme for this rule.
   *
   * @var string
   */
  protected $predefined_theme;

  /**
   * The conjunction operator for the conditions (AND/OR).
   *
   * @var string
   */
  protected $conjunction = 'and';

  /**
   * The visibility collection.
   *
   * @var \Drupal\Core\Condition\ConditionPluginCollection
   */
  protected $visibilityCollection;

  /**
   * The condition plugin manager.
   *
   * @var \Drupal\Core\Executable\ExecutableManagerInterface
   */
  protected $conditionPluginManager;

  /**
   * {@inheritdoc}
   */
  public function getLibrary() {
    return $this->library;
  }

  /**
   * {@inheritdoc}
   */
  public function getConditions() {
    return $this->getVisibilityConditions()->getConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus() {
    return $this->status;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->weight;
  }

  /**
   * {@inheritdoc}
   */
  public function getConjunction() {
    return $this->conjunction;
  }

  /**
   * {@inheritdoc}
   */
  public function getThemeCategory() {
    return $this->theme_category;
  }

  /**
   * {@inheritdoc}
   */
  public function getPredefinedTheme() {
    return $this->predefined_theme;
  }

  /**
   * Sets the theme category for the rule.
   *
   * @param string $theme_category
   *   The theme category.
   */
  public function setThemeCategory($theme_category) {
    $this->theme_category = $theme_category;
  }

  /**
   * Sets the predefined theme for the rule.
   *
   * @param string $predefined_theme
   *   The predefined theme.
   */
  public function setPredefinedTheme($predefined_theme) {
    $this->predefined_theme = $predefined_theme;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return ['conditions' => $this->getVisibilityConditions()];
  }

  /**
   * {@inheritdoc}
   */
  public function getVisibility() {
    return $this->getVisibilityConditions()->getConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getVisibilityConditions() {
    if (!isset($this->visibilityCollection)) {
      $this->visibilityCollection = new ConditionPluginCollection(
        $this->conditionPluginManager(),
        $this->get('conditions')
      );
    }
    return $this->visibilityCollection;
  }

  /**
   * Gets the selected theme from the conditions.
   *
   * @return string|null
   *   The selected theme machine name, or NULL if not set.
   */
  public function getSelectedTheme() {
    // Retrieve the conditions from the visibility collection.
    $conditions = $this->getVisibilityConditions();
    foreach ($conditions as $condition) {
      if ($condition->getPluginId() === 'current_theme') {
        $configuration = $condition->getConfiguration();
        return $configuration['theme'] ?? NULL;
      }
    }
    return NULL;
  }

  /**
   * Gets the condition plugin manager.
   *
   * @return \Drupal\Core\Executable\ExecutableManagerInterface
   *   The condition plugin manager.
   */
  protected function conditionPluginManager() {
    if (!isset($this->conditionPluginManager)) {
      $this->conditionPluginManager = \Drupal::service('plugin.manager.condition');
    }
    return $this->conditionPluginManager;
  }

}
