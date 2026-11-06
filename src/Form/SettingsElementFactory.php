<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Form;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\solo_utilities\Layout\LayoutResolver;
use Drupal\solo_utilities\Settings\BoundThemeSettings;
use Drupal\solo_utilities\Settings\SchemaBounds;

/**
 * The one home of the element shapes the settings sections repeat.
 *
 * Every element posts to its full stored path through #parents, because core
 * replaces a whole top-level group when it saves.
 *
 * @internal
 */
final class SettingsElementFactory {

  use StringTranslationTrait;

  public function __construct(
    private readonly SettingsOptions $options,
    private readonly SchemaBounds $bounds,
  ) {}

  /**
   * Splits a dotted settings path into form #parents.
   *
   * @return list<string>
   *   The path segments.
   */
  public function parents(string $path): array {
    return explode('.', $path);
  }

  /**
   * The name attribute of an element posting to a path, for #states.
   */
  public function inputName(string $path): string {
    $parents = $this->parents($path);
    $first = array_shift($parents);
    return $first . implode('', array_map(static fn(string $part): string => '[' . $part . ']', $parents));
  }

  /**
   * A color field with its picker.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function colorSwatch(BoundThemeSettings $settings, string $path, string|\Stringable $label): array {
    return $this->bounds->applyTo([
      '#type' => 'textfield',
      '#parents' => $this->parents($path),
      '#size' => 10,
      '#title' => $label,
      '#default_value' => $settings->get($path),
      '#attributes' => [
        'pattern' => '^#[a-fA-F0-9]{6}',
      ],
      '#wrapper_attributes' => [
        'data-drupal-selector' => 'solo-color-picker',
      ],
    ], $path);
  }

  /**
   * Adds the fifteen color fields of one region to a tab, in three groups.
   *
   * The groups only arrange the fields: each field's #parents is explicit, so
   * its stored value does not move.
   *
   * @param array<string, mixed> $tab
   *   The tab element.
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   * @param string $region
   *   The region machine name.
   */
  public function addRegionColors(array &$tab, BoundThemeSettings $settings, string $region): void {
    $groups = [
      'region' => $this->t('Region'),
      'menu' => $this->t('Menu links'),
      'buttons' => $this->t('Buttons'),
    ];
    foreach ($groups as $group => $title) {
      $tab['solo_colors_' . $group] = [
        '#type' => 'fieldset',
        '#title' => $title,
        '#attributes' => ['class' => ['solo-color-group', 'solo-color-group--' . $group]],
      ];
    }
    foreach ($this->options->regionColors() as $key => $label) {
      $group = match (TRUE) {
        str_starts_with($key, 'menu_link_') => 'menu',
        str_starts_with($key, 'button_') => 'buttons',
        default => 'region',
      };
      $tab['solo_colors_' . $group]['color_' . $key] = $this->colorSwatch($settings, 'regions.' . $region . '.colors.' . $key, $label);
    }
  }

