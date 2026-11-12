<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Migration;

use Drupal\Component\Serialization\Yaml;

/**
 * Loads the renames made after Solo 1: classes, libraries, files, JS, variables.
 *
 * The rows live once, in config/map/solo-rename-map.yml (classes, libraries,
 * files, JavaScript names) and config/map/solo-variable-map.yml (CSS custom
 * properties, `removed` section). Nothing here copies a row; this class only
 * decodes the files and chains the class rows onto the Solo 1 class map, so a
 * Solo 1 name maps straight to its final name.
 *
 * @internal
 */
final class SoloRenameMap {

  /**
   * The rename map file, relative to this module.
   */
  public const string FILE = 'config/map/solo-rename-map.yml';

  /**
   * The CSS variable map file, relative to this module.
   */
  public const string VARIABLE_FILE = 'config/map/solo-variable-map.yml';

  /**
   * Row actions: rewrite, rewrite and keep the old name working, or report.
   *
   * @var list<string>
   */
  public const array ACTIONS = ['rewrite', 'alias', 'report-only'];

  /**
   * Returns the decoded rename map.
   *
   * @return array<string, mixed>
   *   The sections `classes`, `libraries`, `files` and `js`.
   *
   * @throws \RuntimeException
   *   When the file is missing or is not a Solo rename map.
   */
  public static function load(): array {
    return self::read(self::FILE, 'rename map');
  }

  /**
   * Returns every row of the non-class kinds, as a flat list.
   *
   * @return list<array{old: string, new: string, action: string, kind: string}>
   *   Libraries, files, JavaScript names and CSS variables.
   *
   * @throws \RuntimeException
   *   When a file is missing or a row is malformed.
   */
  public static function rows(): array {
    $map = self::load();
    $rows = [];
    foreach (['libraries' => 'library', 'files' => 'file'] as $section => $kind) {
      foreach (self::section($map, $section) as $old => $row) {
        $rows[] = self::row($old, $row, $kind, $section);
      }
    }
    foreach (self::section($map, 'js') as $old => $row) {
      $kind = is_array($row) && is_string($row['kind'] ?? NULL) ? $row['kind'] : '';
      $rows[] = self::row($old, $row, $kind, 'js');
    }
    $variables = self::read(self::VARIABLE_FILE, 'variable map');
    foreach ($variables['removed'] ?? [] as $old => $new) {
      if (is_string($old) && is_string($new)) {
        $rows[] = ['old' => $old, 'new' => $new, 'action' => 'alias', 'kind' => 'variable'];
      }
    }
    return $rows;
  }

  /**
   * Chains the renamed class rows onto a decoded Solo 1 class map.
   *
   * A Solo 1 row whose target was renamed again now points at the final name.
   * Each renamed class is added as a row of its own, so a theme already on the
   * earlier 2.0 names is migrated too.
   *
   * @param array<string, mixed> $classMap
   *   The decoded Solo 1 class map.
   *
   * @return array<string, mixed>
   *   The class map the engine reads.
   *
   * @throws \RuntimeException
   *   When an old name already has a different new name.
   */
  public static function chain(array $classMap): array {
    $renames = [];
    foreach (self::section(self::load(), 'classes') as $old => $row) {
      $renames[$old] = self::row($old, $row, 'class', 'classes')['new'];
    }
    if ($renames === []) {
      return $classMap;
    }
    $idTargets = [];
    foreach (['structural', 'ids', 'state', 'generic'] as $section) {
      $rows = $classMap[$section] ?? [];
      if (!is_array($rows)) {
        continue;
      }
      foreach ($rows as $old => $new) {
        if (is_string($new) && isset($renames[$new])) {
          $classMap[$section][$old] = $renames[$new];
        }
      }
    }
    foreach (($classMap['ids'] ?? []) as $new) {
      if (is_string($new)) {
        $idTargets[$new] = TRUE;
      }
    }
    foreach ($renames as $old => $new) {
      $section = isset($idTargets[$old]) ? 'ids' : 'structural';
      $existing = $classMap[$section][$old] ?? NULL;
      if ($existing !== NULL && $existing !== $new) {
        throw new \RuntimeException(sprintf('The class %s has two new names (%s and %s); fix the maps.', $old, (string) $existing, $new));
      }
      $classMap[$section][$old] = $new;
    }
    return $classMap;
  }

  /**
   * Returns a SHA-256 of the map files, for the report header.
   */
  public static function checksum(): string {
    $base = dirname(__DIR__, 2) . '/';
    $parts = [];
    foreach ([SoloClassMap::FILE, self::FILE, self::VARIABLE_FILE] as $file) {
      $parts[] = (string) hash_file('sha256', $base . $file);
    }
    return hash('sha256', implode('', $parts));
  }

  /**
   * Reads and decodes one map file.
   *
   * @return array<string, mixed>
   *   The decoded file.
   */
  private static function read(string $file, string $label): array {
    $path = dirname(__DIR__, 2) . '/' . $file;
    if (!is_file($path)) {
      throw new \RuntimeException(sprintf('The Solo %s is missing: %s', $label, $file));
    }
    $decoded = Yaml::decode((string) file_get_contents($path));
    if (!is_array($decoded)) {
      throw new \RuntimeException(sprintf('The Solo %s holds no rows: %s', $label, $file));
    }
    return $decoded;
  }

  /**
   * Returns one section of a map as an array.
   *
   * @param array<string, mixed> $map
   *   The decoded map.
   * @param string $name
   *   The section name.
   *
   * @return array<string, mixed>
   *   The rows, or an empty array.
   */
  private static function section(array $map, string $name): array {
    $rows = $map[$name] ?? [];
    return is_array($rows) ? $rows : [];
  }

  /**
   * Validates one row and returns it in the flat shape.
   *
   * @return array{old: string, new: string, action: string, kind: string}
   *   The row.
   */
  private static function row(mixed $old, mixed $row, string $kind, string $section): array {
    if (!is_string($old) || !is_array($row) || !is_string($row['new'] ?? NULL) || !in_array($row['action'] ?? NULL, self::ACTIONS, TRUE) || $kind === '') {
      throw new \RuntimeException(sprintf('A row in the %s section of %s is malformed.', $section, self::FILE));
    }
    return ['old' => $old, 'new' => $row['new'], 'action' => $row['action'], 'kind' => $kind];
  }

}
