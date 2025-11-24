<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the NodeWidth entity.
 *
 * @ContentEntityType(
 *   id = "node_width",
 *   label = @Translation("Node Width"),
 *   base_table = "solo_theme_node_width",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "id",
 *   },
 * )
 */
class NodeWidth extends ContentEntityBase {

  /**
   * Store only customized nodes.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['node_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Node ID'))
      ->setRequired(TRUE);

    $fields['width_class'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Width Class'))
      ->setRequired(TRUE);

    return $fields;
  }

}
