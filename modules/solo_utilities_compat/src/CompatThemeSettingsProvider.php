<?php

declare(strict_types=1);

namespace Drupal\solo_utilities_compat;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\solo_utilities\Upgrade\SettingsMap;

/**
 * Answers a read of an old Solo setting name from its new name.
 *
 * Core gives this provider no interface, so the decorator has to extend the
 * class. It overrides the only public method and never calls the parent, so the
 * parent's constructor is not needed. Which names are old comes from the shipped
 * map: this module holds no list.
 *
 * @internal
 */
final class CompatThemeSettingsProvider extends ThemeSettingsProvider {

  /**
   * The key-value collection of old names already logged.
   */
  public const LOGGED_COLLECTION = 'solo_utilities_compat.logged_keys';

  /**
   * Names logged or checked during this request.
   *
   * @var array<string, true>
   */
  private array $seen = [];

  public function __construct(
    private readonly ThemeSettingsProvider $inner,
    private readonly SettingsMap $map,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSetting(string $setting_name, ?string $theme = NULL): mixed {
    if (!$this->map->isOldName($setting_name)) {
      return $this->inner->getSetting($setting_name, $theme);
    }
    [$head, $suffix] = array_pad(explode('.', $setting_name, 2), 2, NULL);
    $row = $this->map->lookup($head);
    $this->logOnce($head);

    switch ($row['action']) {
      case 'drop':
        return NULL;

      case 'fold_align_center':
        return $this->inner->getSetting((string) $row['new'], $theme) === 'center';

      case 'fan_out_layouts':
        $value = $this->layouts($theme);
        return $suffix === NULL ? $value : NestedArray::getValue($value ?? [], explode('.', $suffix));

      default:
        $path = (string) $row['new'] . ($suffix === NULL ? '' : '.' . $suffix);
        // A value the migration could not move is still under the old name.
        return $this->inner->getSetting($path, $theme) ?? $this->inner->getSetting($setting_name, $theme);
    }
  }

  /**
   * Rebuilds the old solo_layouts array from the region groups.
   *
   * @return array<string, array<string, mixed>>|null
   *   The old shape, or NULL when no group holds any.
   */
  private function layouts(?string $theme): ?array {
    $groups = $this->inner->getSetting('region_groups', $theme);
    $layouts = [];
    foreach (is_array($groups) ? $groups : [] as $group => $settings) {
      $per_type = is_array($settings) ? ($settings['per_content_type'] ?? NULL) : NULL;
      foreach (is_array($per_type) ? $per_type : [] as $columns => $types) {
        if (preg_match('/^columns_(\d+)$/', (string) $columns, $matches)) {
          $layouts[$group][$matches[1] . 'col'] = $types;
        }
      }
    }
    return $layouts === [] ? NULL : $layouts;
  }

  /**
   * Logs an old name once for the site, by name only.
   */
  private function logOnce(string $name): void {
    if (isset($this->seen[$name])) {
      return;
    }
    $this->seen[$name] = TRUE;
    $store = $this->keyValue->get(self::LOGGED_COLLECTION);
    if ($store->has($name)) {
      return;
    }
    $store->set($name, TRUE);
    $this->loggerFactory->get('solo_utilities_compat')->warning('Code read the old Solo setting @name. Run "drush solo:preflight" to find it and change it to the new name.', ['@name' => $name]);
  }

}
