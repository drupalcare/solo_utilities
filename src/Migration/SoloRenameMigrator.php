<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Migration;

/**
 * Rewrites renamed library ids, JavaScript names and CSS variables; reports files.
 *
 * Works beside ClassMigrator, which handles class names and ids. The rows come
 * from SoloRenameMap::rows(), already decoded, so no row is copied into PHP.
 *
 * Rules:
 * 1. A row with the action `report-only` is never rewritten: the new name is
 *    not one name, or the place is not safe to edit. Every match is reported.
 * 2. Where a kind may be rewritten (REWRITE) the name is replaced on a name
 *    boundary, longest first. Anywhere else a match in PHP is reported.
 * 3. Library ids in a `*.info.yml` or `*.libraries.yml` are rewritten only when
 *    `$yml` is TRUE (the Drush option --yml); otherwise they are reported.
 * 4. Once ids and event names are matched only inside quotes or after
 *    `data-once~=`, so ordinary words are left alone.
 * 5. Moved or renamed files are only ever reported, in every file type read.
 * 6. A second run changes nothing: no new name is an old name (the drift
 *    check class-list.py proves it).
 *
 * @internal
 */
final class SoloRenameMigrator {

  /**
   * Report labels, keyed by note kind, in report order.
   *
   * @var array<string, string>
   */
  public const array NOTE_LABELS = [
    'rename-report-only' => 'Renamed names that need a person to pick the new name (a split or a merge)',
    'rename-php' => 'PHP lines that use a renamed library id, JavaScript name or CSS variable (change them by hand)',
    'rename-yml' => 'Library ids in .info.yml and .libraries.yml files (add --yml to rewrite them)',
    'file-moved' => 'Moved or renamed Solo files named in your files (libraries-override and libraries-extend need the new path)',
    'dataset-name' => 'Renamed data attributes read through element.dataset (rename the camelCase key by hand)',
  ];

  /**
   * Extensions a kind is rewritten in, when the row is not report-only.
   *
   * @var array<string, list<string>>
   */
  private const array REWRITE = [
    'variable' => ['css', 'scss', 'sass', 'less', 'js', 'twig'],
    'library' => ['twig'],
    'behavior' => ['js'],
    'once-id' => ['js', 'css', 'scss', 'sass', 'less', 'twig'],
    'drupalsettings-key' => ['js'],
    'data-attribute' => ['js', 'css', 'scss', 'sass', 'less', 'twig'],
    'js-event' => ['js'],
    'file' => [],
  ];

  /**
   * Characters a name is made of.
   */
  private const string TOKEN_CHARS = 'A-Za-z0-9_-';

  /**
   * Rows by kind: old => row.
   *
   * @var array<string, array<string, array{old: string, new: string, action: string, kind: string}>>
   */
  private array $byKind = [];

  /**
   * Compiled patterns, keyed by kind.
   *
   * @var array<string, string>
   */
  private array $patterns = [];

