<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Procedural code must never fetch this module's own classes as services.
 *
 * WHY THIS EXISTS. Issue #3628961 comment #7: "You have requested a
 * non-existent service Drupal\solo_utilities\Hook\SoloUtilitiesHooks". The
 * SoloUtilitiesHooks service is declared in solo_utilities.services.yml from
 * 1.0.7 on; it did not exist in 1.0.6. After updating the files, a site keeps
 * its 1.0.6 container until a cache rebuild, and every #[LegacyHook] wrapper
 * in the .module called \Drupal::service(SoloUtilitiesHooks::class) - so
 * every page answered HTTP 500 until `drush cr` or `drush updb`.
 *
 * The wrappers now use \Drupal::classResolver(), which returns the service
 * when the container has it and instantiates the class itself when it does
 * not. The same fix shipped in the Solo theme 1.0.36.
 *
 * WHAT THIS ENFORCES. No \Drupal::service() call in *.module or *.inc names a
 * class in the Drupal\solo_utilities namespace. Core services, such as
 * ThemeSettingsProvider, are not flagged: `use` statements are resolved, so
 * the check is about the namespace, not the name. It covers all seven
 * wrappers, including those the kernel test does not invoke.
 *
 * Red-proofed against the 1.0.8 .module: 7 calls flagged. Reads files as
 * text, so it runs on every core version.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
class HookClassResolutionTest extends TestCase {

  /**
   * The number of #[LegacyHook] wrappers that resolve the Hook class.
   */
  private const EXPECTED_WRAPPERS = 7;

  /**
   * Procedural module files: the .module and any .inc in the module root.
   *
   * @return list<string>
   *   Absolute paths.
   */
  private function proceduralFiles(): array {
    $root = dirname(__DIR__, 3);
    return array_merge(
      glob($root . '/*.module') ?: [],
      glob($root . '/*.inc') ?: [],
    );
  }

  /**
   * Maps each imported short class name to its fully qualified name.
   *
   * @return array<string, string>
   *   FQCN keyed by the short name (or alias) used in the file.
   */
  private function imports(string $code): array {
    $map = [];
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $code, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $fqcn = ltrim($match[1], '\\');
      $short = $match[2] ?? '';
      if ($short === '') {
        $parts = explode('\\', $fqcn);
        $short = end($parts);
      }
      $map[$short] = $fqcn;
    }
    return $map;
  }

  /**
   * No wrapper asks the container for a class the module itself defines.
   */
  public function testNoContainerLookupOfModuleOwnClasses(): void {
    $offenders = [];
    $resolved = 0;
    foreach ($this->proceduralFiles() as $file) {
      $code = (string) file_get_contents($file);
      $imports = $this->imports($code);
      preg_match_all('/\\\\Drupal::(service|classResolver)\(\s*\\\\?([\w\\\\]+)::class\s*\)/', $code, $calls, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
      foreach ($calls as $call) {
        $name = $call[2][0];
        $fqcn = str_contains($name, '\\') ? ltrim($name, '\\') : ($imports[$name] ?? $name);
        if (!str_starts_with($fqcn, 'Drupal\\solo_utilities\\')) {
          continue;
        }
        if ($call[1][0] === 'classResolver') {
          $resolved++;
          continue;
        }
        $line = substr_count(substr($code, 0, $call[0][1]), "\n") + 1;
        $offenders[] = sprintf('%s:%d \\Drupal::service(%s::class)', basename($file), $line, $name);
      }
    }

    $this->assertSame([], $offenders, sprintf(
      "A service declared in a new release does not exist in the container of a site updated from an older one until a cache rebuild; use \\Drupal::classResolver() instead. %d offending call(s):\n%s",
      count($offenders),
      implode("\n", $offenders)
    ));
    // A scan that matches nothing proves nothing: a moved file or a broken
    // pattern must go red rather than pass on an empty result.
    $this->assertSame(self::EXPECTED_WRAPPERS, $resolved, sprintf('Found %d classResolver() wrappers; expected %d.', $resolved, self::EXPECTED_WRAPPERS));
  }

}
