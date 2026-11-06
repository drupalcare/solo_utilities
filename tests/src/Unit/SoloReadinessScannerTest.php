<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\solo_utilities\Service\SoloReadinessScanner;
use Drupal\solo_utilities\Theme\SoloThemeDetectionInterface;
use Drupal\solo_utilities\Upgrade\SettingsMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Solo 2 readiness scanner lists what a sub-theme must change.
 *
 * WHY THIS EXISTS
 * A clean sub-theme must produce no finding, and a sub-theme that calls Solo's
 * PHP, includes a Solo template, overrides a Solo library or copies a Solo
 * template must be listed with the file and line. The fixtures are written to
 * a temporary directory, because a .info.yml inside a module's own tree would
 * be discovered by core as a real theme. Solo 2 ships no PHP functions, so the
 * function names come from the list this module ships; the fixture root holds
 * a copy of that list.
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @group solo_utilities
 */
#[Group('solo_utilities')]
class SoloReadinessScannerTest extends TestCase {

  /**
   * The temporary root holding the fixtures.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/solo_scanner_' . bin2hex(random_bytes(6));
    $this->write('solo_utilities/config/map/solo1-function-names.yml', "functions:\n  - solo_helper_a\n  - _solo_private_b\n  - solo_in_include\n");
    $this->write('solo/templates/page.html.twig', '{{ page }}');
    $this->write('solo/templates/layout/block.html.twig', '{{ content }}');

    $this->write('clean/clean.info.yml', "name: Clean\ntype: theme\nbase theme: solo\nlibraries-override:\n  core/drupal.dialog: false\n");
    $this->write('clean/clean.theme', "<?php\n\n/**\n * Mentions solo_helper_a() only in a comment.\n */\nfunction clean_preprocess_page(&\$variables) {\n  \$variables['x'] = 1;\n}\n");
    $this->write('clean/templates/node.html.twig', '{{ content }}');
    $this->write('clean/node_modules/pkg/skipped.theme', "<?php\nsolo_helper_a();\n");

    $this->write('bad/bad.info.yml', "name: Bad\ntype: theme\nbase theme: solo\nlibraries-override:\n  solo/global-styling: bad/replacement\n  core/drupal.dialog: false\n");
    $this->write('bad/bad.theme', "<?php\n\nfunction bad_preprocess_page(&\$variables) {\n  solo_helper_a();\n  \\_solo_private_b(1);\n  \$variables['f'] = 'solo_in_include';\n  \$this->solo_helper_a();\n  Foo::solo_helper_a();\n}\n\nfunction solo_helper_a_extra() {}\n");
    $this->write('bad/templates/page.html.twig', "{% include '@solo/partials/header.html.twig' %}\n");
    $this->write('bad/templates/deep/block.html.twig', '{{ content }}');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->remove($this->root);
    parent::tearDown();
  }

  /**
   * A sub-theme that uses none of the four is not listed.
   */
  public function testCleanSubthemeHasNoFinding(): void {
    $this->assertSame([], $this->scanner()->scanDirectory($this->root . '/clean', $this->root . '/solo'));
  }

  /**
   * A bad sub-theme is listed once per use, with file and line.
   */
  public function testBadSubthemeIsListed(): void {
    $findings = $this->scanner()->scanDirectory($this->root . '/bad', $this->root . '/solo');
    $seen = array_map(static fn(array $f): string => implode('|', [$f['type'], $f['file'], $f['line']]), $findings);

    sort($seen);
    $this->assertSame([
      'copied_template|templates/deep/block.html.twig|0',
      'copied_template|templates/page.html.twig|0',
      'function_call|bad.theme|4',
      'function_call|bad.theme|5',
      'function_call|bad.theme|6',
      'library_override|bad.info.yml|5',
      'solo_include|templates/page.html.twig|1',
    ], $seen);
    // Methods, static calls, longer names and comments are not calls.
    $this->assertCount(3, array_filter($findings, static fn(array $f): bool => $f['type'] === 'function_call'));
  }

  /**
   * Solo 1's function names come from the list the module ships.
   */
  public function testFunctionNamesAreReadFromTheShippedList(): void {
    $names = $this->scanner()->soloFunctionNames();
    sort($names);
    $this->assertSame(['_solo_private_b', 'solo_helper_a', 'solo_in_include'], $names);
  }

  /**
   * A missing list is an error, never an empty list that reports all clear.
   */
  public function testMissingFunctionListThrows(): void {
    unlink($this->root . '/solo_utilities/config/map/solo1-function-names.yml');
    $this->expectException(\RuntimeException::class);
    $this->scanner()->soloFunctionNames();
  }

  /**
   * Scanning writes nothing.
   */
  public function testScanIsReadOnly(): void {
    $before = $this->snapshot();
    $this->scanner()->scanDirectory($this->root . '/bad', $this->root . '/solo');
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * Builds the scanner; scanDirectory() needs only the function list path.
   */
  private function scanner(): SoloReadinessScanner {
    $module_list = $this->createMock(ExtensionList::class);
    $module_list->method('getPath')->with('solo_utilities')->willReturn('solo_utilities');
    return new SoloReadinessScanner(
      $this->createMock(SoloThemeDetectionInterface::class),
      $this->createMock(ExtensionList::class),
      $this->createMock(ModuleHandlerInterface::class),
      $this->root,
      new SettingsMap($this->createMock(CacheBackendInterface::class)),
      $this->createMock(ConfigFactoryInterface::class),
      $module_list,
    );
  }

  /**
   * Writes a fixture file.
   */
  private function write(string $relative, string $contents): void {
    $path = $this->root . '/' . $relative;
    if (!is_dir(dirname($path))) {
      mkdir(dirname($path), 0777, TRUE);
    }
    file_put_contents($path, $contents);
  }

  /**
   * Returns every path and content hash under the root.
   *
   * @return array<string, string>
   *   Content hashes keyed by path.
   */
  private function snapshot(): array {
    $all = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
      $all[$file->getPathname()] = md5_file($file->getPathname());
    }
    ksort($all);
    return $all;
  }

  /**
   * Removes a directory tree.
   */
  private function remove(string $path): void {
    if (!is_dir($path)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
  }

}
