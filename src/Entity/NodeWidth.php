<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the NodeWidth entity.
 *
 * The definition is declared twice ON PURPOSE, and the two must agree. Core
 * that ships the entity-type attribute classes reads #[ContentEntityType] and
 * ignores this annotation. Drupal 10 ships none (checked on 10.6.18) and
 * discovers entity types from the annotation only: without it this entity
 * type does not exist there, which is the 1.0.7 PluginNotFoundException.
 * EntityTypeDefinitionTest fails if either copy goes missing or the two
 * drift apart.
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
#[ContentEntityType(
  id: 'node_width',
  label: new TranslatableMarkup('Node Width'),
  base_table: 'solo_theme_node_width',
  entity_keys: [
    'id' => 'id',
    'label' => 'id',
  ],
)]
class NodeWidth extends ContentEntityBase {

  /**
   * Store only customized nodes.
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
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
