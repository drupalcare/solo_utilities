<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Layout;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;

/**
 * Which regions a named theme has, and which of them hold blocks.
 *
 * Always a named theme: reading the active one is how a settings form under an
 * admin theme counted the admin theme's blocks. Reads only. Nothing here writes
 * to configuration, where 1.x stored its counts while the form was only viewed.
 *
 * Regions come from the theme's info, which core keeps on every supported
 * version. Theme::listAllRegions() and system_region_list() each exist on only
 * some of them. An unknown theme has no regions.
 *
 * @internal
 */
final class RegionInventory {

  public function __construct(
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Every region of a theme.
   *
   * @return array<string, string>
   *   Untranslated labels keyed by region machine name.
   */
  public function regions(string $theme): array {
    return $this->infoList($theme, 'regions');
  }

  /**
   * The regions of a theme that its info does not mark hidden.
   *
   * @return array<string, string>
   *   Untranslated labels keyed by region machine name.
   */
  public function visibleRegions(string $theme): array {
    return array_diff_key($this->regions($theme), array_flip($this->infoList($theme, 'regions_hidden')));
  }

  /**
   * The regions of a theme holding at least one block, enabled or not.
   *
   * @return list<string>
   *   Region machine names, in the theme's order.
   */
  public function regionsWithBlocks(string $theme): array {
    return $this->occupied($theme, FALSE);
  }

  /**
   * The regions of a theme holding at least one enabled block.
   *
   * @return list<string>
   *   Region machine names, in the theme's order.
   */
  public function regionsWithEnabledBlocks(string $theme): array {
    return $this->occupied($theme, TRUE);
  }

  /**
   * How many of the given regions hold an enabled block.
   *
   * @param string $theme
   *   The theme machine name.
   * @param list<string> $regions
   *   The region machine names of one group.
   */
  public function countEnabled(string $theme, array $regions): int {
    return count(array_intersect($this->regionsWithEnabledBlocks($theme), $regions));
  }

  /**
   * How many enabled blocks each region of a theme holds.
   *
   * @return array<string, int>
   *   Counts keyed by region machine name; a region with none has no entry.
   */
  public function enabledBlockCounts(string $theme): array {
    $counts = [];
    /** @var \Drupal\block\BlockInterface $block */
    foreach ($this->entityTypeManager->getStorage('block')->loadByProperties(['theme' => $theme]) as $block) {
      if ($block->status()) {
        $counts[$block->getRegion()] = ($counts[$block->getRegion()] ?? 0) + 1;
      }
    }
    return array_intersect_key($counts, $this->regions($theme));
  }

  /**
   * Cache tags of any answer about which regions hold blocks.
   *
   * @return list<string>
   *   The block list tag.
   */
  public function cacheTags(): array {
    return array_values($this->entityTypeManager->getDefinition('block')->getListCacheTags());
  }

  /**
   * The occupied regions of a theme, in the theme's order.
   *
   * @return list<string>
   *   Region machine names.
   */
  private function occupied(string $theme, bool $enabled_only): array {
    $regions = $this->regions($theme);
    if ($regions === []) {
      return [];
    }
    $occupied = [];
    /** @var \Drupal\block\BlockInterface $block */
    foreach ($this->entityTypeManager->getStorage('block')->loadByProperties(['theme' => $theme]) as $block) {
      if (!$enabled_only || $block->status()) {
        $occupied[$block->getRegion()] = TRUE;
      }
    }
    return array_keys(array_intersect_key($regions, $occupied));
  }

  /**
   * A list from a theme's info; empty for an unknown theme or a bad value.
   *
   * @return array<mixed>
   *   The stored list.
   */
  private function infoList(string $theme, string $key): array {
    if (!$this->themeHandler->themeExists($theme)) {
      return [];
    }
    $list = $this->themeHandler->getTheme($theme)->info[$key] ?? [];
    return is_array($list) ? $list : [];
  }

}
