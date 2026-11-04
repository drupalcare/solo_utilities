<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Migration;

/**
 * Path rules for the class migration command: target, report file, writes.
 *
 * Kept apart from the command so the boundary checks can be unit-tested, and
 * shared byte for byte below the namespace line by the Solo and D8W3CSS
 * copies. Based on the Paragraphs Bundles migration path rules.
 * Every check works on realpath() results, so `..` segments and symbolic
 * links are resolved before a path is compared with a boundary, and every
 * prefix comparison appends a directory separator, so `/x/web2` never counts
 * as inside `/x/web`.
 */
final class ClassMigrationPaths {

  /**
   * The real Drupal root, without a trailing separator.
   */
  private string $root;

  /**
   * The Composer project folder (the root's parent holding composer.json).
   */
  private ?string $project = NULL;

  /**
   * Real directories that may never be a target, nor contain one.
   *
   * @var list<string>
   */
  private array $forbidden = [];

  /**
   * Real directories a report may not be written into.
   *
   * @var list<string>
   */
  private array $reportForbidden = [];

  /**
   * Returns a theme's path relative to the Drupal root, or NULL.
   *
   * @var \Closure(string): ?string
   */
  private \Closure $themePath;

  /**
   * Constructs the path rules.
   *
   * @param string $drupalRoot
   *   The Drupal root.
   * @param callable(string): ?string $themePath
   *   Returns a theme's path relative to the Drupal root, or NULL when no
   *   theme has that machine name.
   * @param list<string> $forbiddenTargets
   *   Directories (absolute, or relative to the Drupal root) that may not be
   *   migrated: core, the theme itself, the public files directory.
   * @param list<string> $forbiddenReportDirs
   *   Extra directories a report may not be written into, besides the Drupal
   *   root itself (for example a public files directory outside it).
   *
   * @throws \InvalidArgumentException
   *   When the Drupal root does not resolve.
   */
  public function __construct(string $drupalRoot, callable $themePath, array $forbiddenTargets = [], array $forbiddenReportDirs = []) {
    $root = realpath($drupalRoot);
    if ($root === FALSE || !is_dir($root)) {
      throw new \InvalidArgumentException(sprintf('Cannot resolve the Drupal root (%s).', $drupalRoot));
    }
    $this->root = rtrim($root, DIRECTORY_SEPARATOR);
    $parent = dirname($this->root);
    if ($parent !== $this->root && is_file($parent . DIRECTORY_SEPARATOR . 'composer.json')) {
      $this->project = $parent;
    }
    $this->themePath = \Closure::fromCallable($themePath);
    foreach ($forbiddenTargets as $dir) {
      $real = realpath(self::isAbsolute($dir) ? $dir : $this->root . DIRECTORY_SEPARATOR . $dir);
      if ($real !== FALSE) {
        $this->forbidden[] = $real;
      }
    }
    $this->reportForbidden = [$this->root];
    foreach ($forbiddenReportDirs as $dir) {
      $real = realpath($dir);
      if ($real !== FALSE) {
        $this->reportForbidden[] = $real;
      }
    }
  }

  /**
   * Returns the Composer project folder, or NULL when there is none.
   */
  public function project(): ?string {
    return $this->project;
  }

  /**
   * Returns the real Drupal root.
   */
  public function root(): string {
    return $this->root;
  }

  /**
   * Resolves a theme name or directory to a real directory inside the root.
   *
   * @param string $target
   *   A theme machine name, or a directory path. A relative path is looked up
   *   from $cwd, then from the Composer project folder, then from the Drupal
   *   root; the first existing directory wins.
   * @param string|null $cwd
   *   The folder Drush was run in, or NULL.
   *
   * @return string
   *   The real directory.
   *
   * @throws \InvalidArgumentException
   *   With a message for the user when the target is refused.
   */
  public function resolveTarget(string $target, ?string $cwd = NULL): string {
    // Candidate paths, in lookup order. Drush may be run from any folder:
    // the project folder, web/, web/sites/<site> or anywhere else.
    $candidates = [];
    if (preg_match('/^[a-z][a-z0-9_]*$/', $target) === 1) {
      $relative = ($this->themePath)($target);
      if (is_string($relative) && $relative !== '') {
        $candidates[] = $this->root . DIRECTORY_SEPARATOR . $relative;
      }
    }
    if (self::isAbsolute($target)) {
      $candidates[] = $target;
    }
    else {
      $bases = [$cwd, $this->project, $this->root];
      foreach ($bases as $base) {
        if ($base !== NULL && $base !== '') {
          $candidates[] = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $target;
        }
      }
    }
    $candidates = array_values(array_unique($candidates));

    $candidate = NULL;
    $real = FALSE;
    foreach ($candidates as $try) {
      $resolved = realpath($try);
      if ($resolved !== FALSE && is_dir($resolved)) {
        $candidate = $try;
        $real = $resolved;
        break;
      }
    }
    if ($candidate === NULL || $real === FALSE) {
      throw new \InvalidArgumentException(sprintf(
        '%s is not a theme or an existing directory. Looked in: %s',
        $target,
        implode(', ', $candidates),
      ));
    }
    if ($real === $this->root) {
      throw new \InvalidArgumentException('Refused: the whole Drupal root is not a valid target. Name one theme or module folder.');
    }
    if (!$this->isInside($real, $this->root)) {
      if (is_link(rtrim($candidate, DIRECTORY_SEPARATOR))) {
        throw new \InvalidArgumentException(sprintf(
          'Refused: %s is a symbolic link to %s, which is outside the Drupal root (%s). Run the command on a copy of the theme inside the Drupal root.',
          $target,
          $real,
          $this->root,
        ));
      }
      throw new \InvalidArgumentException(sprintf('Refused: %s is outside the Drupal root (%s).', $real, $this->root));
    }
    foreach ($this->forbidden as $dir) {
      if ($real === $dir || $this->isInside($real, $dir) || $this->isInside($dir, $real)) {
        throw new \InvalidArgumentException(sprintf(
          'Refused: %s is or contains %s (Drupal core, the base theme itself or the public files directory). Point the command at your own theme or module.',
          $this->relative($real),
          $this->relative($dir),
        ));
      }
    }
    return $real;
  }

