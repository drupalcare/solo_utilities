<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Migration;

/**
 * Rewrites a theme's old class names and ids in files and stored strings.
 *
 * Pure logic plus the file walk; no Drush, no database, no web UI. The rename
 * rows come from the theme's class map, passed in already decoded, so no row
 * is ever copied into PHP. Everything below the namespace line is shared
 * byte for byte by the Solo and D8W3CSS copies; a lane drift check compares
 * them. Based on Paragraphs Bundles' PbThemeClassMigrator.
 *
 * Map sections: structural, ids, state, values, context, generic, removed,
 * frozen, kept, plus `prefix`. A key ending in `*` is a dynamic tail: the old
 * prefix followed by any value becomes the new prefix with the same value.
 *
 * Rules:
 * 1. Tokens match on class-name boundaries, longest first, so `nav__menu`
 *    never rewrites inside `nav__menu-item`. A token after `/` or `@` is a
 *    path, never a class, and is left alone.
 * 2. Only stylesheets, Twig, JavaScript and PHP are read. Never `.yml`;
 *    `*.min.*`, node_modules, vendor, dist and .git are skipped, and so is
 *    every symlink and hard-linked file. PHP files are reported, never
 *    written.
 * 3. A hyphenated structural name that is not also an id is rewritten
 *    wherever it stands: it cannot be a PHP, Twig or JS identifier.
 * 4. A name that is one plain word, or is both a class and an id, is
 *    rewritten as `.name` in any file, and elsewhere only on a line that
 *    plainly sets classes (`class`, `addClass`, `classList`, ...) and not ids.
 *    Otherwise it is reported.
 * 5. Ids are rewritten as `#name`, and as a quoted or attribute value on a
 *    line that plainly sets ids (`id=`, `getElementById`, `aria-controls`,
 *    `for=`, ...) and not classes. Otherwise they are left alone.
 * 6. State and generic tokens are rewritten only in a stylesheet, chained
 *    onto a theme class or id (`.solo-x.toggled`). Elsewhere they are
 *    reported: a bare `.is-visible` may belong to something else.
 * 7. Display-value classes are rewritten in stylesheets only; in Twig, JS
 *    and PHP the same string is usually a stored-value comparison: reported.
 * 8. Context-dependent tokens and removed classes are reported only.
 * 9. SCSS/Less nesting built from a theme class (`&__x`, `#{}`) is reported.
 * 10. A second run changes nothing.
 *
 * @internal
 */
final class ClassMigrator {

  /**
   * Extensions the migrator reads; also the only values `--ext` accepts.
   *
   * @var list<string>
   */
  public const array EXTENSIONS = ['css', 'scss', 'sass', 'less', 'twig', 'js', 'theme', 'php', 'module', 'inc'];

  /**
   * Stylesheet extensions.
   *
   * @var list<string>
   */
  private const array STYLESHEETS = ['css', 'scss', 'sass', 'less'];

  /**
   * PHP extensions: read and reported, never written.
   *
   * @var list<string>
   */
  public const array REPORT_ONLY = ['theme', 'php', 'module', 'inc'];

  /**
   * Directory names never descended into.
   *
   * @var list<string>
   */
  private const array SKIP_DIRS = ['node_modules', 'vendor', '.git', 'dist'];

  /**
   * Characters a class name or id is made of.
   */
  private const string TOKEN_CHARS = 'A-Za-z0-9_-';

  /**
   * A line that plainly sets or selects classes.
   */
  private const string CLASS_CONTEXT = '/\bclass(?:Name|List|es)?\b|addClass|removeClass|toggleClass|hasClass|\bclass=/';

  /**
   * A line that plainly sets or selects ids.
   */
  private const string ID_CONTEXT = '/\bid\s*[=:]|[\'"]id[\'"]|\.id\b|getElementById|aria-(?:controls|labelledby|describedby|owns)|\bfor\s*=|data-target|data-bs-target|[\'"]#/';

