<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\solo_utilities\Render\AttributeHelper;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * The template variables of nodes, fields and captions.
 *
 * @internal
 */
final class ContentPreprocessor {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly AttributeHelper $attributes,
  ) {}

  /**
   * Applies the date format, comment link rule and reading mode to a node.
   *
   * @param array<string, mixed> $variables
   *   The node template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocessNode(array &$variables, BoundThemeSettings $settings): void {
    $format = $settings->get('typography.node_date_format');
    // The value "none" keeps Drupal's own date.
    if (is_string($format) && $format !== 'none' && !empty($variables['date']) && ($variables['display_submitted'] ?? NULL) === TRUE) {
      $variables['date'] = $this->dateFormatter->format($variables['node']->getCreatedTime(), $format);
    }
    // Drupal 12 no longer sets a teaser variable, so read the view mode.
    if (($variables['view_mode'] ?? '') === 'teaser' || !empty($variables['content']['comments']['comment_form'])) {
      unset($variables['content']['links']['comment']['#links']['comment-add']);
    }
    if (in_array($variables['node']->getType(), $settings->array('typography.reading_mode_content_types'), TRUE)) {
      $this->attributes->addClasses($variables, 'solo-reading-mode');
    }
    CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings)->applyTo($variables);
  }

  /**
   * Names the referenced entity type on an entity reference field.
   *
   * @param array<string, mixed> $variables
   *   The field template variables.
   */
  public function preprocessField(array &$variables): void {
    if (($variables['element']['#field_type'] ?? NULL) !== 'entity_reference' || empty($variables['element']['#items'])) {
      return;
    }
    $entity = $variables['element']['#items']->first()?->entity;
    if ($entity) {
      $this->attributes->addClasses($variables, Html::getClass($entity->getEntityTypeId() . '-entity'));
    }
  }

  /**
   * Removes the prefix from the items of a node's created field.
   *
   * @param array<string, mixed> $variables
   *   The field template variables.
   */
  public function preprocessFieldNodeCreated(array &$variables): void {
    foreach (array_keys($variables['items'] ?? []) as $key) {
      unset($variables['items'][$key]['content']['#prefix']);
    }
  }

  /**
   * Adds the caption class.
   *
   * @param array<string, mixed> $variables
   *   The filter caption template variables.
   */
  public function preprocessFilterCaption(array &$variables): void {
    $variables['classes'] = ($variables['classes'] ?? '') . ' caption';
  }

}