  /**
   * The CSS classes field of a region.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function cssClasses(BoundThemeSettings $settings, string $region): array {
    $path = 'regions.' . $region . '.classes';
    return [
      '#type' => 'textfield',
      '#parents' => $this->parents($path),
      '#title' => $this->t('CSS classes only'),
      '#description' => $this->t('Insert any CSS classes here. The CSS should be input by a trusted user (an admin or developer).'),
      '#default_value' => $settings->get($path),
    ];
  }

  /**
   * The checkbox animating the border of a region group.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function animateBorder(BoundThemeSettings $settings, string $group): array {
    $path = 'region_groups.' . $group . '.animate_border';
    return [
      '#type' => 'checkbox',
      '#parents' => $this->parents($path),
      '#title' => $this->t("Initiate animation of the region's border"),
      '#default_value' => $settings->get($path),
    ];
  }

  /**
   * The checkbox animating a region group on hover.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function animateHover(BoundThemeSettings $settings, string $group): array {
    $path = 'region_groups.' . $group . '.animate_hover';
    return [
      '#type' => 'checkbox',
      '#parents' => $this->parents($path),
      '#title' => $this->t('Initiate on hover animation for the region'),
      '#default_value' => $settings->get($path),
    ];
  }

  /**
   * The checkbox applying a border to the regions of a group.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function regionBorder(BoundThemeSettings $settings, string $group): array {
    $path = 'region_groups.' . $group . '.border';
    return [
      '#type' => 'checkbox',
      '#parents' => $this->parents($path),
      '#title' => $this->t('Tick this box if you prefer to apply a border to the inside regions'),
      '#default_value' => $settings->get($path),
    ];
  }

  /**
   * The select rounding the corners of the regions of a group.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function regionRounded(BoundThemeSettings $settings, string $group): array {
    $path = 'region_groups.' . $group . '.rounded';
    return [
      '#type' => 'select',
      '#parents' => $this->parents($path),
      '#title' => $this->t("Select from this list to enable rounding of the inside regions' corners"),
      '#default_value' => $settings->get($path, ''),
      '#options' => $this->options->rounded(),
    ];
  }

  /**
   * The select limiting which pages show a region or region group.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   * @param string $path
   *   The stored path of the visibility setting.
   * @param string $region
   *   The name shown in the title, as 1.x showed it.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function regionVisibility(BoundThemeSettings $settings, string $path, string $region): array {
    return [
      '#type' => 'select',
      '#parents' => $this->parents($path),
      '#title' => $this->t('Change the visibility for @region region', ['@region' => $region]),
      '#default_value' => $settings->get($path, 0),
      '#options' => $this->options->visibility(),
    ];
  }

  /**
   * The column layout select of a region group, global or for one content type.
   *
   * @param \Drupal\solo_utilities\Settings\BoundThemeSettings $settings
   *   The edited theme's settings.
   * @param string $group
   *   The region group.
   * @param int $columns
   *   Two, three or four columns.
   * @param string|null $type
   *   The content type of an override, or NULL for the group's own layout.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function regionColumns(BoundThemeSettings $settings, string $group, int $columns, ?string $type = NULL): array {
    $global = 'region_groups.' . $group . '.columns_' . $columns;
    $title = match ($columns) {
      2 => $this->t("The two columns' settings for @region region", ['@region' => $group]),
      3 => $this->t("The three columns' settings for @region region", ['@region' => $group]),
      4 => $this->t("The four columns' settings for @region region", ['@region' => $group]),
      default => throw new \InvalidArgumentException('A region group has two, three or four columns.'),
    };
    return [
      '#type' => 'select',
      '#parents' => $this->parents($type === NULL ? $global : $this->perTypePath($group, $columns, $type)),
      '#title' => $title,
      '#default_value' => $type === NULL
        ? $settings->get($global, $this->defaultColumns($group, $columns))
        : $this->layoutDefault($settings, $group, $columns, $type),
      '#options' => $this->options->columnLayouts($columns),
    ];
  }

  /**
   * The value a per-type layout select opens on.
   *
   * The stored override while overrides are on, otherwise the group's own.
   */
  private function layoutDefault(BoundThemeSettings $settings, string $group, int $columns, string $type): mixed {
    $value = NULL;
    if ($settings->get('region_groups.' . $group . '.per_content_type_enabled')) {
      $value = $settings->get($this->perTypePath($group, $columns, $type));
    }
    return empty($value) ? $settings->get('region_groups.' . $group . '.columns_' . $columns) : $value;
  }

  /**
   * The stored path of one content type's layout.
   */
  private function perTypePath(string $group, int $columns, string $type): string {
    return 'region_groups.' . $group . '.per_content_type.columns_' . $columns . '.' . $type;
  }

  /**
   * The layout a group's select opens on when nothing is stored.
   *
   * The form has no page to read, so the main group takes 1.x's fixed answer.
   */
  private function defaultColumns(string $group, int $columns): string {
    if ($group === 'main' && $columns === 2) {
      return 'solo-col-2-1';
    }
    if ($group === 'main' && $columns === 3) {
      return 'solo-col-1-2-1';
    }
    return LayoutResolver::DEFAULT_COLUMNS[$columns];
  }

  /**
   * The primary menu's mega menu layout select.
   *
   * @return array<string, mixed>
   *   The element.
   */
  public function megamenuLayout(BoundThemeSettings $settings): array {
    $path = 'menus.primary.megamenu.layout';
    return [
      '#type' => 'select',
      '#parents' => $this->parents($path),
      '#title' => $this->t('Megamenu layout (1, 2, 3 or 4 columns)'),
      '#default_value' => $settings->get($path, 'm-1-1'),
      '#options' => $this->options->megamenuLayouts(),
      '#states' => [
        'visible' => [
          ':input[name="' . $this->inputName('menus.primary.megamenu.enabled') . '"]' => ['checked' => TRUE],
        ],
      ],
    ];
  }

}
