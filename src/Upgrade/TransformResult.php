<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Upgrade;

/**
 * What the map did to one settings array. Holds key names, never values.
 *
 * The data property holds values and must never reach a log.
 *
 * @internal
 */
final class TransformResult {

  /**
   * Constructs the result.
   *
   * @param array<string|int, mixed> $data
   *   The transformed settings.
   * @param list<string> $unknown
   *   Top-level keys the map does not know, left where they were.
   * @param list<string> $unmovable
   *   Old keys whose value cannot be cast; they stay under the old key.
   * @param list<string> $differences
   *   Old keys removed in favour of a differing new value.
   */
  public function __construct(
    public readonly array $data,
    public readonly array $unknown,
    public readonly array $unmovable,
    public readonly array $differences,
  ) {}

}
