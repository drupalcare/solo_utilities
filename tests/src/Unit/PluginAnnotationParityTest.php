<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every plugin or entity attribute in src/ has the annotation beside it.
 *
 * WHY THIS EXISTS. This module claims ^10. Drupal 10 discovers entity types
 * from annotations only (and every plugin type on 10.0 and 10.1), so a class
 * that carries only a #[...] definition attribute does not exist there. 1.0.7
 * shipped exactly that for both entity types. This scan goes red on any class
 * in src/ that repeats the mistake, for any plugin type, on any core.
 *
 * It reads source rather than booting Drupal so that it runs everywhere, and
 * it was proven red against the 1.0.7 entity classes before it was trusted.
 *
 * @group solo_utilities
 *
 * @license SPDX-License-Identifier: GPL-2.0-or-later
 */
#[Group('solo_utilities')]
class PluginAnnotationParityTest extends UnitTestCase {

  /**
   * Attribute namespaces that are not plugin definitions.
   */
  private const NOT_PLUGIN_ATTRIBUTES = [
    'Drupal\Core\Hook\Attribute\\',
  ];

  /**
   * Each attributed plugin class also carries the matching annotation.
   */
  public function testEveryDefinitionAttributeHasAnAnnotation(): void {
    $src = dirname(__DIR__, 3) . '/src';
    $this->assertDirectoryExists($src);
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));

    $attributed = [];
    $missing = [];
    foreach ($iterator as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      foreach ($this->definitionAttributes((string) file_get_contents($file->getPathname())) as [$short, $docblock]) {
        $attributed[] = $file->getFilename() . ' #[' . $short . ']';
        if (!preg_match('/@' . preg_quote($short, '/') . '\s*\(/', $docblock)) {
          $missing[] = substr($file->getPathname(), strlen($src) + 1) . ' has #[' . $short . '] but no @' . $short . '(...) annotation';
        }
      }
    }
    // A scan that finds nothing proves nothing: both entity classes are
    // attributed, so the scan must see at least those two.
    $this->assertGreaterThanOrEqual(2, count($attributed), 'The scan sees the attributed classes: ' . implode(', ', $attributed));
    $this->assertSame([], $missing, 'Drupal 10 cannot discover these classes.');
  }

  /**
   * Finds class-level definition attributes and the docblock before them.
   *
   * @return list<array{string, string}>
   *   Pairs of attribute short name and the class docblock.
   */
  private function definitionAttributes(string $source): array {
    if (!preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+\w+/m', $source, $class, PREG_OFFSET_CAPTURE)) {
      return [];
    }
    $head = substr($source, 0, $class[0][1]);
    $docblock_end = strrpos($head, '*/');
    $docblock = '';
    $between = $head;
    if ($docblock_end !== FALSE) {
      $docblock_start = strrpos(substr($head, 0, $docblock_end), '/**');
      $docblock = $docblock_start === FALSE ? '' : substr($head, $docblock_start, $docblock_end + 2 - $docblock_start);
      $between = substr($head, $docblock_end + 2);
    }
    preg_match_all('/^use\s+([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/m', $source, $uses, PREG_SET_ORDER);
    $imports = [];
    foreach ($uses as $use) {
      $alias = $use[2] ?? '';
      $imports[$alias !== '' ? $alias : substr(strrchr('\\' . $use[1], '\\'), 1)] = $use[1];
    }

    $found = [];
    preg_match_all('/^\s*#\[\\\\?([\w\\\\]+)/m', $between, $attributes);
    foreach ($attributes[1] as $name) {
      $first = explode('\\', $name)[0];
      $fqcn = isset($imports[$first]) ? $imports[$first] . substr($name, strlen($first)) : $name;
      if (!str_contains($fqcn, '\\Attribute\\')) {
        continue;
      }
      foreach (self::NOT_PLUGIN_ATTRIBUTES as $prefix) {
        if (str_starts_with($fqcn, $prefix)) {
          continue 2;
        }
      }
      $found[] = [substr(strrchr('\\' . $fqcn, '\\'), 1), $docblock];
    }
    return $found;
  }

}