  /**
   * Report labels, keyed by note kind, in report order.
   *
   * @var array<string, string>
   */
  public const array NOTE_LABELS = [
    'pcre-error' => 'Lines the pattern engine could not process (left unchanged; check by hand)',
    'php-change' => 'PHP lines that print an old name (never written; change them by hand)',
    'scss-nesting' => 'Nested selectors built from a parent with & or #{} (not rewritten; rename by hand)',
    'file-reference' => 'File names that contain an old name (not rewritten; the files on disk keep their names)',
    'unsure-name' => 'Old names in a place that may not be a class (one plain word, or a name that is both a class and an id)',
    'state-token' => 'State classes set by the theme JavaScript (rename where they mean the theme state)',
    'generic-token' => 'Generic tokens next to theme selectors',
    'removed' => 'Old classes with no new element',
    'value-in-template' => 'Display-value classes in Twig, JS or PHP (often stored-value comparisons; change only if used as a class)',
    'context-dependent' => 'Context-dependent classes (pick the right variant)',
  ];

  /**
   * The new-name prefix, for example `solo-`.
   */
  private string $prefix;

  /**
   * Fixed structural renames safe everywhere: old => new.
   *
   * @var array<string, string>
   */
  private array $structural = [];

  /**
   * Fixed structural renames that need a class context: old => new.
   *
   * @var array<string, string>
   */
  private array $risky = [];

  /**
   * Dynamic structural prefixes: old prefix => new prefix.
   *
   * @var array<string, string>
   */
  private array $tails = [];

  /**
   * Ids: old => new.
   *
   * @var array<string, string>
   */
  private array $ids = [];

  /**
   * Display-value classes: old => new.
   *
   * @var array<string, string>
   */
  private array $values = [];

  /**
   * State and generic tokens: old => new.
   *
   * @var array<string, string>
   */
  private array $chained = [];

  /**
   * Which chained tokens are state tokens.
   *
   * @var array<string, true>
   */
  private array $state = [];

  /**
   * Context-dependent tokens: old => description (fixed and dynamic).
   *
   * @var array<string, string>
   */
  private array $context = [];

  /**
   * Removed classes: old => explanation.
   *
   * @var array<string, string>
   */
  private array $removed = [];

  /**
   * PCRE error messages raised while processing the current line.
   *
   * @var list<string>
   */
  private array $pcreErrors = [];

  /**
   * Compiled patterns, keyed by purpose; NULL when the table is empty.
   *
   * @var array<string, string|null>
   */
  private array $patterns = [];

  /**
   * Builds the engine from a decoded class map.
   *
   * @param array<string, mixed> $map
   *   The decoded map: `prefix` and the sections named in the class comment.
   *
   * @throws \InvalidArgumentException
   *   When the map has no prefix or no rows; a migration must not guess.
   */
  public function __construct(array $map) {
    $prefix = $map['prefix'] ?? NULL;
    if (!is_string($prefix) || preg_match('/^[a-z][a-z0-9]*-$/', $prefix) !== 1) {
      throw new \InvalidArgumentException('The class map has no valid prefix.');
    }
    $this->prefix = $prefix;

    $ids = self::stringMap($map['ids'] ?? []);
    foreach ($ids as $old => $new) {
      if (!str_ends_with($old, '*')) {
        $this->ids[$old] = $new;
      }
    }
    foreach (self::stringMap($map['structural'] ?? []) as $old => $new) {
      if (str_ends_with($old, '*')) {
        $this->tails[substr($old, 0, -1)] = rtrim($new, '*');
      }
      elseif (isset($this->ids[$old]) || preg_match('/[-_]/', $old) !== 1) {
        $this->risky[$old] = $new;
      }
      else {
        $this->structural[$old] = $new;
      }
    }
    if ($this->structural === [] && $this->risky === [] && $this->tails === [] && $this->ids === []) {
      throw new \InvalidArgumentException('The class map holds no rename rows.');
    }
    $this->values = self::stringMap($map['values'] ?? []);
    foreach (self::stringMap($map['state'] ?? []) as $old => $new) {
      $this->chained[$old] = $new;
      $this->state[$old] = TRUE;
    }
    foreach (self::stringMap($map['generic'] ?? []) as $old => $new) {
      $this->chained += [$old => $new];
    }
    $context = $map['context'] ?? [];
    if (is_array($context)) {
      foreach ($context as $old => $row) {
        if (!is_string($old)) {
          continue;
        }
        $this->context[rtrim($old, '*')] = self::describe($row);
      }
    }
    foreach (self::stringMap($map['removed'] ?? []) as $old => $why) {
      $this->removed[$old] = $why;
    }

    // TOKEN_CHARS ends with a hyphen, so it goes last in a character class.
    $lb = '(?<![\/@' . self::TOKEN_CHARS . '])';
    $la = '(?![' . self::TOKEN_CHARS . '])';
    $q = preg_quote($this->prefix, '/');
    $this->patterns = [
      'struct' => self::compile($this->structural, $lb . '(', ')' . $la),
      // A dynamic tail continues with a value or a template expression,
      // never with a second hyphen.
      'tail' => self::compile($this->tails, $lb . '(', ')(?=[A-Za-z0-9_{$\'"%])'),
      'risky_dot' => self::compile($this->risky, '(?<=\.)(', ')' . $la),
      'risky' => self::compile($this->risky, $lb . '(', ')' . $la),
      'id_hash' => self::compile($this->ids, '(?<=#)(', ')' . $la),
      'id' => self::compile($this->ids, $lb . '(', ')' . $la),
      'value' => self::compile($this->values, $lb . '(', ')' . $la),
      'chained' => self::compile($this->chained, '([.#]' . $q . '[' . self::TOKEN_CHARS . ']+)\.(', ')' . $la),
      'chained_any' => self::compile($this->chained, '(?<=[.\s"\'])(', ')' . $la),
      'context' => self::compile($this->context, $lb . '(', ')' . $la),
      'context_css' => self::compile($this->context, '(?<=\.)(', ')' . $la),
      'removed' => self::compile($this->removed, $lb . '(', ')' . $la),
    ];
  }

