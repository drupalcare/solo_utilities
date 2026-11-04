<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Layout;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * Decides the column layout of a region group and the width of a page.
 *
 * @internal
 */
final class LayoutResolver {

  /**
   * The regions of each region group, in display order.
   */
  public const GROUP_REGIONS = [
    'top' => ['top_first', 'top_second', 'top_third'],
    'main' => ['sidebar_first', 'content', 'sidebar_second'],
    'bottom' => ['bottom_first', 'bottom_second', 'bottom_third', 'bottom_fourth'],
    'footer' => ['footer_first', 'footer_second', 'footer_third'],
  ];

  /**
   * The layout of a group when no setting chooses one, by filled region count.
   */
  public const DEFAULT_COLUMNS = [
    2 => 'solo-col-1-1',
    3 => 'solo-col-1-1-1',
    4 => 'solo-col-1-1-1-1',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Lists the region groups.
   *
   * @return list<string>
   *   Group names.
   */
  public function groups(): array {
    return array_keys(self::GROUP_REGIONS);
  }

  /**
   * Counts the regions of a group that have content on this page.
   *
   * @param string $group
   *   A group name from groups().
   * @param array<string, mixed> $page
   *   The page render array.
   */
  public function filledCount(string $group, array $page): int {
    $count = 0;
    foreach (self::GROUP_REGIONS[$group] ?? [] as $region) {
      if (!empty($page[$region])) {
        $count++;
      }
    }
    return $count;
  }

  /**
   * The column layout class of a group on this page.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param string $group
   *   A group name from groups().
   * @param array<string, mixed> $page
   *   The page render array.
   * @param string|null $bundle
   *   The content type shown by the page, if any.
   *
   * @return string|null
   *   The class, or NULL for a group with nothing in it.
   */
  public function columnLayout(BoundThemeSettings $settings, string $group, array $page, ?string $bundle): ?string {
    $count = $this->filledCount($group, $page);
    if ($count === 1) {
      return 'solo-col-1';
    }
    if (!isset(self::DEFAULT_COLUMNS[$count])) {
      return NULL;
    }
    $layout = $settings->get("region_groups.$group.columns_$count") ?? $this->defaultColumns($group, $count, $page);
    if ($bundle !== NULL && $settings->bool("region_groups.$group.per_content_type_enabled")) {
      $override = $settings->get("region_groups.$group.per_content_type.columns_$count.$bundle");
      if (!empty($override)) {
        $layout = $override;
      }
    }
    return is_scalar($layout) ? (string) $layout : NULL;
  }

  /**
   * The width class of a page: node, then content type, then global.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The theme's settings.
   * @param \Drupal\node\NodeInterface|null $node
   *   The node shown by the page, if any.
   *
   * @return mixed
   *   The stored class, as templates have always received it.
   */
  public function widthClass(BoundThemeSettings $settings, ?NodeInterface $node): mixed {
    $width = $settings->get('layout.width.global');
    if ($node === NULL) {
      return $width;
    }
    if ($settings->bool('layout.width.per_content_type_enabled')) {
      $type_width = $settings->array('layout.width.per_content_type')[$node->bundle()] ?? NULL;
      if (!empty($type_width)) {
        $width = $type_width;
      }
    }
    if ($settings->bool('layout.width.per_node_enabled')) {
      $node_width = $this->nodeWidth($node);
      if (!empty($node_width)) {
        $width = $node_width;
      }
    }
    return $width;
  }

  /**
   * The default layout, which for the main group follows the empty sidebar.
   *
   * @param string $group
   *   The group name.
   * @param int $count
   *   How many of its regions have content.
   * @param array<string, mixed> $page
   *   The page render array.
   */
  private function defaultColumns(string $group, int $count, array $page): string {
    if ($group === 'main' && $count === 3) {
      return 'solo-col-1-2-1';
    }
    if ($group === 'main' && $count === 2) {
      if (empty($page['sidebar_first'])) {
        return 'solo-col-2-1';
      }
      if (empty($page['sidebar_second'])) {
        return 'solo-col-1-2';
      }
    }
    return self::DEFAULT_COLUMNS[$count];
  }

  /**
   * The width class stored for one node, if any.
   */
  private function nodeWidth(NodeInterface $node): ?string {
    $widths = $this->entityTypeManager->getStorage('node_width')->loadByProperties(['node_id' => $node->id()]);
    if ($widths === []) {
      return NULL;
    }
    /** @var \Drupal\Core\Entity\ContentEntityInterface $width */
    $width = reset($widths);
    return $width->get('width_class')->value;
  }

}
