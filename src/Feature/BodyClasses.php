<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Component\Utility\Html;
use Drupal\Core\Template\Attribute;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Render\AttributeHelper;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * Adds Solo's classes to the html and body elements.
 *
 * The result depends on the route and the path: the caller carries both cache
 * contexts.
 *
 * @internal
 */
final class BodyClasses {

  public function __construct(
    private readonly RequestContext $requestContext,
    private readonly AttributeHelper $attributeHelper,
  ) {}

  /**
   * Adds the classes to the variables of the html template.
   *
   * @param array<string, mixed> $variables
   *   The html template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function apply(array &$variables, BoundThemeSettings $settings): void {
    $this->attributeHelper->addClasses($variables, $this->bodyClasses($settings));
    $this->addHtmlClasses($variables, $settings);

    $attributes = $variables['attributes'];
    $classes = $attributes instanceof Attribute ? $attributes->getClass()->value() : ($attributes['class'] ?? []);
    $this->requestContext->rememberBodyClasses($this->attributeHelper->classList($classes));
  }

  /**
   * Lists the classes this request adds to the body element.
   *
   * @return list<string>
   *   Class names.
   */
  private function bodyClasses(BoundThemeSettings $settings): array {
    $classes = [];
    $route = $this->requestContext->routeName();
    if ($settings->bool('layout.width.use_pixels')) {
      $classes[] = 'solo-body--px-widths';
    }
    if ($route === 'entity.user.canonical') {
      $classes[] = 'solo-body--user-profile';
    }
    if ($route === 'entity.node.canonical' && ($node_id = $this->requestContext->routeEntity('node')?->id())) {
      $classes[] = 'path-node-' . $node_id;
    }
    if ($route === 'entity.taxonomy_term.canonical' && ($term = $this->requestContext->routeEntity('taxonomy_term'))) {
      if ($term->id()) {
        $classes[] = 'path-taxonomy-term-' . $term->id();
      }
      if ($term->bundle()) {
        $classes[] = 'path-vocabulary-' . Html::cleanCssIdentifier($term->bundle());
      }
    }
    $path = explode('/', ltrim($this->requestContext->internalPath(), '/'));
    if ($path[0] === 'user' && isset($path[1])) {
      $classes[] = 'path-user-' . $path[1];
    }
    $view_id = $this->requestContext->routeString('view_id');
    if ($view_id !== NULL) {
      $classes[] = 'solo-body--views-page';
      $classes[] = 'path-view-' . Html::cleanCssIdentifier($view_id);
    }
    if ($this->requestContext->isAdminRoute()) {
      $classes[] = 'solo-body--admin-context';
    }
    return $classes;
  }

  /**
   * Adds the font size and region gap classes to the html element.
   *
   * @param array<string, mixed> $variables
   *   The html template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  private function addHtmlClasses(array &$variables, BoundThemeSettings $settings): void {
    if (!($variables['html_attributes'] ?? NULL) instanceof Attribute) {
      return;
    }
    foreach (['fs' => 'typography.font_size', 'gap' => 'layout.regions_gap'] as $prefix => $key) {
      $value = $settings->get($key);
      if ($value && is_scalar($value)) {
        $variables['html_attributes']->addClass($prefix . '-' . $value);
      }
    }
  }

}