  /**
   * Validates and normalizes an `--ext` list.
   *
   * @param string|null $extensions
   *   Comma-separated extensions, case-insensitive, leading dots tolerated.
   *   NULL selects the full default set; an empty value is refused.
   *
   * @return list<string>
   *   The selected extensions, lower case, without dots.
   *
   * @throws \InvalidArgumentException
   *   When any value is not one of self::EXTENSIONS.
   */
  public function normalizeExtensions(?string $extensions): array {
    if ($extensions === NULL) {
      return self::EXTENSIONS;
    }
    $selected = [];
    $refused = [];
    foreach (explode(',', $extensions) as $raw) {
      $ext = strtolower(ltrim(trim($raw), '.'));
      if ($ext === '') {
        continue;
      }
      if (!in_array($ext, self::EXTENSIONS, TRUE)) {
        $refused[] = $ext;
        continue;
      }
      $selected[$ext] = $ext;
    }
    if ($refused !== []) {
      throw new \InvalidArgumentException(sprintf('Refused extension(s): %s. Allowed: %s.', implode(', ', $refused), implode(', ', self::EXTENSIONS)));
    }
    if ($selected === []) {
      throw new \InvalidArgumentException(sprintf('No extension given. Allowed: %s.', implode(', ', self::EXTENSIONS)));
    }
    return array_values($selected);
  }

  /**
   * Walks a directory: files to read, and what was skipped and why.
   *
   * Symbolic links (files and directories) are never followed. Regular files
   * with more than one hard link are skipped too, because rewriting one would
   * change the other names of the same file outside the target.
   *
   * @param string $root
   *   An existing directory (already resolved with realpath()).
   * @param list<string> $extensions
   *   Extensions to include, from normalizeExtensions().
   *
   * @return array{files: list<string>, symlinks: list<string>, hardlinks: list<string>}
   *   Absolute paths, each list sorted.
   */
  public function scan(string $root, array $extensions): array {
    $files = [];
    $symlinks = [];
    $hardlinks = [];
    $directory = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
    $filter = new \RecursiveCallbackFilterIterator(
      $directory,
      static function (\SplFileInfo $item) use (&$symlinks): bool {
        if ($item->isLink()) {
          $symlinks[] = $item->getPathname();
          return FALSE;
        }
        if ($item->isDir()) {
          return !in_array($item->getFilename(), self::SKIP_DIRS, TRUE);
        }
        return TRUE;
      },
    );
    foreach (new \RecursiveIteratorIterator($filter) as $item) {
      if (!$item instanceof \SplFileInfo || !$item->isFile() || !$this->accepts($item->getFilename(), $extensions)) {
        continue;
      }
      $stat = @stat($item->getPathname());
      if ($stat !== FALSE && $stat['nlink'] > 1) {
        $hardlinks[] = $item->getPathname();
        continue;
      }
      $files[] = $item->getPathname();
    }
    sort($files);
    sort($symlinks);
    sort($hardlinks);
    return ['files' => $files, 'symlinks' => $symlinks, 'hardlinks' => $hardlinks];
  }

