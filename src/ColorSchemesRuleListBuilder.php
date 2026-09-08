<?php

declare(strict_types=1);

namespace Drupal\solo_utilities;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Provides a listing of Color Schemes Rules.
 */
class ColorSchemesRuleListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function render() {
    // Attach the "pre-defined" library when rendering the list.
    $build = parent::render();
    $attachments['#attached']['library'][] = 'core/drupalSettings';
    $build['#attached']['library'][] = 'solo_utilities/pre-defined';
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    $header['label'] = $this->t('Label');
    $header['status'] = $this->t('Status');
    $header['weight'] = $this->t('Weight');
    $header['selected_theme'] = $this->t('Selected Theme');
    $header['theme_category'] = $this->t('Theme Category');
    $header['predefined_theme'] = $this->t('Predefined Theme');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    /** @var \Drupal\solo_utilities\Entity\ColorSchemesRule $entity */
    $row['label'] = $entity->label();
    $row['status'] = $entity->getStatus() ? $this->t('Enabled') : $this->t('Disabled');
    $row['weight'] = $entity->get('weight') ?: 0;

    $selected_theme = $entity->getSelectedTheme();
    $row['selected_theme'] = $selected_theme ? $this->humanize($selected_theme) : $this->t('None');

    // Convert theme category to human-readable format.
    $theme_category = $entity->get('theme_category');
    $row['theme_category'] = $theme_category ? $this->humanize($theme_category) : $this->t('None');

    // Convert predefined theme to human-readable format and remove 'solo-'.
    $predefined_theme = $entity->get('predefined_theme');
    if ($predefined_theme) {
      $predefined_theme = str_replace('solo-', '', $predefined_theme);
      $row['predefined_theme'] = $this->humanize($predefined_theme);
    }
    else {
      $row['predefined_theme'] = $this->t('None');
    }

    return $row + parent::buildRow($entity);
  }

  /**
   * Helper function to convert machine names to human-readable format.
   *
   * @param string $value
   *   The machine name.
   *
   * @return string
   *   The human-readable name.
   */
  protected function humanize($value) {
    return ucwords(str_replace(['_', '-'], ' ', $value));
  }

  /**
   * {@inheritdoc}
   */
  public function getOperations(EntityInterface $entity, ?CacheableMetadata $cacheability = NULL) {
    $operations = parent::getOperations($entity, $cacheability);

    // Add enable/disable operation.
    if ($entity->getStatus()) {
      $operations['disable'] = [
        'title' => $this->t('Disable'),
        'url' => Url::fromRoute('solo_utilities.ajax_operation', [
          'op' => 'disable',
          'color_schemes_rule' => $entity->id(),
        ]),
      ];
    }
    else {
      $operations['enable'] = [
        'title' => $this->t('Enable'),
        'url' => Url::fromRoute('solo_utilities.ajax_operation', [
          'op' => 'enable',
          'color_schemes_rule' => $entity->id(),
        ]),
      ];
    }

    return $operations;
  }

}
