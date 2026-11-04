<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Upgrade;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Reads the shipped old-to-new settings map. It holds no key name of its own.
 *
 * The file is a generated copy of planning/solo-config-map.yml. Decoding 700
 * rows is slow enough to matter on a read path, so the result is cached under a
 * key that includes the file's size and time: new code cannot read an old map.
 *
 * @internal
 */
final class SettingsMap {

  /**
   * The map file, relative to this module.
   */
  public const FILE = 'config/map/solo-config-map.yml';

  /**
   * The map once loaded.
   *
   * @var array{rows: array<string, array<string, mixed>>, legacy: list<array<string, mixed>>, top: array<string, true>}|null
   */
  private ?array $map = NULL;

  public function __construct(
    private readonly CacheBackendInterface $cache,
  ) {}

  /**
   * Whether the map file is on disk.
   */
  public function exists(): bool {
    return is_file($this->path());
  }

  /**
   * The rows for keys in the schema, keyed by old name, in file order.
   *
   * @return array<string, array<string, mixed>>
   *   Each row has old, new and action, and a type when the schema gives one.
   *
   * @throws \RuntimeException
   *   When the file is missing or is not a map. A migration must not guess.
   */
  public function rows(): array {
    return $this->load()['rows'];
  }

  /**
   * Whether a top-level key belongs to the new layout or to core.
   */
  public function isNewTopLevel(string $key): bool {
    return isset($this->load()['top'][$key]) || in_array($key, ['_core', 'langcode'], TRUE);
  }

  /**
   * The row for an old top-level key: a schema key or a legacy shape.
   *
   * @return array<string, mixed>|null
   *   The row, with new filled in for a legacy shape, or NULL for a key the map
   *   does not know.
   */
  public function lookup(string $key): ?array {
    $map = $this->load();
    if (isset($map['rows'][$key])) {
      return $map['rows'][$key];
    }
    foreach ($map['legacy'] as $shape) {
      if (preg_match($shape['regex'], $key, $matches)) {
        $new = $shape['new'];
        foreach ($matches as $placeholder => $value) {
          if (is_string($placeholder)) {
            $new = str_replace('<' . $placeholder . '>', $value, (string) $new);
          }
        }
        return [
          'old' => $key,
          'new' => $new,
          'action' => $shape['action'],
          'legacy' => TRUE,
        ];
      }
    }
    return NULL;
  }

  /**
   * Whether a setting name, or the first segment of one, is an old name.
   *
   * Core's own keys keep their names and so are not old names. A false answer
   * when the file is missing: this serves reports and read-time fallbacks,
   * which must not take a site down.
   */
  public function isOldName(string $name): bool {
    if (!$this->exists()) {
      return FALSE;
    }
    $row = $this->lookup(explode('.', $name, 2)[0]);
    return $row !== NULL && $row['action'] !== 'keep_core';
  }

  /**
   * Returns the absolute path of the map file.
   */
  private function path(): string {
    return dirname(__DIR__, 2) . '/' . self::FILE;
  }

  /**
   * Loads the map from memory, the cache or the file.
   *
   * @return array{rows: array<string, array<string, mixed>>, legacy: list<array<string, mixed>>, top: array<string, true>}
   *   Rows by old name, compiled legacy shapes and the new top-level names.
   */
  private function load(): array {
    if ($this->map !== NULL) {
      return $this->map;
    }
    $path = $this->path();
    if (!is_file($path)) {
      throw new \RuntimeException('The Solo settings map is missing: ' . self::FILE);
    }
    $cid = 'solo_utilities:settings_map:' . filesize($path) . ':' . filemtime($path);
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      $this->map = $cached->data;
      return $cached->data;
    }

    $decoded = Yaml::decode((string) file_get_contents($path));
    if (!is_array($decoded) || empty($decoded['keys']) || !is_array($decoded['keys'])) {
      throw new \RuntimeException('The Solo settings map holds no rows: ' . self::FILE);
    }
    $map = ['rows' => [], 'legacy' => [], 'top' => []];
    foreach ($decoded['keys'] as $row) {
      $map['rows'][(string) $row['old']] = $row;
      if (is_string($row['new'] ?? NULL)) {
        $map['top'][explode('.', $row['new'], 2)[0]] = TRUE;
      }
    }
    foreach ($decoded['legacy_shapes'] ?? [] as $shape) {
      $map['legacy'][] = $shape + ['regex' => $this->shapeRegex((string) $shape['old'])];
    }
    $this->cache->set($cid, $map);
    $this->map = $map;
    return $map;
  }

  /**
   * Compiles a legacy shape such as "site_width_<content_type>" to a regex.
   */
  private function shapeRegex(string $shape): string {
    $parts = preg_split('/<([a-z_]+)>/', $shape, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $regex = '';
    foreach ($parts as $index => $part) {
      if ($index % 2 === 0) {
        $regex .= preg_quote($part, '/');
      }
      else {
        $regex .= '(?<' . $part . '>' . match ($part) {
          'group' => 'top|main|bottom|footer',
          'n' => '\d+',
          default => '[a-z0-9_]+',
        } . ')';
      }
    }
    return '/^' . $regex . '$/';
  }

}
