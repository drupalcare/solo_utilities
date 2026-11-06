<?php

declare(strict_types=1);

namespace Drupal\solo_utilities;

use Drupal\Core\Cache\CacheableMetadata;

/**
 * The color scheme a rule picked, and what the choice depends on.
 *
 * @internal
 */
final class NegotiatedColorScheme {

  /**
   * Constructs the result.
   *
   * @param string|null $scheme
   *   The predefined scheme of the first matching rule, or NULL when no
   *   enabled rule matched.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   The rules list and every evaluated condition's cache contexts, tags and
   *   max-age. It applies whether or not a rule matched: "no match" varies by
   *   the same contexts as a match.
   */
  public function __construct(
    public readonly ?string $scheme,
    public readonly CacheableMetadata $cacheability,
  ) {}

}
