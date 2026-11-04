<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\node\NodeInterface;
use Drupal\solo_utilities\Render\AttributeHelper;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * Solo's search: result teasers, the search page form and the search block.
 *
 * @internal
 */
final class SearchResult {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AttributeHelper $attributes,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Renders a result's teaser when teaser mode is on.
   *
   * @param array<string, mixed> $variables
   *   The search result template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocess(array &$variables, BoundThemeSettings $settings): void {
    $node = $variables['result']['node'] ?? NULL;
    if ($settings->bool('search.teaser_mode') && $node instanceof NodeInterface) {
      $variables['search_teaser'] = $this->entityTypeManager->getViewBuilder('node')->view($node, 'teaser');
    }
    CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings)->applyTo($variables);
  }

  /**
   * Decorates the search page form and hides the filters the settings disable.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function alterSearchForm(array &$form, BoundThemeSettings $settings): void {
    CacheableMetadata::createFromRenderArray($form)->addCacheableDependency($settings)->applyTo($form);
    unset($form['help_link']);
    if (!empty($form['actions']['submit']) && count($form['actions']) <= 2) {
      $this->attributes->addClasses($form['actions']['submit'], 'button--primary', '#attributes');
    }
    if (isset($form['keys'])) {
      $form['keys']['#attributes']['placeholder'] = $this->t('Search by keyword or phrase.');
    }
    if (isset($form['basic']['submit'])) {
      $this->attributes->addClasses($form['basic']['submit'], 'button--primary', '#attributes');
    }
    if (isset($form['advanced']['submit'])) {
      $this->attributes->addClasses($form['advanced']['submit'], 'button--primary', '#attributes');
    }

    $disabled = $settings->array('search.disabled_filters');
    if (array_diff(['disable_keywords', 'disable_types', 'disable_language'], $disabled) === []) {
      unset($form['advanced']);
      return;
    }
    if (in_array('disable_keywords', $disabled, TRUE)) {
      unset($form['advanced']['keywords-fieldset']);
    }
    if (in_array('disable_language', $disabled, TRUE)) {
      unset($form['advanced']['lang-fieldset']);
    }
    if (isset($form['advanced']['types-fieldset']) && in_array('disable_types', $disabled, TRUE)) {
      unset($form['advanced']['types-fieldset']);
      $content_types = array_filter($settings->array('search.result_content_types'));
      if ($content_types !== []) {
        $form['advanced']['hidden_content_types'] = [
          '#type' => 'value',
          '#value' => $content_types,
        ];
        $form['#validate'][] = [self::class, 'applyHiddenContentTypes'];
      }
    }
  }

  /**
   * Decorates the search block and hands its content types to the behaviour.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function alterSearchBlockForm(array &$form, BoundThemeSettings $settings): void {
    CacheableMetadata::createFromRenderArray($form)->addCacheableDependency($settings)->applyTo($form);
    $form['keys']['#attributes']['placeholder'] = $this->t('Search by keyword or phrase.');
    $form['actions']['submit'] ??= [];
    $this->attributes->addClasses($form['actions']['submit'], 'solo-fixed-search__button-block', '#attributes');
    $form['#attached']['drupalSettings']['solo'] = [
      'searchContentTypes' => array_filter($settings->array('search.result_content_types')),
      'searchBasePath' => '/search/node',
    ];
    $form['#attached']['library'][] = 'solo/solo-search-settings';
  }

  /**
   * Applies the hidden content types to the search query.
   *
   * Static, as this runs from a form's #validate list.
   *
   * @param array<mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function applyHiddenContentTypes(array &$form, FormStateInterface $form_state): void {
    $hidden = $form['advanced']['hidden_content_types']['#value'] ?? [];
    if (!empty($hidden)) {
      $form_state->setValue('type', array_merge($form_state->getValue('type') ?? [], $hidden));
    }
  }

}
