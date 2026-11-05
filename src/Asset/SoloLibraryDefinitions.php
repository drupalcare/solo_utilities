<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Asset;

use Drupal\Component\Serialization\Yaml;

/**
 * Builds the Solo theme's generated library definitions from shipped data.
 *
 * Sources, all inside the Solo theme directory:
 * - fonts/solo-fonts.json: "fonts" (one entry per Google font slug) and
 *   "icons" (the Font Awesome libraries).
 * - schemes/*.yml: one record per predefined colour scheme.
 * - scripts/breakpoints/breakpoints.json: the breakpoint widths in px.
 * - libraries/solo-components.json: "libraries" (the component and feature
 *   libraries) and "globs" (libraries built from a file pattern).
 *
 * Pure: no services, no state. Malformed data is reported through the
 * $problems argument and skipped; nothing here throws, because it runs inside
 * library discovery and a throw would take down every page.
 *
 * @internal
 */
final class SoloLibraryDefinitions {

  /**
   * Font category: stylesheet directory and CSS weight.
   *
   * @var array<string, array{0: string, 1: int}>
   */
  private const FONT_CATEGORIES = [
    'global' => ['global', -200],
    'heading' => ['head', -150],
    'special' => ['special', -100],
    'site-name' => ['site-name', -50],
  ];

  /**
   * Weight of a predefined colour scheme stylesheet.
   */
  private const SCHEME_WEIGHT = -9;

  /**
   * Default CSS category and weight by directory, for a stylesheet entry.
   *
   * A path outside these directories defaults to category base, no weight.
   *
   * @var array<string, array{0: string, 1: int|null}>
   */
  private const CSS_DIRECTORIES = [
    'css/base' => ['base', -200],
    'css/layout' => ['layout', 200],
    'css/components' => ['component', 0],
    'css/theme' => ['theme', 200],
    'css/debug' => ['theme', NULL],
  ];

  /**
   * CSS categories Drupal accepts.
   */
  private const CSS_CATEGORIES = ['base', 'layout', 'component', 'state', 'theme'];

  /**
   * Top-level library keys a "meta" block may carry.
   */
  private const META_KEYS = ['remote', 'version', 'Version', 'license', 'header'];

  /**
   * Builds every generated library, keyed by library name.
   *
   * @param string $themePath
   *   Absolute path to the Solo theme directory.
   * @param array<int, string> $problems
   *   Receives one message per skipped source or record.
   *
   * @return array<string, array<string, mixed>>
   *   Library definitions in the shape of a *.libraries.yml entry.
   */
  public function build(string $themePath, array &$problems = []): array {
    $themePath = rtrim($themePath, '/');
    $fonts = $this->readJson($themePath . '/fonts/solo-fonts.json', 'fonts/solo-fonts.json', $problems);
    $components = $this->readJson($themePath . '/libraries/solo-components.json', 'libraries/solo-components.json', $problems);
    $libraries = [];
    foreach ([
      $this->fonts($fonts['fonts'] ?? NULL, $problems),
      $this->records($fonts['icons'] ?? NULL, 'fonts/solo-fonts.json icons', $problems),
      $this->schemes($themePath, $problems),
      $this->breakpoints($themePath, $problems),
      $this->records($components['libraries'] ?? NULL, 'libraries/solo-components.json libraries', $problems),
      $this->globs($themePath, $components['globs'] ?? NULL, $problems),
    ] as $part) {
      foreach ($part as $name => $definition) {
        if (isset($libraries[$name])) {
          $problems[] = sprintf('Library %s is defined twice; the second definition was skipped.', $name);
          continue;
        }
        $libraries[$name] = $definition;
      }
    }
    return $libraries;
  }

