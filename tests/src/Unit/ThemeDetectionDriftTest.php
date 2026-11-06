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
 * 1. Only src/Theme/SoloThemeDetection.php walks base themes or reads the
 *    default theme name.
 * 2. Every #[Hook] method in src/Hook states its theme scope in code: its body
 *    asks the detection service (isActive, isDefault, isInHierarchy or
 *    isEdited), or it is on UNSCOPED_HOOKS with the reason it needs no theme
 *    decision. A hook that is added without deciding is red. The hooks no
 *    longer carry a "Theme scope:" docblock line; the code is the statement.
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
      if ($path === 'src/Theme/SoloThemeDetection.php') {
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
    $this->assertNotEmpty($sources['src/Theme/SoloThemeDetection.php'] ?? '', 'The service file was found.');
    $this->assertSame([], array_values(array_diff($this->walkOffences($sources), $allowed)));
  }

  /**
   * The scan can go red: a planted walk outside the service is reported.
   */
  public function testScanDetectsPlantedWalk(): void {
    $sources = [
      'src/Theme/SoloThemeDetection.php' => "<?php \$i['base theme'];",
      'src/Hook/Planted.php' => "<?php \$info->info['base theme'];",
      'src/Hook/PlantedTwo.php' => "<?php \$c->get('system.theme')->get('default');",
    ];
    $this->assertSame(
      ["src/Hook/Planted.php: ['base theme']", "src/Hook/PlantedTwo.php: get('default')"],
      $this->walkOffences($sources)
    );
  }

  /**
   * Hooks that need no theme decision, keyed "File.php:hook", with the reason.
   *
   * Adding an entry is a decision, not a convenience.
   */
  private const UNSCOPED_HOOKS = [
    'AssetHooks.php:library_info_build' => 'Registers the generated libraries; they are only attached by themes that use them.',
    'AssetHooks.php:css_alter' => 'Reorders stylesheets; acts only on libraries the registrar owns.',
    'LibraryHooks.php:library_info_alter' => 'Scoped by the extension name (solo), not by the active theme.',
    'SettingsUpgradeHooks.php:themes_installed' => 'Install-time migration and cache reset; runs for any theme install.',
    'SettingsUpgradeHooks.php:themes_uninstalled' => 'Resets the detection cache after any uninstall.',
    'SettingsUpgradeHooks.php:modules_installed' => 'Install-time migration, scoped by the installed module list.',
    'SettingsUpgradeHooks.php:config_schema_info_alter' => 'Schema alter, scoped by the schema type.',
    'SiteFeatureHooks.php:help' => 'Help page, scoped by route name.',
    'SiteFeatureHooks.php:form_block_form_alter' => 'Scoped by the block title feature flag, not by theme.',
    'UpgradePageHooks.php:theme' => 'Declares the upgrade page theme hook.',
    'UpgradePageHooks.php:modules_installed' => 'Install-time redirect, scoped by the installed module list.',
  ];

  /**
   * Every #[Hook] method states its theme scope in code or is listed.
   */
  public function testEveryHookStatesItsThemeScope(): void {
    $root = dirname(__DIR__, 3);
    $violations = [];
    $unguarded = [];
    $hooks = 0;
    foreach (glob($root . '/src/Hook/*.php') ?: [] as $file) {
      $violations = array_merge($violations, $this->scopeViolations((string) file_get_contents($file), basename($file), $hooks, $unguarded));
    }
    $this->assertGreaterThan(0, $hooks, 'Hooks were found.');
    $this->assertSame([], $violations);
    // Each listed exception still exists and still has no detection call;
    // a stale entry would hide a hook that gained or lost its guard.
    $listed = array_keys(self::UNSCOPED_HOOKS);
    sort($listed);
    sort($unguarded);
    $this->assertSame($listed, $unguarded, 'Every UNSCOPED_HOOKS entry is a live, unguarded hook.');
  }

  /**
   * The scope scan can go red: a hook with no detection call is reported.
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
    $this->other->run();
  }

  /**
   * Implements hook_b().
   */
  #[Hook('b', order: Order::Last)]
  public function b() {
    if ($this->detection->isActive()) {
      $x = 1;
    }
  }

  /**
   * Implements hook_c().
   */
  #[Hook('c')]
  public function c() {
    if (!$this->themeDetection->isDefault()) {
      return;
    }
  }
}
PHP;
    $hooks = 0;
    $unguarded = [];
    $this->assertSame(
      ['P.php: hook "a" makes no theme decision (no isActive/isDefault/isInHierarchy/isEdited call) and is not on UNSCOPED_HOOKS'],
      $this->scopeViolations($code, 'P.php', $hooks, $unguarded)
    );
    $this->assertSame(3, $hooks);
    $this->assertSame(['P.php:a'], $unguarded);
  }

  /**
   * Scope violations of one hook class.
   *
   * @param string $code
   *   The class source.
   * @param string $name
   *   The file name.
   * @param int $hooks
   *   Counts the hooks seen.
   * @param list<string> $unguarded
   *   Collects "File:hook" for every hook with no detection call.
   *
   * @return list<string>
   *   Violations.
   */
  private function scopeViolations(string $code, string $name, int &$hooks, array &$unguarded): array {
    $violations = [];
    preg_match_all('/#\[Hook\(\'([^\']+)\'[^\]]*\]\s*public function \w+\([^)]*\)[^{]*\{(.*?)\n  \}\n/s', $code, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
      $hooks++;
      if (preg_match('/->\w+->(?:isActive|isDefault|isInHierarchy|isEdited)\(/', $match[2])) {
        continue;
      }
      $unguarded[] = $name . ':' . $match[1];
      if (!array_key_exists($name . ':' . $match[1], self::UNSCOPED_HOOKS)) {
        $violations[] = sprintf('%s: hook "%s" makes no theme decision (no isActive/isDefault/isInHierarchy/isEdited call) and is not on UNSCOPED_HOOKS', $name, $match[1]);
      }
    }
    return $violations;
  }

}
