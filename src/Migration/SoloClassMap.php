<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Migration;

use Drupal\Component\Serialization\Yaml;

/**
 * Loads Solo's class rename map and builds the migration engine from it.
 *
 * The rows live once, in config/map/solo-class-map.yml. Nothing here copies
 * them; this class only finds and decodes the file.
 *
 * @internal
 */
final class SoloClassMap {

  /**
   * The map file, relative to this module.
   */
  public const string FILE = 'config/map/solo-class-map.yml';

  /**
   * Returns the decoded map.
   *
   * @return array<string, mixed>
   *   The map sections.
   *
   * @throws \RuntimeException
   *   When the file is missing or holds no map. A migration must not guess.
   */
  public static function load(): array {
    $path = dirname(__DIR__, 2) . '/' . self::FILE;
    if (!is_file($path)) {
      throw new \RuntimeException('The Solo class map is missing: ' . self::FILE);
    }
    $decoded = Yaml::decode((string) file_get_contents($path));
    if (!is_array($decoded) || ($decoded['theme'] ?? NULL) !== 'solo') {
      throw new \RuntimeException('The Solo class map is not a Solo map: ' . self::FILE);
    }
    return $decoded;
  }

  /**
   * Builds the engine from the map.
   *
   * @throws \RuntimeException
   *   When the map cannot be read or holds no rows.
   */
  public static function migrator(): ClassMigrator {
    try {
      return new ClassMigrator(self::load());
    }
    catch (\InvalidArgumentException $e) {
      throw new \RuntimeException($e->getMessage() . ' (' . self::FILE . ')', 0, $e);
    }
  }

}
