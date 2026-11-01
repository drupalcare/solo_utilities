<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The public API is one list; every class under src/ is @api or @internal.
 *
 * The list is tests/fixtures/public-api.txt. A class tagged @api that is not
 * on it, a class on it that is not tagged, a class with neither tag or with
 * both are all red. Changing the public API is therefore a visible edit to
 * that file, never a side effect of adding a class.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
class PublicApiDriftTest extends TestCase {

  /**
   * Classifies the class-like declarations in a source text.
   *
   * @return array<string, string>
   *   Tag ('api', 'internal', 'both', 'none') keyed by fully qualified name.
   */
  private function classify(string $code): array {
    $tokens = token_get_all($code);
    $namespace = '';
    $doc = NULL;
    $result = [];
    foreach ($tokens as $i => $token) {
      if (!is_array($token)) {
        if (in_array($token, [';', '{', '}'], TRUE)) {
          $doc = NULL;
        }
        continue;
      }
      if ($token[0] === T_NAMESPACE) {
        $namespace = '';
        for ($j = $i + 1; isset($tokens[$j]) && $tokens[$j] !== ';'; $j++) {
          if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], TRUE)) {
            $namespace .= $tokens[$j][1];
          }
        }
      }
      elseif ($token[0] === T_DOC_COMMENT) {
        $doc = $token[1];
      }
      elseif (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], TRUE)) {
        $previous = $tokens[$i - 1] ?? NULL;
        if (is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
          continue;
        }
        $name = '';
        for ($j = $i + 1; isset($tokens[$j]); $j++) {
          if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
            $name = $tokens[$j][1];
            break;
          }
        }
        $api = $doc !== NULL && (bool) preg_match('/^\s*\*\s*@api\b/m', $doc);
        $internal = $doc !== NULL && (bool) preg_match('/^\s*\*\s*@internal\b/m', $doc);
        $result[$namespace . '\\' . $name] = match (TRUE) {
          $api && $internal => 'both',
          $api => 'api',
          $internal => 'internal',
          default => 'none',
        };
        $doc = NULL;
      }
    }
    return $result;
  }

  /**
   * The classes under src/, classified.
   *
   * @return array<string, string>
   *   Tag keyed by fully qualified name.
   */
  private function sourceClasses(): array {
    $root = dirname(__DIR__, 3);
    $all = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->getExtension() === 'php') {
        $all += $this->classify((string) file_get_contents($file->getPathname()));
      }
    }
    return $all;
  }

  /**
   * The names on the approved-or-draft list.
   *
   * @return list<string>
   *   Fully qualified names.
   */
  private function listed(): array {
    $lines = file(dirname(__DIR__, 2) . '/fixtures/public-api.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return array_values(array_filter($lines, fn ($l) => !str_starts_with($l, '#')));
  }

  /**
   * Each class has exactly one tag, and @api equals the list.
   */
  public function testEveryClassIsApiOrInternalAndApiMatchesTheList(): void {
    $classes = $this->sourceClasses();
    $this->assertGreaterThan(10, count($classes), 'Classes were found.');
    $untagged = array_keys(array_filter($classes, fn ($tag) => in_array($tag, ['none', 'both'], TRUE)));
    $this->assertSame([], $untagged, 'Each class needs exactly one of @api and @internal.');
    $api = array_keys(array_filter($classes, fn ($tag) => $tag === 'api'));
    $listed = $this->listed();
    sort($api);
    sort($listed);
    $this->assertSame($listed, $api, 'The @api tags and tests/fixtures/public-api.txt must be the same set.');
  }

  /**
   * The classifier can go red.
   */
  public function testClassifierDetectsSeededCases(): void {
    $code = "<?php\nnamespace A\\B;\n/**\n * X.\n *\n * @api\n */\nclass One {}\n/**\n * Y.\n */\nclass Two {}\n/**\n * @api\n * @internal\n */\nfinal class Three { function f() { return static::class; } }\n/**\n * @internal\n */\n#[Attr(a: [1])]\nclass Four {}\n";
    $this->assertSame([
      'A\\B\\One' => 'api',
      'A\\B\\Two' => 'none',
      'A\\B\\Three' => 'both',
      'A\\B\\Four' => 'internal',
    ], $this->classify($code));
  }

}