  /**
   * Builds the solo-gfex-* and solo-gfin-* libraries.
   *
   * @param mixed $rows
   *   The "fonts" list of fonts/solo-fonts.json.
   * @param array<int, string> $problems
   *   Problem messages.
   *
   * @return array<string, array<string, mixed>>
   *   Library definitions.
   */
  private function fonts(mixed $rows, array &$problems): array {
    $libraries = [];
    if (!is_array($rows) || !array_is_list($rows)) {
      $problems[] = 'fonts/solo-fonts.json has no "fonts" list.';
      return [];
    }
    foreach ($rows as $index => $row) {
      $slug = is_array($row) ? ($row['slug'] ?? NULL) : NULL;
      $category = is_array($row) ? ($row['category'] ?? NULL) : NULL;
      $query = is_array($row) ? ($row['query'] ?? NULL) : NULL;
      $local = is_array($row) ? ($row['local'] ?? NULL) : NULL;
      if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)
        || !is_string($category) || !isset(self::FONT_CATEGORIES[$category])
        || !is_string($query) || !preg_match('/^[A-Za-z0-9+:;,@._-]+$/', $query)
        || !is_bool($local)) {
        $problems[] = sprintf('fonts/solo-fonts.json entry %s is malformed and was skipped.', $index);
        continue;
      }
      [$directory, $weight] = self::FONT_CATEGORIES[$category];
      $stylesheet = sprintf('fonts/css/%s/solo-%s.css', $directory, $slug);
      $url = sprintf('https://fonts.googleapis.com/css2?family=%s&display=swap', $query);
      $libraries['solo-gfex-' . $slug] = [
        'css' => [
          'base' => [
            $url => ['type' => 'external', 'minified' => TRUE, 'weight' => $weight],
            $stylesheet => ['weight' => $weight],
          ],
        ],
      ];
      if ($local) {
        $libraries['solo-gfin-' . $slug] = [
          'css' => [
            'base' => [
              sprintf('fonts/font-face/google-font-%s.css', $slug) => ['weight' => $weight],
              $stylesheet => ['weight' => $weight],
            ],
          ],
        ];
      }
    }
    return $libraries;
  }

  /**
   * Builds one library per predefined colour scheme record.
   *
   * @param string $themePath
   *   Solo theme directory.
   * @param array<int, string> $problems
   *   Problem messages.
   *
   * @return array<string, array<string, mixed>>
   *   Library definitions.
   */
  private function schemes(string $themePath, array &$problems): array {
    $libraries = [];
    $files = glob($themePath . '/schemes/*.yml') ?: [];
    sort($files);
    foreach ($files as $file) {
      $record = $this->readYaml($file, $problems);
      if ($record === NULL) {
        continue;
      }
      $id = $record['id'] ?? NULL;
      $css = $record['css'] ?? NULL;
      $dependencies = $record['dependencies'] ?? [];
      if (!is_string($id) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id)
        || !is_string($css) || !$this->isRelativeAssetPath($css)
        || !is_array($dependencies) || !array_is_list($dependencies)
        || array_filter($dependencies, static fn ($dependency): bool => !is_string($dependency) || !preg_match('/^[a-z0-9_]+\/[A-Za-z0-9_.-]+$/', $dependency))) {
        $problems[] = sprintf('schemes/%s is malformed and was skipped.', basename($file));
        continue;
      }
      $library = [];
      if ($dependencies) {
        $library['dependencies'] = $dependencies;
      }
      $library['css'] = ['theme' => [$css => ['weight' => self::SCHEME_WEIGHT]]];
      $libraries[$id] = $library;
    }
    return $libraries;
  }

  /**
   * Builds the solo-global-breakpoints-sb-* and solo-menu-breakpoints-mn-*.
   *
   * @param string $themePath
   *   Solo theme directory.
   * @param array<int, string> $problems
   *   Problem messages.
   *
   * @return array<string, array<string, mixed>>
   *   Library definitions.
   */
  private function breakpoints(string $themePath, array &$problems): array {
    $libraries = [];
    $widths = $this->readJson($themePath . '/scripts/breakpoints/breakpoints.json', 'scripts/breakpoints/breakpoints.json', $problems);
    if (!array_is_list($widths)) {
      $problems[] = 'scripts/breakpoints/breakpoints.json is not a JSON list.';
      return [];
    }
    foreach ($widths as $index => $width) {
      if (!is_int($width) || $width < 1) {
        $problems[] = sprintf('scripts/breakpoints/breakpoints.json entry %s is malformed and was skipped.', $index);
        continue;
      }
      $libraries['solo-global-breakpoints-sb-' . $width] = [
        'css' => [
          'layout' => [
            sprintf('css/layout/solo-layout-breakpoints-sb-%d.css', $width) => ['weight' => -100],
          ],
          'theme' => [
            sprintf('css/breakpoints/solo-global-breakpoints-sb-%d.css', $width) => ['weight' => 200],
          ],
        ],
      ];
      $libraries['solo-menu-breakpoints-mn-' . $width] = [
        'css' => [
          'theme' => [
            sprintf('css/breakpoints/solo-menu-breakpoints-mn-%d.css', $width) => ['weight' => 200],
          ],
        ],
      ];
    }
    return $libraries;
  }

  /**
   * Builds libraries from a list of explicit records.
   *
   * A record is {id, meta?, css?, js?, dependencies?}. The built library keeps
   * that key order: the meta keys, then css, js and dependencies.
   *
   * @param mixed $records
   *   The decoded list.
   * @param string $label
   *   Where the list came from, for messages.
   * @param array<int, string> $problems
   *   Problem messages.
   *
   * @return array<string, array<string, mixed>>
   *   Library definitions.
   */
  private function records(mixed $records, string $label, array &$problems): array {
    if (!is_array($records) || !array_is_list($records)) {
      $problems[] = sprintf('%s has no list.', $label);
      return [];
    }
    $libraries = [];
    foreach ($records as $index => $record) {
      $id = is_array($record) ? ($record['id'] ?? NULL) : NULL;
      if (!is_string($id) || !$this->isLibraryId($id)) {
        $problems[] = sprintf('%s entry %s has no valid id and was skipped.', $label, $index);
        continue;
      }
      $library = $this->record($record);
      if ($library === NULL) {
        $problems[] = sprintf('%s entry %s (%s) is malformed and was skipped.', $label, $index, $id);
        continue;
      }
      if (isset($libraries[$id])) {
        $problems[] = sprintf('%s defines %s twice; the second was skipped.', $label, $id);
        continue;
      }
      $libraries[$id] = $library;
    }
    return $libraries;
  }

  /**
   * Builds one library from a validated-on-the-fly record.
   *
   * @param array<string, mixed> $record
   *   The record.
   *
   * @return array<string, mixed>|null
   *   The library, or NULL when any part is malformed or nothing is declared.
   */
  private function record(array $record): ?array {
    $library = [];
    if (isset($record['meta'])) {
      $meta = $this->meta($record['meta']);
      if ($meta === NULL) {
        return NULL;
      }
      $library = $meta;
    }
    if (isset($record['css'])) {
      $css = $this->cssFiles($record['css']);
      if ($css === NULL) {
        return NULL;
      }
      $library['css'] = $css;
    }
    if (isset($record['js'])) {
      $js = $this->jsFiles($record['js']);
      if ($js === NULL) {
        return NULL;
      }
      $library['js'] = $js;
    }
    if (isset($record['dependencies'])) {
      if (!$this->isDependencyList($record['dependencies'])) {
        return NULL;
      }
      $library['dependencies'] = $record['dependencies'];
    }
    $unknown = array_diff(array_keys($record), ['id', 'meta', 'css', 'js', 'dependencies']);
    return $unknown || !array_intersect_key($library, array_flip(['css', 'js', 'dependencies'])) ? NULL : $library;
  }

  /**
   * Validates a meta block (remote, version, license, header).
   *
   * @param mixed $meta
   *   The decoded value.
   *
   * @return array<string, mixed>|null
   *   The meta keys in their given order, or NULL when malformed.
   */
  private function meta(mixed $meta): ?array {
    if (!is_array($meta) || array_is_list($meta) || array_diff(array_keys($meta), self::META_KEYS)) {
      return NULL;
    }
    foreach ($meta as $key => $value) {
      $valid = match ($key) {
        'remote' => is_string($value) && preg_match('#^https://[A-Za-z0-9._~:/?\#@!$&=+%-]+$#', $value),
        'version', 'Version' => is_string($value) && preg_match('/^[A-Za-z0-9._-]+$/', $value) || is_int($value) || is_float($value),
        'header' => is_bool($value),
        'license' => is_array($value) && !array_is_list($value)
          && !array_diff(array_keys($value), ['name', 'url', 'gpl-compatible'])
          && is_string($value['name'] ?? NULL) && preg_match('/^[A-Za-z0-9. -]+$/', $value['name'])
          && is_string($value['url'] ?? NULL) && preg_match('#^https://[A-Za-z0-9._~:/?\#@!$&=+%-]+$#', $value['url'])
          && is_bool($value['gpl-compatible'] ?? NULL),
      };
      if (!$valid) {
        return NULL;
      }
    }
    return $meta;
  }

  /**
   * Builds the css section of a library from its file entries.
   *
   * @param mixed $entries
   *   A list of paths or {path, category?, weight?, ...options} objects.
   *
   * @return array<string, array<string, array<string, mixed>>>|null
   *   Files grouped by category in order of first use, or NULL when malformed.
   */
  private function cssFiles(mixed $entries): ?array {
    if (!is_array($entries) || !array_is_list($entries) || !$entries) {
      return NULL;
    }
    $css = [];
    foreach ($entries as $entry) {
      $file = $this->fileEntry($entry, TRUE);
      if ($file === NULL || isset($css[$file[1]][$file[0]])) {
        return NULL;
      }
      $css[$file[1]][$file[0]] = $file[2];
    }
    return $css;
  }

  /**
   * Builds the js section of a library from its file entries.
   *
   * @param mixed $entries
   *   A list of paths or {path, ...options} objects.
   *
   * @return array<string, array<string, mixed>>|null
   *   Options keyed by path, or NULL when malformed.
   */
  private function jsFiles(mixed $entries): ?array {
    if (!is_array($entries) || !array_is_list($entries) || !$entries) {
      return NULL;
    }
    $js = [];
    foreach ($entries as $entry) {
      $file = $this->fileEntry($entry, FALSE);
      if ($file === NULL || isset($js[$file[0]])) {
        return NULL;
      }
      $js[$file[0]] = $file[2];
    }
    return $js;
  }

  /**
   * Resolves one file entry to its path, category and options.
   *
   * A stylesheet gets its category and weight from its directory
   * (CSS_DIRECTORIES) unless the entry overrides them; "weight": null means no
   * weight at all.
   *
   * @param mixed $entry
   *   A path string or an object with "path" and optional overrides.
   * @param bool $css
   *   TRUE for a stylesheet, FALSE for a script.
   *
   * @return array{0: string, 1: string, 2: array<string, mixed>}|null
   *   Path, category (empty for a script) and options, or NULL when malformed.
   */
  private function fileEntry(mixed $entry, bool $css): ?array {
    $entry = is_string($entry) ? ['path' => $entry] : $entry;
    $path = is_array($entry) ? ($entry['path'] ?? NULL) : NULL;
    if (!is_string($path) || !$this->isAssetPath($path)) {
      return NULL;
    }
    $allowed = ['path', 'weight', 'type', 'minified', 'preprocess', 'attributes'];
    if ($css) {
      $allowed[] = 'category';
    }
    if (array_diff(array_keys($entry), $allowed)) {
      return NULL;
    }
    [$category, $weight] = $css ? (self::CSS_DIRECTORIES[dirname($path)] ?? ['base', NULL]) : ['', NULL];
    if (isset($entry['category'])) {
      if (!is_string($entry['category']) || !in_array($entry['category'], self::CSS_CATEGORIES, TRUE)) {
        return NULL;
      }
      $category = $entry['category'];
    }
    $options = [];
    if (!array_key_exists('weight', $entry) && $weight !== NULL) {
      $options['weight'] = $weight;
    }
    foreach ($entry as $key => $value) {
      $valid = match ($key) {
        'path', 'category' => NULL,
        'weight' => $value === NULL || is_int($value),
        'type' => in_array($value, ['external', 'file'], TRUE),
        'minified', 'preprocess' => is_bool($value),
        'attributes' => is_array($value) && !array_is_list($value) && $value
          && !array_filter($value, static fn ($v, $k): bool => !is_string($k) || !preg_match('/^[a-z-]+$/', $k) || !is_bool($v), ARRAY_FILTER_USE_BOTH),
      };
      if ($valid === FALSE) {
        return NULL;
      }
      if ($valid === NULL || ($key === 'weight' && $value === NULL)) {
        continue;
      }
      $options[$key] = $value;
    }
    return [$path, $category, $options];
  }

  /**
   * Builds the libraries a "globs" rule describes.
   *
   * Two rule shapes. With no "id": one library per matched file, named after
   * the file stem. With an "id": one library holding every matched file, the
   * "first" paths leading in their given order and the rest sorted.
   *
   * @param string $themePath
   *   Solo theme directory.
   * @param mixed $rules
   *   The "globs" list of libraries/solo-components.json.
   * @param array<int, string> $problems
   *   Problem messages.
   *
   * @return array<string, array<string, mixed>>
   *   Library definitions.
   */
  private function globs(string $themePath, mixed $rules, array &$problems): array {
    if (!is_array($rules) || !array_is_list($rules)) {
      $problems[] = 'libraries/solo-components.json has no "globs" list.';
      return [];
    }
    $libraries = [];
    foreach ($rules as $index => $rule) {
      $id = is_array($rule) ? ($rule['id'] ?? NULL) : NULL;
      $pattern = is_array($rule) ? ($rule['pattern'] ?? NULL) : NULL;
      $category = is_array($rule) ? ($rule['category'] ?? NULL) : NULL;
      $weight = is_array($rule) ? ($rule['weight'] ?? NULL) : NULL;
      $first = is_array($rule) ? ($rule['first'] ?? []) : NULL;
      $meta = is_array($rule) && isset($rule['meta']) ? $this->meta($rule['meta']) : [];
      if (!is_string($pattern) || !preg_match('#^css/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_*.-]+)*$#', $pattern) || str_contains($pattern, '..')
        || !is_string($category) || !in_array($category, self::CSS_CATEGORIES, TRUE)
        || !is_int($weight)
        || ($id !== NULL && (!is_string($id) || !$this->isLibraryId($id)))
        || !is_array($first) || ($first && array_is_list($first)) || array_filter($first, static fn ($w): bool => !is_int($w))
        || $meta === NULL) {
        $problems[] = sprintf('libraries/solo-components.json globs entry %s is malformed and was skipped.', $index);
        continue;
      }
      $files = glob($themePath . '/' . $pattern) ?: [];
      sort($files);
      $paths = array_map(static fn (string $file): string => substr($file, strlen($themePath) + 1), $files);
      if (!$paths) {
        $problems[] = sprintf('libraries/solo-components.json globs entry %s matched no file.', $index);
        continue;
      }
      if ($id === NULL) {
        foreach ($paths as $path) {
          $name = basename($path, '.css');
          if (!$this->isLibraryId($name)) {
            $problems[] = sprintf('%s is not a valid library name and was skipped.', $path);
            continue;
          }
          $libraries[$name] = ['css' => [$category => [$path => ['weight' => $weight]]]];
        }
        continue;
      }
      $ordered = [];
      foreach (array_keys($first) as $path) {
        if (in_array($path, $paths, TRUE)) {
          $ordered[] = $path;
        }
        else {
          $problems[] = sprintf('%s is listed first in globs entry %s but does not exist.', $path, $index);
        }
      }
      $ordered = array_merge($ordered, array_diff($paths, $ordered));
      $css = [];
      foreach ($ordered as $path) {
        $css[$path] = ['weight' => $first[$path] ?? $weight];
      }
      $libraries[$id] = $meta + ['css' => [$category => $css]];
    }
    return $libraries;
  }

  /**
   * Whether a value is a library machine name.
   *
   * @param string $id
   *   The candidate.
   *
   * @return bool
   *   TRUE for lowercase letters, digits, hyphens and underscores.
   */
  private function isLibraryId(string $id): bool {
    return (bool) preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', $id);
  }

  /**
   * Whether a value is a list of dependency names (extension/library).
   *
   * @param mixed $dependencies
   *   The candidate.
   *
   * @return bool
   *   TRUE for a non-empty list of well-formed names.
   */
  private function isDependencyList(mixed $dependencies): bool {
    return is_array($dependencies) && $dependencies && array_is_list($dependencies)
      && !array_filter($dependencies, static fn ($d): bool => !is_string($d) || !preg_match('/^[a-z0-9_]+\/[A-Za-z0-9_.-]+$/', $d));
  }

  /**
   * Whether a value is a theme-relative path, an https URL or a public:// path.
   *
   * @param string $path
   *   The candidate.
   *
   * @return bool
   *   TRUE when safe to hand to the library system.
   */
  private function isAssetPath(string $path): bool {
    if (str_contains($path, '..')) {
      return FALSE;
    }
    return $this->isRelativeAssetPath($path)
      || preg_match('#^https://[A-Za-z0-9._~:/?\#@!$&=+%;,-]+$#', $path)
      || preg_match('#^public://[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*$#', $path);
  }

  /**
   * Whether a value is a safe path relative to the theme directory.
   *
   * @param string $path
   *   The candidate path.
   *
   * @return bool
   *   TRUE for a plain relative path with no traversal.
   */
  private function isRelativeAssetPath(string $path): bool {
    return (bool) preg_match('/^[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_.-]+)*$/', $path)
      && !str_contains($path, '..');
  }

  /**
   * Reads a JSON file holding an array or an object.
   *
   * @param string $file
   *   Absolute path.
   * @param string $label
   *   Path below the theme directory, for messages.
   * @param array<int, string> $problems
   *   Problem messages.
   *
   * @return array<int|string, mixed>
   *   The decoded data, or an empty array when unreadable or not an array.
   */
  private function readJson(string $file, string $label, array &$problems): array {
    $raw = is_file($file) ? @file_get_contents($file) : FALSE;
    if ($raw === FALSE) {
      $problems[] = sprintf('%s is missing or unreadable.', $label);
      return [];
    }
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      $problems[] = sprintf('%s is not valid JSON.', $label);
      return [];
    }
    return $data;
  }

  /**
   * Reads a YAML file holding a mapping.
   *
   * @param string $file
   *   Absolute path.
   * @param array<int, string> $problems
   *   Problem messages.
   *
   * @return array<string, mixed>|null
   *   The record, or NULL when unreadable or not a mapping.
   */
  private function readYaml(string $file, array &$problems): ?array {
    $raw = @file_get_contents($file);
    if ($raw !== FALSE) {
      try {
        $data = Yaml::decode($raw);
      }
      catch (\Throwable) {
        $data = NULL;
      }
    }
    if (!isset($data) || !is_array($data) || array_is_list($data)) {
      $problems[] = sprintf('schemes/%s is unreadable or not a mapping and was skipped.', basename($file));
      return NULL;
    }
    return $data;
  }

}
