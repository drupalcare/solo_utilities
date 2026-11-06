<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The module's architecture rules, read from source, each able to go red.
 *
 * 1. No static \Drupal:: call in src/ (injection only). One named exception:
 *    config entities cannot take constructor injection.
 * 2. One module and one sub-module: modules/ holds only solo_utilities_compat,
 *    and the module root has one services file.
 * 3. No #[LegacyHook] anywhere: the ^11.3 floor discovers class hooks.
 * 4. Every hook_update_N() / hook_post_update_NAME() docblock is one summary
 *    line, because drush updb prints all of it in one table cell. A
 *    post-update summary has no slash, which Drupal strips.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
class ArchitectureScanTest extends TestCase {

  /**
   * Files allowed a static \Drupal:: call, with the reason.
   *
   * ColorSchemesRule is a config entity: entities are not built from the
   * container, so core's own entities (Block) fetch the condition manager
   * statically too. Adding a file here is a decision, not a convenience.
   */
  private const STATIC_CALL_ALLOWED = [
    'src/Entity/ColorSchemesRule.php',
  ];

  /**
   * Finds static \Drupal:: calls in code, ignoring comments and strings.
   *
   * @return list<int>
   *   Line numbers.
   */
  private function staticCalls(string $code): array {
    $tokens = token_get_all($code);
    $lines = [];
    foreach ($tokens as $i => $token) {
      if (is_array($token) && $token[1] === '\\Drupal' && is_array($tokens[$i + 1] ?? NULL) && $tokens[$i + 1][0] === T_DOUBLE_COLON) {
        $lines[] = $token[2];
      }
    }
    return $lines;
  }

  /**
   * Php files under a directory, keyed by path relative to the module.
   *
   * @return array<string, string>
   *   Contents.
   */
  private function phpFiles(string $root, string $dir): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->getExtension() === 'php') {
        $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
      }
    }
    return $files;
  }

  /**
   * No static service location in src/.
   */
  public function testNoStaticDrupalCallsInSrc(): void {
    $root = dirname(__DIR__, 3);
    $files = $this->phpFiles($root, 'src');
    $this->assertNotEmpty($files);
    $offences = [];
    foreach ($files as $path => $code) {
      if (in_array($path, self::STATIC_CALL_ALLOWED, TRUE)) {
        continue;
      }
      foreach ($this->staticCalls($code) as $line) {
        $offences[] = $path . ':' . $line;
      }
    }
    $this->assertSame([], $offences);
  }

  /**
   * The scan can go red: a seeded call is found, a comment is not.
   */
  public function testStaticCallScanDetectsSeededCall(): void {
    $this->assertSame([3], $this->staticCalls("<?php\n// \\Drupal::logger('x');\n\$a = \\Drupal::service('x');\n"));
    $this->assertSame([], $this->staticCalls("<?php\n/** \\Drupal::service('x') */\n\$a = 'Drupal::x';\n"));
    $this->assertSame([2], $this->staticCalls("<?php\n\$v = \\Drupal::VERSION;\n"));
  }

  /**
   * Every allowed exception still exists and still needs it.
   */
  public function testAllowedExceptionsAreLive(): void {
    $root = dirname(__DIR__, 3);
    foreach (self::STATIC_CALL_ALLOWED as $path) {
      $this->assertNotEmpty($this->staticCalls((string) file_get_contents($root . '/' . $path)), $path . ' no longer calls \\Drupal::; remove it from the list.');
    }
  }

  /**
   * One sub-module (compat), one root services file, no LegacyHook.
   */
  public function testOneModuleOneServicesFile(): void {
    $root = dirname(__DIR__, 3);
    // The only sub-module is solo_utilities_compat (AGENTS.md); a second one is
    // a decision, so it must be added here.
    $sub_modules = array_map('basename', glob($root . '/modules/*', GLOB_ONLYDIR) ?: []);
    $this->assertSame(['solo_utilities_compat'], $sub_modules, 'The sub-modules are exactly solo_utilities_compat.');
    $this->assertFileExists($root . '/modules/solo_utilities_compat/solo_utilities_compat.info.yml');
    $this->assertCount(1, glob($root . '/*.services.yml') ?: [], 'Exactly one services file.');
    $this->assertSame([], glob($root . '/*/*.info.yml') ?: [], 'A nested info file means a sub-module.');
    foreach (['solo_utilities.module', 'solo_utilities.install'] as $name) {
      $this->assertStringNotContainsString('LegacyHook', (string) file_get_contents($root . '/' . $name), $name);
    }
    foreach ($this->phpFiles($root, 'src') as $path => $code) {
      $this->assertStringNotContainsString('LegacyHook', $code, $path);
    }
  }

  /**
   * Update function docblocks found in the text.
   *
   * @return list<string>
   *   Violations.
   */
  private function updateDocblockViolations(string $code): array {
    $violations = [];
    preg_match_all('/\/\*\*((?:(?!\*\/).)*)\*\/\s*function\s+(\w+_(?:update_\d+|post_update_\w+))\s*\(/s', $code, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $lines = array_values(array_filter(array_map(fn ($l) => trim(ltrim(trim($l), '*')), explode("\n", $match[1])), fn ($l) => $l !== ''));
      if (count($lines) !== 1) {
        $violations[] = $match[2] . ': docblock has ' . count($lines) . ' lines';
      }
      elseif (str_contains($match[2], '_post_update_') && str_contains($lines[0], '/')) {
        $violations[] = $match[2] . ': post-update summary contains a slash';
      }
    }
    return $violations;
  }

  /**
   * Update hook docblocks are one line.
   */
  public function testUpdateDocblocksAreOneLine(): void {
    $root = dirname(__DIR__, 3);
    $code = (string) file_get_contents($root . '/solo_utilities.install');
    $this->assertGreaterThan(0, preg_match_all('/function\s+\w+_update_\d+\(/', $code), 'Update hooks were found.');
    $this->assertSame([], $this->updateDocblockViolations($code));
  }

  /**
   * The docblock scan can go red.
   */
  public function testUpdateDocblockScanDetectsSeededViolations(): void {
    $code = "<?php\n/**\n * One.\n *\n * Two.\n */\nfunction m_update_8001() {}\n/**\n * Fixes 403/404.\n */\nfunction m_post_update_x() {}\n/**\n * Fine.\n */\nfunction m_update_8002() {}\n";
    $this->assertSame(['m_update_8001: docblock has 2 lines', 'm_post_update_x: post-update summary contains a slash'], $this->updateDocblockViolations($code));
  }

}