  /**
   * Whether a file name is one the migrator reads.
   *
   * @param string $filename
   *   The file name or path.
   * @param list<string> $extensions
   *   Extensions to include, from normalizeExtensions().
   */
  public function accepts(string $filename, array $extensions): bool {
    $base = basename($filename);
    if (str_contains($base, '.min.')) {
      return FALSE;
    }
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    return in_array($ext, $extensions, TRUE) && in_array($ext, self::EXTENSIONS, TRUE);
  }

  /**
   * Whether a file is read and reported but never written.
   */
  public function isReportOnly(string $filename): bool {
    return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), self::REPORT_ONLY, TRUE);
  }

  /**
   * Migrates the text of one file.
   *
   * @param string $contents
   *   The file contents.
   * @param string $filename
   *   The file name; its extension decides the rules.
   *
   * @return array{content: string, changes: list<array{line: int, before: string, after: string}>, notes: list<array{line: int, kind: string, token: string, new: string, source: string}>}
   *   The migrated text, the changed lines and the manual-review notes. For a
   *   report-only file the text is unchanged, there are no changes, and each
   *   line that would change is a `php-change` note.
   */
  public function migrate(string $contents, string $filename): array {
    $this->pcreErrors = [];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isCss = in_array($ext, self::STYLESHEETS, TRUE);
    $nests = in_array($ext, ['scss', 'sass', 'less'], TRUE);
    $reportOnly = in_array($ext, self::REPORT_ONLY, TRUE);
    $changes = [];
    $notes = [];
    $out = [];
    $stack = [];
    foreach (explode("\n", $contents) as $index => $orig) {
      $n = $index + 1;
      $errorMark = count($this->pcreErrors);
      if ($nests) {
        if ($this->isNesting($orig, $stack)) {
          $notes[] = self::note($n, 'scss-nesting', trim(strtok(trim($orig), '{') ?: trim($orig)), '?', $orig);
        }
        $stack = self::updateSelectorStack($orig, $stack);
      }
      [$line, $lineNotes] = $this->migrateLine($orig, $isCss);
      array_push($notes, ...array_map(static fn (array $note): array => ['line' => $n] + $note, $lineNotes));
      $lineErrors = array_slice($this->pcreErrors, $errorMark);
      if ($lineErrors !== []) {
        $notes[] = self::note($n, 'pcre-error', implode(', ', array_unique($lineErrors)), '?', $orig);
        $line = $orig;
      }
      if ($line !== $orig) {
        if ($reportOnly) {
          $notes[] = self::note($n, 'php-change', trim($orig), trim($line), $orig);
          $line = $orig;
        }
        else {
          $changes[] = ['line' => $n, 'before' => trim($orig), 'after' => trim($line)];
        }
      }
      $out[] = $line;
    }
    return ['content' => implode("\n", $out), 'changes' => $changes, 'notes' => $notes];
  }

  /**
   * Migrates a stylesheet held in configuration, such as inline CSS.
   *
   * @return array{content: string, changes: list<array{line: int, before: string, after: string}>, notes: list<array{line: int, kind: string, token: string, new: string, source: string}>}
   *   As migrate().
   */
  public function migrateCss(string $css): array {
    return $this->migrate($css, 'stored.css');
  }

  /**
   * Migrates a space-separated class list held in configuration.
   *
   * Every token is known to be a class, so structural names (one plain word
   * included) are renamed. State, generic, context and removed names are
   * reported, never renamed: an editor may have typed them for other reasons.
   *
   * @return array{value: string, renamed: array<string, string>, notes: list<array{token: string, kind: string, new: string}>}
   *   The new list, the renamed tokens and the tokens to check by hand.
   */
  public function migrateClassList(string $classes): array {
    $renamed = [];
    $notes = [];
    $parts = preg_split('/(\s+)/', $classes, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$classes];
    foreach ($parts as $i => $token) {
      if ($token === '' || trim($token) === '') {
        continue;
      }
      $new = $this->structural[$token] ?? $this->risky[$token] ?? $this->values[$token] ?? $this->tailFor($token);
      if ($new !== NULL) {
        $parts[$i] = $new;
        $renamed[$token] = $new;
      }
      elseif (isset($this->chained[$token])) {
        $notes[] = [
          'token' => $token,
          'kind' => isset($this->state[$token]) ? 'state-token' : 'generic-token',
          'new' => $this->chained[$token],
        ];
      }
      elseif (($desc = $this->contextFor($token)) !== NULL) {
        $notes[] = ['token' => $token, 'kind' => 'context-dependent', 'new' => $desc];
      }
      elseif (isset($this->removed[$token])) {
        $notes[] = ['token' => $token, 'kind' => 'removed', 'new' => $this->removed[$token]];
      }
    }
    return ['value' => implode('', $parts), 'renamed' => $renamed, 'notes' => $notes];
  }

  /**
   * Returns every fixed id rename: old => new.
   *
   * @return array<string, string>
   *   The id rows.
   */
  public function idMap(): array {
    return $this->ids;
  }

  /**
   * Returns the new id for an old one, or NULL when it is not renamed.
   */
  public function newId(string $id): ?string {
    return $this->ids[$id] ?? NULL;
  }

  /**
   * Renames ids in an HTML fragment: id and for values, and #fragment links.
   *
   * Used on stored content, where only ids are renamed (owner decision D3).
   * Only a same-document link (href="#id") is renamed. A link that names a
   * page or site before the "#" points at an id this text does not hold, so
   * it is left as it is and returned as a note with the whole href.
   *
   * @return array{value: string, renamed: array<string, string>, notes: list<array{id: string, new: string, href: string}>}
   *   The new HTML, the renamed ids, and the links to other pages to check by
   *   hand.
   */
  public function migrateHtmlIds(string $html): array {
    $renamed = [];
    $notes = [];
    $pattern = '/(\s(?:id|for|aria-controls|aria-labelledby|aria-describedby)\s*=\s*["\']|\shref\s*=\s*["\']#)([' . self::TOKEN_CHARS . ']+)(?=["\'])/i';
    $value = preg_replace_callback($pattern, function (array $m) use (&$renamed): string {
      $new = $this->ids[$m[2]] ?? NULL;
      if ($new === NULL) {
        return $m[0];
      }
      $renamed[$m[2]] = $new;
      return $m[1] . $new;
    }, $html);
    if ($value === NULL) {
      return ['value' => $html, 'renamed' => [], 'notes' => []];
    }
    $other = '/\shref\s*=\s*["\']([^"\'#]+#([' . self::TOKEN_CHARS . ']+))(?=["\'])/i';
    if (preg_match_all($other, $html, $matches, PREG_SET_ORDER) > 0) {
      foreach ($matches as $m) {
        if (isset($this->ids[$m[2]])) {
          $notes[] = ['id' => $m[2], 'new' => $this->ids[$m[2]], 'href' => $m[1]];
        }
      }
    }
    return ['value' => $value, 'renamed' => $renamed, 'notes' => $notes];
  }

  /**
   * Rewrites one line and lists its review notes.
   *
   * @return array{0: string, 1: list<array{kind: string, token: string, new: string, source: string}>}
   *   The rewritten line and its notes (without line numbers).
   */
  private function migrateLine(string $orig, bool $isCss): array {
    $notes = [];
    $add = static function (string $kind, string $token, string $new) use (&$notes, $orig): void {
      $notes[] = ['kind' => $kind, 'token' => $token, 'new' => $new, 'source' => trim($orig)];
    };

    // A name that is part of a file name (bottom-box.png) refers to a file on
    // disk, which this command never renames: protect it, then report it.
    $protected = [];
    $line = preg_replace_callback(
      '/(?<![' . self::TOKEN_CHARS . '])([' . self::TOKEN_CHARS . ']+)(?=\.(?:png|svg|jpe?g|gif|webp|avif|ico|html\.twig|twig|js|css|scss|woff2?|ttf)(?![' . self::TOKEN_CHARS . ']))/',
      function (array $m) use (&$protected): string {
        if ($this->newNameFor($m[1]) === NULL) {
          return $m[0];
        }
        $protected[] = $m[1];
        return "\x00" . (count($protected) - 1) . "\x00";
      },
      $orig,
    ) ?? $orig;

    $classLine = preg_match(self::CLASS_CONTEXT, $line) === 1;
    $idLine = preg_match(self::ID_CONTEXT, $line) === 1;

    // Ids first, in their unmistakable forms, so a name that is both a class
    // and an id is never renamed to the class.
    $line = $this->replace('id_hash', $line, fn (array $m): string => $this->ids[$m[1]]);
    // A bare word outside CSS is renamed only inside a quoted string, so a
    // JavaScript variable or a Twig variable that shares the name is kept.
    if (!$isCss && $idLine && !$classLine) {
      $line = $this->replaceInStrings('id', $line, fn (array $m): string => $this->ids[$m[1]]);
    }
    $line = $this->replace('struct', $line, fn (array $m): string => $this->structural[$m[1]]);
    $line = $this->replace('tail', $line, fn (array $m): string => $this->tails[$m[1]]);
    $line = $this->replace('risky_dot', $line, fn (array $m): string => $this->risky[$m[1]]);
    if (!$isCss && $classLine && !$idLine) {
      $line = $this->replaceInStrings('risky', $line, fn (array $m): string => $this->risky[$m[1]]);
    }
    elseif (!$isCss && ($classLine || $idLine)) {
      foreach ($this->find('risky', $line) as $token) {
        $add('unsure-name', $token, $this->risky[$token]);
      }
    }

    if ($isCss) {
      $line = $this->replace('value', $line, fn (array $m): string => $this->values[$m[1]]);
      $line = $this->replace('chained', $line, fn (array $m): string => $m[1] . '.' . $this->chained[$m[2]]);
    }
    else {
      foreach ($this->find('value', $line) as $token) {
        $add('value-in-template', $token, $this->values[$token]);
      }
    }

    foreach ($this->find('chained_any', $line) as $token) {
      if (isset($this->state[$token])) {
        if ($isCss || $classLine) {
          $add('state-token', $token, $this->chained[$token]);
        }
      }
      elseif (str_contains($line, $this->prefix)) {
        $add('generic-token', $token, $this->chained[$token]);
      }
    }
    foreach ($this->find($isCss ? 'context_css' : 'context', $line) as $token) {
      $add('context-dependent', $token, $this->context[$token]);
    }
    foreach ($this->find('removed', $line) as $token) {
      $add('removed', $token, $this->removed[$token]);
    }

    if ($protected !== []) {
      $line = preg_replace_callback('/\x00(\d+)\x00/', static fn (array $m): string => $protected[(int) $m[1]] ?? '', $line) ?? $orig;
      foreach ($protected as $token) {
        $add('file-reference', $token, (string) $this->newNameFor($token));
      }
    }
    return [$line, $notes];
  }

  /**
   * Applies replace() only inside the quoted strings of a line.
   */
  private function replaceInStrings(string $key, string $line, callable $callback): string {
    return preg_replace_callback(
      '/(["\'`])((?:\\\\.|(?!\1)[^\\\\])*)\1/',
      fn (array $m): string => $m[1] . $this->replace($key, $m[2], $callback) . $m[1],
      $line,
    ) ?? $line;
  }

  /**
   * Returns the new name of any renamed token, or NULL.
   */
  private function newNameFor(string $token): ?string {
    return $this->structural[$token] ?? $this->risky[$token] ?? $this->ids[$token] ?? $this->values[$token] ?? $this->tailFor($token);
  }

  /**
   * Returns the new name for a token that starts with a dynamic tail prefix.
   */
  private function tailFor(string $token): ?string {
    $best = NULL;
    foreach ($this->tails as $old => $new) {
      if (str_starts_with($token, $old) && strlen($token) > strlen($old) && $token[strlen($old)] !== '-' && ($best === NULL || strlen($old) > strlen($best))) {
        $best = $old;
      }
    }
    return $best === NULL ? NULL : $this->tails[$best] . substr($token, strlen($best));
  }

  /**
   * Returns the description of a context-dependent token, or NULL.
   */
  private function contextFor(string $token): ?string {
    if (isset($this->context[$token])) {
      return $this->context[$token];
    }
    foreach ($this->context as $old => $desc) {
      if (str_ends_with($old, '-') && str_starts_with($token, $old)) {
        return $desc;
      }
    }
    return NULL;
  }

  /**
   * Whether a line uses SCSS/Less parent nesting on or around a theme class.
   *
   * @param string $line
   *   The original line.
   * @param list<string> $stack
   *   Selector text of the open blocks, outermost first.
   */
  private function isNesting(string $line, array $stack): bool {
    $pieces = explode('{', $line);
    array_pop($pieces);
    $selector = implode(' ', $pieces);
    if (preg_match('/&(?:-|__)/', $selector) !== 1 && !str_contains($selector, '#{')) {
      return FALSE;
    }
    $text = implode(' ', $stack) . ' ' . $selector;
    if (str_contains($text, '.' . $this->prefix)) {
      return TRUE;
    }
    preg_match_all('/[.#]([' . self::TOKEN_CHARS . ']+)/', $text, $matches);
    foreach ($matches[1] as $token) {
      if ($this->newNameFor($token) !== NULL) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Pushes and pops block selectors for the braces on one line.
   *
   * @param string $line
   *   The original line.
   * @param list<string> $stack
   *   Selector text of the open blocks, outermost first.
   *
   * @return list<string>
   *   The stack after the line.
   */
  private static function updateSelectorStack(string $line, array $stack): array {
    $text = '';
    $length = strlen($line);
    for ($i = 0; $i < $length; $i++) {
      $char = $line[$i];
      if ($char === '{') {
        $stack[] = trim($text);
        $text = '';
      }
      elseif ($char === '}') {
        array_pop($stack);
        $text = '';
      }
      elseif ($char === ';') {
        $text = '';
      }
      else {
        $text .= $char;
      }
    }
    return $stack;
  }

  /**
   * Builds a report note.
   *
   * @return array{line: int, kind: string, token: string, new: string, source: string}
   *   The note.
   */
  private static function note(int $line, string $kind, string $token, string $new, string $source): array {
    return ['line' => $line, 'kind' => $kind, 'token' => $token, 'new' => $new, 'source' => trim($source)];
  }

  /**
   * Describes a context row: its candidate names and its note.
   */
  private static function describe(mixed $row): string {
    if (is_string($row)) {
      return $row;
    }
    if (!is_array($row)) {
      return '?';
    }
    $new = $row['new'] ?? [];
    $text = is_array($new) ? implode(' or ', array_map('strval', $new)) : (string) $new;
    return isset($row['note']) && is_string($row['note']) ? $text . ' (' . $row['note'] . ')' : $text;
  }

  /**
   * Applies one compiled pattern with a callback.
   *
   * @param string $key
   *   The pattern key.
   * @param string $line
   *   The line.
   * @param callable(array<int|string, string>): string $callback
   *   Replacement callback.
   */
  private function replace(string $key, string $line, callable $callback): string {
    $pattern = $this->patterns[$key] ?? NULL;
    if ($pattern === NULL) {
      return $line;
    }
    $result = preg_replace_callback($pattern, $callback, $line);
    if ($result === NULL) {
      $this->pcreErrors[] = preg_last_error_msg();
      return $line;
    }
    return $result;
  }

  /**
   * Returns the first capture group of every match of one pattern.
   *
   * @return list<string>
   *   The matched tokens, in order.
   */
  private function find(string $key, string $line): array {
    $pattern = $this->patterns[$key] ?? NULL;
    if ($pattern === NULL) {
      return [];
    }
    $count = preg_match_all($pattern, $line, $matches);
    if ($count === FALSE) {
      $this->pcreErrors[] = preg_last_error_msg();
      return [];
    }
    return $count < 1 ? [] : array_values($matches[1]);
  }

  /**
   * Compiles a longest-first alternation of a table's keys.
   *
   * @param array<string, string> $table
   *   The table.
   * @param string $before
   *   Pattern text before the alternation.
   * @param string $after
   *   Pattern text after the alternation.
   */
  private static function compile(array $table, string $before, string $after): ?string {
    if ($table === []) {
      return NULL;
    }
    $keys = array_map('strval', array_keys($table));
    usort($keys, static fn (string $a, string $b): int => strlen($b) <=> strlen($a) ?: strcmp($a, $b));
    $quoted = array_map(static fn (string $k): string => preg_quote($k, '/'), $keys);
    return '/' . $before . implode('|', $quoted) . $after . '/';
  }

  /**
   * Keeps only the string => string entries of a value.
   *
   * @return array<string, string>
   *   The filtered map.
   */
  private static function stringMap(mixed $raw): array {
    $map = [];
    if (is_array($raw)) {
      foreach ($raw as $key => $value) {
        if (is_string($key) && is_string($value)) {
          $map[$key] = $value;
        }
      }
    }
    return $map;
  }

}
