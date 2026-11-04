<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\block_content\BlockContentInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeTypeInterface;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * Solo's theme suggestions.
 *
 * @internal
 */
final class Suggestions {

  public function __construct(
    private readonly RequestContext $requestContext,
  ) {}

  /**
   * Suggests the search block form template.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  public function form(array &$suggestions, array $variables): void {
    if (($variables['element']['#form_id'] ?? NULL) === 'search_block_form') {
      $suggestions[] = 'form__search_block_form';
    }
  }

  /**
   * Suggests a block template by block content type.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  public function block(array &$suggestions, array $variables): void {
    $block_content = $variables['elements']['content']['#block_content'] ?? NULL;
    if ($block_content instanceof BlockContentInterface) {
      array_splice($suggestions, 1, 0, 'block__type__' . $block_content->bundle());
    }
  }

  /**
   * Suggests menu templates: an assigned template beats the region's.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function menu(array &$suggestions, array $variables, BoundThemeSettings $settings): void {
    $region_suggestions = $this->regionMenuSuggestions($variables);
    $assigned = $this->assignedMenuSuggestion($variables, $settings);
    if ($assigned !== NULL) {
      $suggestions = array_values(array_diff($suggestions, $region_suggestions, [$assigned]));
      array_unshift($suggestions, $assigned);
      return;
    }
    foreach (array_reverse($region_suggestions) as $suggestion) {
      if (!in_array($suggestion, $suggestions, TRUE)) {
        array_unshift($suggestions, $suggestion);
      }
    }
  }

  /**
   * Suggests page templates by node type, vocabulary and view.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   */
  public function page(array &$suggestions): void {
    $route = $this->requestContext->routeName();
    if ($route === 'entity.node.canonical') {
      $this->spliceBundleSuggestion($suggestions, 'page__', $this->requestContext->routeEntity('node'));
    }
    if ($route === 'entity.taxonomy_term.canonical') {
      $this->spliceBundleSuggestion($suggestions, 'page__taxonomy__', $this->requestContext->routeEntity('taxonomy_term'));
    }
    $view = $this->requestContext->routeString('view_id');
    if ($view !== NULL) {
      $suggestions[] = 'page__' . $view;
    }
  }

  /**
   * Suggests user templates by view mode and by the viewing user's id.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  public function user(array &$suggestions, array $variables): void {
    if (isset($variables['elements']['#view_mode'])) {
      $view_mode = str_replace('.', '_', (string) $variables['elements']['#view_mode']);
      $user_id = $this->requestContext->userId();
      $suggestions[] = 'user__' . $view_mode;
      $suggestions[] = 'user__' . $user_id;
      $suggestions[] = 'user__' . $user_id . '__' . $view_mode;
    }
  }

  /**
   * Suggests a node edit form template by content type.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param array<string, mixed> $variables
   *   The template variables.
   */
  public function nodeEditForm(array &$suggestions, array $variables): void {
    $node = $variables['form']['#node'] ?? NULL;
    $bundle = NULL;
    if ($node instanceof NodeInterface) {
      $bundle = $node->bundle();
    }
    else {
      $type = $this->requestContext->routeEntity('node_type');
      $node = $this->requestContext->routeEntity('node');
      if ($type instanceof NodeTypeInterface) {
        $bundle = $type->id();
      }
      elseif ($node instanceof NodeInterface) {
        $bundle = $node->bundle();
      }
    }
    if ($bundle) {
      $suggestions[] = 'node_edit_form__' . $bundle;
    }
  }

  /**
   * Inserts a bundle suggestion after the first, as more specific than it.
   *
   * @param list<string> $suggestions
   *   The suggestions.
   * @param string $prefix
   *   The start of the suggestion.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The route's entity; NULL when the route parameter was not converted.
   */
  private function spliceBundleSuggestion(array &$suggestions, string $prefix, ?EntityInterface $entity): void {
    if ($entity !== NULL) {
      array_splice($suggestions, 1, 0, $prefix . $entity->bundle());
    }
  }

  /**
   * The region-based suggestions of a menu.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   *
   * @return list<string>
   *   The suggestions, most specific order as 1.x listed them.
   */
  private function regionMenuSuggestions(array $variables): array {
    $region = $variables['attributes']['region'] ?? NULL;
    if ($region === NULL) {
      return [];
    }
    $suggestions = ['menu__' . $region];
    if (isset($variables['#menu_block_configuration'])) {
      $suggestions[] = 'menu__' . $region . '__menu_block';
      $menu_name = $variables['#menu_name'] ?? '';
      if ($menu_name) {
        $region_menu = 'menu__' . $region . '__' . strtr($menu_name, '-', '_');
        $suggestions[] = $region_menu;
        $suggestions[] = $region_menu . '__menu_block';
      }
      $configured = $variables['#menu_block_configuration']['suggestion'] ?? NULL;
      if (!empty($configured)) {
        $suggestions[] = 'menu__' . $region . '__' . $configured;
      }
    }
    return $suggestions;
  }

  /**
   * The suggestion of the template the site builder assigned to this menu.
   *
   * @param array<string, mixed> $variables
   *   The template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function assignedMenuSuggestion(array $variables, BoundThemeSettings $settings): ?string {
    $menu_name = $variables['menu_name'] ?? $variables['#menu_name'] ?? NULL;
    if ($menu_name === NULL || $menu_name === '') {
      return NULL;
    }
    $menu_name = strtr((string) $menu_name, '_', '-');
    // Rows are keyed by menu id since the migration; each may repeat it.
    foreach ($settings->array('menus.template_assignments') as $key => $row) {
      $row_menu = is_array($row) ? ($row['menu_id'] ?? $key) : NULL;
      if (empty($row_menu) || empty($row['template']) || strtr((string) $row_menu, '_', '-') !== $menu_name) {
        continue;
      }
      $template = (string) $row['template'];
      if (str_ends_with($template, '.html.twig')) {
        $template = substr($template, 0, -10);
      }
      return str_starts_with($template, 'menu--') ? 'menu__' . strtr(substr($template, 6), '-', '_') : NULL;
    }
    return NULL;
  }

}
