<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Feature;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\solo_utilities\Context\RequestContext;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\views\Render\ViewsRenderPipelineMarkup;

/**
 * Adds the current page's title to the breadcrumb and removes duplicates.
 *
 * @internal
 */
final class Breadcrumb {

  /**
   * Canonical routes whose entity names the page, with their route parameter.
   */
  private const ENTITY_ROUTES = [
    'entity.node.canonical' => 'node',
    'entity.taxonomy_term.canonical' => 'taxonomy_term',
    'entity.user.canonical' => 'user',
    'entity.webform.canonical' => 'webform',
  ];

  public function __construct(
    private readonly RequestContext $requestContext,
  ) {}

  /**
   * Updates the breadcrumb variable.
   *
   * @param array<string, mixed> $variables
   *   The breadcrumb template variables.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The active theme's settings.
   */
  public function preprocess(array &$variables, BoundThemeSettings $settings): void {
    $cache = CacheableMetadata::createFromRenderArray($variables)->addCacheableDependency($settings);
    if (!empty($variables['breadcrumb']) && !$settings->bool('breadcrumb.hide_page_title')) {
      // Always, as the outcome depends on the page even when nothing is added.
      $cache->addCacheContexts(['url']);
      $items = $this->withPageTitle($variables['breadcrumb']);
      array_push($items, ...$this->routeItems($cache));
      $variables['breadcrumb'] = $this->withoutDuplicates($items);
    }
    $cache->applyTo($variables);
  }

  /**
   * Appends the resolved page title unless an item already has that text.
   *
   * @param array<int|string, array<string, mixed>> $items
   *   The breadcrumb items.
   *
   * @return array<int|string, array<string, mixed>>
   *   The items, with the title last when it was added.
   */
  private function withPageTitle(array $items): array {
    $title = $this->requestContext->pageTitle();
    if (empty($title) || !is_string($title)) {
      return $items;
    }
    foreach ($items as $item) {
      if (($item['text'] ?? NULL) === $title) {
        return $items;
      }
    }
    $items[] = ['text' => Html::decodeEntities($title)];
    return $items;
  }

  /**
   * Builds the items that name the entity or view this route shows.
   *
   * @param \Drupal\Core\Cache\CacheableMetadata $cache
   *   Receives the entity, so renaming it updates the breadcrumb.
   *
   * @return list<array{text: string}>
   *   Up to two items, before duplicates are removed.
   */
  private function routeItems(CacheableMetadata $cache): array {
    $items = [];
    $parameter = self::ENTITY_ROUTES[$this->requestContext->routeName()] ?? NULL;
    $entity = $parameter === NULL ? NULL : $this->requestContext->routeEntity($parameter);
    if ($entity !== NULL) {
      $cache->addCacheableDependency($entity);
      $label = $entity instanceof AccountInterface ? $entity->getDisplayName() : $entity->label();
      $items[] = ['text' => Html::decodeEntities((string) $label)];
    }
    $view_title = $this->viewTitle();
    if ($view_title !== NULL) {
      $items[] = ['text' => Html::decodeEntities($view_title)];
    }
    return $items;
  }

  /**
   * The title of the Views page this request shows, or NULL.
   */
  private function viewTitle(): ?string {
    if ($this->requestContext->routeString('view_id') === NULL && !in_array('solo-body--views-page', $this->requestContext->bodyClasses(), TRUE)) {
      return NULL;
    }
    $title = $this->requestContext->pageTitle();
    // Instanceof needs no loaded class, so a site without Views is safe here.
    if ($title instanceof ViewsRenderPipelineMarkup) {
      $title = (string) $title;
    }
    elseif (is_array($title)) {
      $title = $title['#markup'] ?? NULL;
    }
    return is_string($title) && !empty($title) ? $title : NULL;
  }

  /**
   * Keeps the first item per text; items with no usable text are dropped.
   *
   * @param array<int|string, array<string, mixed>> $items
   *   The breadcrumb items.
   *
   * @return list<array<string, mixed>>
   *   The remaining items.
   */
  private function withoutDuplicates(array $items): array {
    $unique = [];
    $seen = [];
    foreach ($items as $item) {
      $text = $this->itemText($item['text'] ?? NULL);
      if ($text === NULL) {
        continue;
      }
      $decoded = Html::decodeEntities($text);
      if (!in_array($decoded, $seen, TRUE)) {
        $seen[] = $decoded;
        $unique[] = $item;
      }
    }
    return $unique;
  }

  /**
   * An item's text as a string, or NULL when it is empty or not text.
   *
   * Link text is usually a translatable object, hence the Stringable check.
   */
  private function itemText(mixed $text): ?string {
    if (empty($text)) {
      return NULL;
    }
    if (is_array($text)) {
      return is_string($text['#markup'] ?? NULL) ? $text['#markup'] : NULL;
    }
    return is_scalar($text) || $text instanceof \Stringable ? (string) $text : NULL;
  }

}
