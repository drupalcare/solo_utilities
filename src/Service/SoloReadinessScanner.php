<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Service;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;

/**
 * Reads the installed Solo sub-themes and lists what Solo 2 will change.
 *
 * WHY THIS EXISTS
 * Solo 2 moves its PHP into this module and removes solo.theme. A sub-theme
 * that calls one of Solo's procedural functions, includes a "@solo/" template,
 * overrides a Solo library or carries a copy of a Solo template needs a change
 * before then. This is the one scanner behind the readiness panel on Solo's
 * settings form and "drush solo:preflight". It only reads: it writes no file
 * and changes no setting.
 *
 * Findings are arrays with the keys type, file (relative to the sub-theme),
 * line (0 when it applies to the whole file) and detail. The types are
 * function_call, solo_include, library_override and copied_template.
 *
 * @internal
 */
final class SoloReadinessScanner {

  public const TYPE_FUNCTION_CALL = 'function_call';

  public const TYPE_SOLO_INCLUDE = 'solo_include';

  public const TYPE_LIBRARY_OVERRIDE = 'library_override';

  public const TYPE_COPIED_TEMPLATE = 'copied_template';

  /**
   * A file is read only when it is at most this size, in bytes.
   */
  private const MAX_FILE_SIZE = 2097152;

  /**
   * Directories never entered while scanning.
   */
  private const SKIPPED_DIRECTORIES = ['node_modules', 'vendor', '.git'];

  /**
   * Solo's procedural function names, once read.
   *
   * @var string[]|null
   */
  private ?array $soloFunctions = NULL;

  public function __construct(
    private readonly ThemeHandlerInterface $themeHandler,
    private readonly ExtensionList $themeList,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly string $appRoot,
  ) {}

  /**
   * Scans every installed sub-theme of Solo.
   *
   * @return array{solo_utilities_enabled: bool, solo_found: bool, subthemes: array<string, array{path: string, findings: list<array{type: string, file: string, line: int, detail: string}>}>}
   *   The result. Sub-themes with no finding are present with an empty list.
   */
  public function scan(): array {
    $result = [
      'solo_utilities_enabled' => $this->moduleHandler->moduleExists('solo_utilities'),
      'solo_found' => FALSE,
      'subthemes' => [],
    ];
    $solo_path = $this->soloPath();
    if ($solo_path === NULL) {
      return $result;
    }
    $result['solo_found'] = TRUE;

    foreach ($this->themeHandler->listInfo() as $name => $theme) {
      $base_themes = $theme->base_themes ?? [];
      if ($name === 'solo' || !is_array($base_themes) || !array_key_exists('solo', $base_themes)) {
        continue;
      }
      $path = $this->appRoot . '/' . $theme->getPath();
      $result['subthemes'][(string) $name] = [
        'path' => $theme->getPath(),
        'findings' => $this->scanDirectory($path, $solo_path),
      ];
    }
    return $result;
  }

  /**
   * Scans one sub-theme directory against Solo's directory.
   *
   * @param string $subtheme_path
   *   Absolute path of the sub-theme.
   * @param string $solo_path
   *   Absolute path of Solo.
   *
   * @return list<array{type: string, file: string, line: int, detail: string}>
   *   The findings, ordered by file and line.
   */
  public function scanDirectory(string $subtheme_path, string $solo_path): array {
    $functions = array_flip($this->soloFunctionNames($solo_path));
    $solo_templates = array_flip($this->templateNames($solo_path));
    $findings = [];

    foreach ($this->files($subtheme_path) as $relative => $absolute) {
      $contents = file_get_contents($absolute);
      if ($contents === FALSE) {
        continue;
      }
      $is_php = (bool) preg_match('/\.(theme|php|inc|module)$/', $relative);
      $is_twig = str_ends_with($relative, '.twig');
      $is_info = str_ends_with($relative, '.info.yml');

      if ($is_php) {
        foreach ($this->functionCalls($contents, $functions) as [$line, $function]) {
          $findings[] = $this->finding(self::TYPE_FUNCTION_CALL, $relative, $line, $function . '() is a Solo function; Solo 2 moves Solo\'s PHP into solo_utilities.');
        }
      }
      if ($is_php || $is_twig || $is_info) {
        foreach (preg_split('/\R/', $contents) ?: [] as $index => $text) {
          if (preg_match_all('~@solo/[A-Za-z0-9_./-]*~', $text, $matches)) {
            foreach ($matches[0] as $reference) {
              $findings[] = $this->finding(self::TYPE_SOLO_INCLUDE, $relative, $index + 1, $reference . ' is a Solo template path; check it against Solo 2.');
            }
          }
        }
      }
      if ($is_info) {
        foreach ($this->libraryOverrides($contents) as $library) {
          $findings[] = $this->finding(self::TYPE_LIBRARY_OVERRIDE, $relative, $this->lineOf($contents, $library), 'libraries-override of ' . $library . ' replaces a Solo library; check it against Solo 2.');
        }
      }
      if ($is_twig && str_ends_with($relative, '.html.twig')) {
        $base = basename($relative);
        if (isset($solo_templates[$base])) {
          $findings[] = $this->finding(self::TYPE_COPIED_TEMPLATE, $relative, 0, $base . ' replaces the Solo template of the same name; compare it with the Solo 2 template.');
        }
      }
    }

    usort($findings, static fn(array $a, array $b): int => [
      $a['file'],
      $a['line'],
      $a['type'],
    ] <=> [$b['file'], $b['line'], $b['type']]);
    return $findings;
  }

