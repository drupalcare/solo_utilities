<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form\ThemeSettings;

use Drupal\solo_utilities\Settings\BoundThemeSettings;

/**
 * What every settings section is given: the edited theme and nothing else.
 *
 * @internal
 */
final readonly class SettingsFormContext {

  /**
   * Constructs the context.
   *
   * @param string $theme
   *   The machine name of the edited theme.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The settings of the edited theme.
   * @param array<string, string|\Stringable> $mixRegions
   *   Occupied regions outside the four groups: label keyed by machine name.
   * @param array<string, array<string, string|\Stringable>> $groupRegions
   *   Occupied regions of each group: label keyed by machine name, with the
   *   group wrapper's label under 'container' when any is occupied.
   */
  public function __construct(
    public string $theme,
    public BoundThemeSettings $settings,
    public array $mixRegions,
    public array $groupRegions,
  ) {}

  /**
   * Whether a region outside the four groups holds a block.
   */
  public function hasMixRegion(string $region): bool {
    return isset($this->mixRegions[$region]);
  }

}
