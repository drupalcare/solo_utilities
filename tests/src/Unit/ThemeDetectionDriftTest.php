<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Solo theme detection has one home, and every hook states its theme scope.
 *
 * WHY THIS EXISTS. One source of truth needs a check that can go red. Two
 * rules are enforced by reading source as text:
 *
 * 1. Only src/Service/SoloThemeDetection.php walks base themes or reads the
 *    default theme name.
 * 2. Every #[Hook] method in src/Hook carries a "Theme scope: none|default|
 *    active." line. A hook that is added without deciding is red. A hook
 *    scoped "active" must start with the guard, because a guard on the wrong
 *    theme silently removes a feature (the node width field renders under the
 *    admin theme).
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
class ThemeDetectionDriftTest extends TestCase {

  /**
   * Module source files, as text, keyed by path relative to the module.
   *
   * @return array<string, string>
   *   Contents keyed by relative path.
   */
  private function sources(string $root): array {
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      if ($file->getExtension() === 'php') {
        $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
      }
    }
    foreach (['solo_utilities.module', 'solo_utilities.install'] as $name) {
      $files[$name] = (string) file_get_contents($root . '/' . $name);
    }
    return $files;
  }

  /**
   * Finds the offending relative paths for the walk and default-name rules.
   *
   * @param array<string, string> $sources
   *   Contents keyed by relative path.
   *
   * @return list<string>
   *   Offences.
   */
  private function walkOffences(array $sources): array {
    $offences = [];
    foreach ($sources as $path => $code) {
      if ($path === 'src/Service/SoloThemeDetection.php') {
        continue;
      }
      if (preg_match("/\\['base theme'\\]|getBaseThemeExtensions|get\\('default'\\)/", $code, $match)) {
        // The block migrator and install file read other configuration; only a
        // system.theme default read or a base theme walk counts.
        if ($match[0] === "get('default')" && !preg_match("/system\\.theme'\\)\\s*->get\\('default'\\)/", $code)) {
          continue;
        }
        $offences[] = $path . ': ' . $match[0];
      }
    }
    return $offences;
  }

  /**
   * Nothing outside the service walks base themes or reads the default theme.
   *
   * There are no exceptions: the uninstall cleanup and the block title
   * migrator both ask the service now.
   */
  public function testOnlyTheServiceWalksTheHierarchy(): void {
    $root = dirname(__DIR__, 3);
    $sources = $this->sources($root);
    $allowed = [];
    $this->assertNotEmpty($sources['src/Service/SoloThemeDetection.php'] ?? '', 'The service file was found.');
    $this->assertSame([], array_values(array_diff($this->walkOffences($sources), $allowed)));
  }

  /**
   * The scan can go red: a planted walk outside the service is reported.
   */
  public function testScanDetectsPlantedWalk(): void {
    $sources = [
      'src/Service/SoloThemeDetection.php' => "<?php \$i['base theme'];",
      'src/Hook/Planted.php' => "<?php \$info->info['base theme'];",
      'src/Hook/PlantedTwo.php' => "<?php \$c->get('system.theme')->get('default');",
    ];
    $this->assertSame(
      ["src/Hook/Planted.php: ['base theme']", "src/Hook/PlantedTwo.php: get('default')"],
      $this->walkOffences($sources)
    );
  }

  /**
   * Every #[Hook] method states its theme scope; "active" starts guarded.
   */
  public function testEveryHookStatesItsThemeScope(): void {
    $root = dirname(__DIR__, 3);
    $violations = [];
    $hooks = 0;
    foreach (glob($root . '/src/Hook/*.php') ?: [] as $file) {
      $violations = array_merge($violations, $this->scopeViolations((string) file_get_contents($file), basename($file), $hooks));
    }
    $this->assertGreaterThan(0, $hooks, 'Hooks were found.');
    $this->assertSame([], $violations);
  }

  /**
   * The scope scan can go red: a hook without a scope, and a bad guard.
   */
  public function testScopeScanDetectsPlantedViolations(): void {
    $code = <<<'PHP'
<?php
class P {
  /**
   * Implements hook_a().
   */
  #[Hook('a')]
  public function a() {
  }

  /**
   * Theme scope: active. Test.
   */
  #[Hook('b')]
  public function b() {
    $x = 1;
  }

  /**
   * Theme scope: active. Test.
   */
  #[Hook('c')]
  public function c() {
    if (!$this->detection->isActive()) {
      return;
    }
  }
}
PHP;
    $hooks = 0;
    $this->assertSame(
      [
        'P.php: hook "a" has no Theme scope line',
        'P.php: hook "b" is scoped active but does not start with the isActive() guard',
      ],
      $this->scopeViolations($code, 'P.php', $hooks)
    );
    $this->assertSame(3, $hooks);
  }

  /**
   * Scope violations of one hook class.
   *
   * @return list<string>
   *   Violations.
   */
  private function scopeViolations(string $code, string $name, int &$hooks): array {
    $violations = [];
    preg_match_all('/\/\*\*((?:(?!\*\/).)*?)\*\/\s*#\[Hook\(\'([^\']+)\'[^\]]*\]\s*public function \w+\([^)]*\)[^{]*\{\s*(.*?)\n/s', $code, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $hooks++;
      if (!preg_match('/Theme scope: (none|default|active)\./', $match[1], $scope)) {
        $violations[] = sprintf('%s: hook "%s" has no Theme scope line', $name, $match[2]);
        continue;
      }
      if ($scope[1] === 'active' && !str_contains($match[3], 'isActive()')) {
        $violations[] = sprintf('%s: hook "%s" is scoped active but does not start with the isActive() guard', $name, $match[2]);
      }
    }
    return $violations;
  }

}