  /**
   * Builds the engine from flat rows.
   *
   * Rows whose old name is not one matchable word (a composite note, a
   * wildcard) are kept out of the patterns; they are in the human list only.
   *
   * @param list<array{old: string, new: string, action: string, kind: string}> $rows
   *   The rows from SoloRenameMap::rows().
   *
   * @throws \InvalidArgumentException
   *   When there are no matchable rows, or a kind is unknown.
   */
  public function __construct(array $rows) {
    foreach ($rows as $row) {
      if (!array_key_exists($row['kind'], self::REWRITE)) {
        throw new \InvalidArgumentException('Unknown rename kind: ' . $row['kind']);
      }
      if (self::matchable($row['old'])) {
        $this->byKind[$row['kind']][$row['old']] = $row;
      }
    }
    if ($this->byKind === []) {
      throw new \InvalidArgumentException('The rename map holds no matchable rows.');
    }
    foreach ($this->byKind as $kind => $table) {
      $names = array_keys($table);
      usort($names, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
      $alt = implode('|', array_map(static fn (string $n): string => preg_quote($n, '/'), $names));
      $this->patterns[$kind] = match ($kind) {
        'variable' => '/(?<![' . self::TOKEN_CHARS . '])(' . $alt . ')(?![' . self::TOKEN_CHARS . '])/',
        'library' => '/(?<![.\/' . self::TOKEN_CHARS . '])(' . $alt . ')(?![' . self::TOKEN_CHARS . '])/',
        'file' => '/(?<![.\/' . self::TOKEN_CHARS . '])(' . $alt . ')(?![' . self::TOKEN_CHARS . '])/',
        'once-id' => '/(?:(?<=[\'"])|(?<=data-once~=))(' . $alt . ')(?:(?=[\'"])|(?![' . self::TOKEN_CHARS . ']))/',
        'js-event' => '/(?<=[\'"])(' . $alt . ')(?=[\'"])/',
        default => '/(?<![$' . self::TOKEN_CHARS . '])(' . $alt . ')(?![' . self::TOKEN_CHARS . '])/',
      };
    }
  }

  /**
   * Whether a library or other YAML file is one this migrator reads.
   */
  public static function isYamlTarget(string $filename): bool {
    $base = basename($filename);
    return str_ends_with($base, '.info.yml') || str_ends_with($base, '.libraries.yml');
  }

  /**
   * Walks a directory for the .info.yml and .libraries.yml files.
   *
   * Symbolic links and hard-linked files are skipped, as ClassMigrator does.
   *
   * @param string $root
   *   An existing directory (already resolved with realpath()).
   *
   * @return list<string>
   *   Absolute paths, sorted.
   */
  public function scanYaml(string $root): array {
    $files = [];
    $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
    $filter = new \RecursiveCallbackFilterIterator(
      $directory,
      static function (\SplFileInfo $item): bool {
        if ($item->isLink()) {
          return FALSE;
        }
        if ($item->isDir()) {
          return !in_array($item->getFilename(), ['node_modules', 'vendor', '.git', 'dist'], TRUE);
        }
        return TRUE;
      },
    );
    foreach (new \RecursiveIteratorIterator($filter) as $item) {
      if (!$item instanceof \SplFileInfo || !$item->isFile() || !self::isYamlTarget($item->getFilename())) {
        continue;
      }
      $stat = @stat($item->getPathname());
      if ($stat !== FALSE && $stat['nlink'] > 1) {
        continue;
      }
      $files[] = $item->getPathname();
    }
    sort($files);
    return $files;
  }

  /**
   * Migrates the text of one file.
   *
   * @param string $contents
   *   The file contents.
   * @param string $filename
   *   The file name; its extension decides the rules.
   * @param bool $yml
   *   Whether library ids in YAML files may be rewritten (--yml).
   *
   * @return array{content: string, changes: list<array{line: int, before: string, after: string}>, notes: list<array{line: int, kind: string, token: string, new: string, source: string}>}
   *   The migrated text, the changed lines and the manual-review notes.
   */
  public function migrate(string $contents, string $filename, bool $yml = FALSE): array {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isYaml = $ext === 'yml';
    $changes = [];
    $notes = [];
    $out = [];
    foreach (explode("\n", $contents) as $index => $orig) {
      $n = $index + 1;
      $line = $orig;
      foreach ($this->patterns as $kind => $pattern) {
        if (!$this->readsKind($kind, $ext, $isYaml)) {
          continue;
        }
        $rewritable = $this->rewritesKind($kind, $ext, $isYaml, $yml);
        $replaced = preg_replace_callback($pattern, function (array $m) use ($kind, $rewritable, $n, $orig, $ext, &$notes): string {
          $row = $this->byKind[$kind][$m[1]];
          if ($kind === 'file') {
            $notes[] = $this->note($n, 'file-moved', $row, $orig);
            return $m[0];
          }
          if ($row['action'] === 'report-only') {
            $notes[] = $this->note($n, 'rename-report-only', $row, $orig);
            return $m[0];
          }
          if (!$rewritable) {
            $notes[] = $this->note($n, in_array($ext, ['yml'], TRUE) ? 'rename-yml' : 'rename-php', $row, $orig);
            return $m[0];
          }
          return $row['new'];
        }, $line);
        if ($replaced === NULL) {
          $notes[] = [
            'line' => $n,
            'kind' => 'rename-report-only',
            'token' => 'pattern error',
            'new' => '?',
            'source' => $orig,
          ];
          continue;
        }
        $line = $replaced;
      }
      if (in_array($ext, ['js'], TRUE)) {
        foreach ($this->byKind['data-attribute'] ?? [] as $row) {
          if (preg_match('/dataset\.' . preg_quote(self::camel($row['old']), '/') . '(?![A-Za-z0-9_])/', $orig) === 1) {
            $notes[] = $this->note($n, 'dataset-name', $row, $orig);
          }
        }
      }
      if ($line !== $orig) {
        $changes[] = ['line' => $n, 'before' => trim($orig), 'after' => trim($line)];
      }
      $out[] = $line;
    }
    return ['content' => implode("\n", $out), 'changes' => $changes, 'notes' => $notes];
  }

  /**
   * Whether a kind is looked for at all in a file type.
   */
  private function readsKind(string $kind, string $ext, bool $isYaml): bool {
    if ($isYaml) {
      return $kind === 'library' || $kind === 'file';
    }
    if (in_array($ext, ClassMigrator::REPORT_ONLY, TRUE)) {
      return TRUE;
    }
    return $kind === 'file' || in_array($ext, self::REWRITE[$kind], TRUE);
  }

  /**
   * Whether a match of a kind may be written in a file type.
   */
  private function rewritesKind(string $kind, string $ext, bool $isYaml, bool $yml): bool {
    if ($isYaml) {
      return $kind === 'library' && $yml;
    }
    return in_array($ext, self::REWRITE[$kind], TRUE);
  }

  /**
   * Whether an old name is one word the patterns can match.
   */
  private static function matchable(string $old): bool {
    return preg_match('/^[A-Za-z0-9_.:$\/-]+$/', $old) === 1;
  }

  /**
   * Turns data-solo-x-y into the camelCase key soloXY used by element.dataset.
   */
  private static function camel(string $attribute): string {
    $name = preg_replace('/^data-/', '', $attribute) ?? $attribute;
    return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $name))));
  }

  /**
   * Builds one review note.
   *
   * @param int $line
   *   The line number.
   * @param string $kind
   *   The note kind, a key of NOTE_LABELS.
   * @param array{old: string, new: string, action: string, kind: string} $row
   *   The matched row.
   * @param string $source
   *   The source line.
   *
   * @return array{line: int, kind: string, token: string, new: string, source: string}
   *   The note.
   */
  private function note(int $line, string $kind, array $row, string $source): array {
    return ['line' => $line, 'kind' => $kind, 'token' => $row['old'], 'new' => $row['new'], 'source' => $source];
  }

}