  /**
   * Resolves the --report path and checks it is safe to create.
   *
   * A relative path that lands inside the Drupal root (for example
   * class-report.md run from web/sites/<site>) is moved to the Composer project
   * folder, keeping its file name. An absolute path inside the root is
   * refused, and so is a relative one when there is no project folder.
   *
   * @param string $report
   *   The --report value.
   * @param string $cwd
   *   The directory the user ran Drush from; a relative path resolves here.
   *
   * @return array{path: string, moved_from: ?string}
   *   The absolute path of the file to create, and the path the user asked
   *   for when it was moved to the project folder (NULL otherwise).
   *
   * @throws \InvalidArgumentException
   *   With a message for the user when the path is refused.
   */
  public function resolveReport(string $report, string $cwd): array {
    $relativeReport = !self::isAbsolute($report);
    $path = $relativeReport ? rtrim($cwd, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $report : $report;
    $name = basename($path);
    if ($name === '' || $name === '.' || $name === '..' || str_ends_with($report, DIRECTORY_SEPARATOR)) {
      throw new \InvalidArgumentException(sprintf('--report needs a file name, not a directory: %s', $report));
    }
    $parent = realpath(dirname($path));
    if ($parent === FALSE || !is_dir($parent)) {
      throw new \InvalidArgumentException(sprintf('The folder for the report does not exist: %s', dirname($path)));
    }
    $movedFrom = NULL;
    if ($this->isReportForbidden($parent)) {
      $example = ($this->project ?? dirname($this->root)) . DIRECTORY_SEPARATOR . 'class-report.md';
      if (!$relativeReport || $this->project === NULL) {
        throw new \InvalidArgumentException(sprintf(
          'Refused: %s is inside the Drupal root or the public files folder, where a web server may serve it. Write the report outside the web root, for example --report=%s',
          $parent . DIRECTORY_SEPARATOR . $name,
          $example,
        ));
      }
      $movedFrom = $parent . DIRECTORY_SEPARATOR . $name;
      $parent = $this->project;
      if ($this->isReportForbidden($parent)) {
        throw new \InvalidArgumentException(sprintf('Refused: the project folder %s is itself served. Give an absolute --report path outside the web root.', $parent));
      }
    }
    if (!is_writable($parent)) {
      throw new \InvalidArgumentException(sprintf('Refused: the folder %s is not writable. Choose a folder you can write to.', $parent));
    }
    $final = $parent . DIRECTORY_SEPARATOR . $name;
    if (is_link($final) || file_exists($final)) {
      throw new \InvalidArgumentException(sprintf('Refused: %s already exists. Choose a new file name, or delete the old report first.', $final));
    }
    return ['path' => $final, 'moved_from' => $movedFrom];
  }

  /**
   * Whether a real folder is one a report may not be written into.
   */
  private function isReportForbidden(string $dir): bool {
    foreach ($this->reportForbidden as $forbidden) {
      if ($dir === $forbidden || $this->isInside($dir, $forbidden)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Returns a path relative to the Drupal root, or the path unchanged.
   */
  public function relative(string $path): string {
    if ($path === $this->root) {
      return '.';
    }
    return $this->isInside($path, $this->root) ? substr($path, strlen($this->root) + 1) : $path;
  }

  /**
   * Replaces a file's contents atomically.
   *
   * Writes a temporary file in the same directory, checks every byte was
   * written, copies the permissions and renames it over the original. A
   * failure leaves the original untouched and removes the temporary file.
   * rename() replaces a symbolic link rather than following it.
   *
   * @return bool
   *   TRUE when the file now holds $contents.
   */
  public static function writeAtomic(string $path, string $contents): bool {
    $dir = dirname($path);
    $tmp = tempnam($dir, '.clsmig');
    if ($tmp === FALSE) {
      return FALSE;
    }
    if (dirname($tmp) !== $dir) {
      // tempnam() fell back to the system temp directory: not atomic.
      @unlink($tmp);
      return FALSE;
    }
    $written = file_put_contents($tmp, $contents);
    $perms = fileperms($path);
    if ($written !== strlen($contents) || $perms === FALSE || !chmod($tmp, $perms & 0777) || !rename($tmp, $path)) {
      @unlink($tmp);
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Whether a path is strictly inside a directory.
   */
  private function isInside(string $path, string $dir): bool {
    return str_starts_with($path, rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
  }

  /**
   * Whether a path is absolute.
   */
  private static function isAbsolute(string $path): bool {
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
  }

}