  /**
   * Returns the names of Solo's procedural functions.
   *
   * @return string[]
   *   Read from solo.theme and includes/*.inc, so there is no second list.
   */
  public function soloFunctionNames(string $solo_path): array {
    if ($this->soloFunctions !== NULL) {
      return $this->soloFunctions;
    }
    $names = [];
    $files = glob($solo_path . '/includes/*.inc') ?: [];
    $files[] = $solo_path . '/solo.theme';
    foreach ($files as $file) {
      $contents = is_file($file) ? file_get_contents($file) : FALSE;
      if ($contents !== FALSE && preg_match_all('/^function\s+(\w+)\s*\(/m', $contents, $matches)) {
        array_push($names, ...$matches[1]);
      }
    }
    $this->soloFunctions = array_values(array_unique($names));
    return $this->soloFunctions;
  }

  /**
   * Returns the absolute path of Solo, or NULL when it is not on disk.
   */
  private function soloPath(): ?string {
    try {
      return $this->appRoot . '/' . $this->themeList->getPath('solo');
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Finds calls to the given functions in PHP source.
   *
   * @param string $source
   *   PHP source.
   * @param array<string, int> $functions
   *   Function names as keys.
   *
   * @return list<array{int, string}>
   *   Line and function name of each call. Definitions, methods and
   *   comments are not calls. A function name given as a string literal is
   *   reported too, because call_user_func() and hook lists take that form.
   */
  private function functionCalls(string $source, array $functions): array {
    try {
      $tokens = token_get_all($source, TOKEN_PARSE);
    }
    catch (\ParseError) {
      $tokens = token_get_all($source);
    }
    $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $significant = array_values(array_filter(
      $tokens,
      static fn($token): bool => !is_array($token) || !in_array($token[0], $ignored, TRUE),
    ));
    $calls = [];
    foreach ($significant as $i => $token) {
      if (!is_array($token)) {
        continue;
      }
      $previous = $significant[$i - 1] ?? NULL;
      $previous_id = is_array($previous) ? $previous[0] : NULL;
      if ($token[0] === T_STRING || $token[0] === T_NAME_FULLY_QUALIFIED) {
        $name = ltrim($token[1], '\\');
        $next = $significant[$i + 1] ?? NULL;
        $not_a_call = [
          T_FUNCTION,
          T_OBJECT_OPERATOR,
          T_NULLSAFE_OBJECT_OPERATOR,
          T_DOUBLE_COLON,
          T_NEW,
        ];
        if (isset($functions[$name]) && $next === '(' && !in_array($previous_id, $not_a_call, TRUE)) {
          $calls[] = [$token[2], $name];
        }
      }
      elseif ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
        $name = trim($token[1], '\'"');
        if (isset($functions[$name])) {
          $calls[] = [$token[2], $name];
        }
      }
    }
    return $calls;
  }

  /**
   * Returns the Solo libraries a .info.yml overrides.
   *
   * @return string[]
   *   Library ids such as "solo/global-styling".
   */
  private function libraryOverrides(string $info): array {
    try {
      $data = Yaml::decode($info);
    }
    catch (\Exception) {
      return [];
    }
    $overrides = is_array($data) ? ($data['libraries-override'] ?? []) : [];
    if (!is_array($overrides)) {
      return [];
    }
    return array_values(array_filter(array_map('strval', array_keys($overrides)), static fn(string $key): bool => str_starts_with($key, 'solo/')));
  }

  /**
   * Returns the line holding a string, or 0.
   */
  private function lineOf(string $contents, string $needle): int {
    foreach (preg_split('/\R/', $contents) ?: [] as $index => $text) {
      if (str_contains($text, $needle)) {
        return $index + 1;
      }
    }
    return 0;
  }

  /**
   * Returns the base names of Solo's template files.
   *
   * @return string[]
   *   For example "page.html.twig".
   */
  private function templateNames(string $solo_path): array {
    $names = [];
    foreach ($this->files($solo_path . '/templates') as $relative => $absolute) {
      if (str_ends_with($relative, '.html.twig')) {
        $names[] = basename($relative);
      }
    }
    return $names;
  }

  /**
   * Lists the files worth reading under a directory.
   *
   * @return array<string, string>
   *   Absolute paths keyed by path relative to the directory.
   */
  private function files(string $directory): array {
    if (!is_dir($directory)) {
      return [];
    }
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        static fn(\SplFileInfo $file): bool => !($file->isDir() && in_array($file->getFilename(), self::SKIPPED_DIRECTORIES, TRUE)),
      ),
    );
    foreach ($iterator as $file) {
      if (!$file->isFile() || $file->isLink() || $file->getSize() > self::MAX_FILE_SIZE) {
        continue;
      }
      $relative = substr($file->getPathname(), strlen(rtrim($directory, '/')) + 1);
      if (preg_match('/\.(theme|php|inc|module|twig)$|\.info\.yml$/', $relative)) {
        $found[$relative] = $file->getPathname();
      }
    }
    ksort($found);
    return $found;
  }

  /**
   * Builds one finding.
   *
   * @return array{type: string, file: string, line: int, detail: string}
   *   The finding.
   */
  private function finding(string $type, string $file, int $line, string $detail): array {
    return ['type' => $type, 'file' => $file, 'line' => $line, 'detail' => $detail];
  }

}
