<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Settings;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Resets a stored setting that is not one of its schema's Choice values.
 *
 * The one owner of this rule: the 1.x migration, the config import transform
 * and the post-update all call normalize(). The allowed values come from the
 * schema (generated from solo-2.0-choices.yml), the replacement from the
 * install default.
 *
 * @internal
 */
final class ChoiceNormalizer {

  public function __construct(
    private readonly SettingsCatalog $catalog,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Replaces every stored value outside its allowed list with the default.
   *
   * A path that is not stored, or has no install default, is left alone.
   *
   * @param array<string, mixed> $data
   *   One theme's settings.
   * @param string $name
   *   The config object name, for the log.
   *
   * @return array{data: array<string, mixed>, changes: array<string, array{old: mixed, new: mixed}>}
   *   The settings and what was reset, keyed by dotted path.
   */
  public function normalize(array $data, string $name): array {
    $changes = [];
    foreach ($this->catalog->choicePaths() as $path => $choices) {
      $parts = explode('.', $path);
      $exists = FALSE;
      $value = NestedArray::getValue($data, $parts, $exists);
      if (!$exists || (is_scalar($value) && in_array((string) $value, $choices, TRUE))) {
        continue;
      }
      $default = $this->catalog->defaultAt($path);
      if (!is_scalar($default) || !in_array((string) $default, $choices, TRUE)) {
        continue;
      }
      NestedArray::setValue($data, $parts, $default);
      $changes[$path] = ['old' => $value, 'new' => $default];
      $this->loggerFactory->get('solo_utilities')->warning('@name: @path held @old, which is not an allowed value; reset to @new.', [
        '@name' => $name,
        '@path' => $path,
        '@old' => is_scalar($value) ? var_export($value, TRUE) : gettype($value),
        '@new' => var_export($default, TRUE),
      ]);
    }
    return ['data' => $data, 'changes' => $changes];
  }

}
