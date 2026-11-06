<?php

declare(strict_types=1);

namespace Drupal\Tests\solo_utilities\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every plugin or entity definition is an attribute; no orphan annotation.
 *
 * WHY THIS EXISTS. The module requires Drupal ^11.3 || ^12, so the attribute is
 * the one definition core reads: annotation discovery is gone in Drupal 12 and
 * an annotation without its attribute would be a class that does not exist
 * there. The old rule (an annotation beside every attribute, for Drupal 10)
 * no longer applies; this scan keeps the single-source rule instead. It goes
 * red on a class whose docblock carries a definition annotation that its
 * attributes do not repeat, and on a scan that sees fewer than two attributed
 * classes (a moved directory or a broken pattern).
 *
 * It reads source rather than booting Drupal so that it runs everywhere.
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
   * Each attributed class has its attribute; no annotation stands alone.
   */
  public function testEveryDefinitionAnnotationHasItsAttribute(): void {
    $src = dirname(__DIR__, 3) . '/src';
    $this->assertDirectoryExists($src);
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));

    $attributed = [];
    $orphans = [];
    foreach ($iterator as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $source = (string) file_get_contents($file->getPathname());
      $shorts = [];
      foreach ($this->definitionAttributes($source) as [$short, $docblock]) {
        $shorts[] = $short;
        $attributed[] = $file->getFilename() . ' #[' . $short . ']';
      }
      foreach ($this->classDocblockAnnotations($source) as $annotation) {
        if (!in_array($annotation, $shorts, TRUE)) {
          $orphans[] = substr($file->getPathname(), strlen($src) + 1) . ' has @' . $annotation . '(...) but no #[' . $annotation . '] attribute';
        }
      }
    }
    // A scan that finds nothing proves nothing: both entity classes are
    // attributed, so the scan must see at least those two.
    $this->assertGreaterThanOrEqual(2, count($attributed), 'The scan sees the attributed classes: ' . implode(', ', $attributed));
    $this->assertSame([], $orphans, 'Drupal 12 cannot discover these classes.');
  }

  /**
   * The scan can go red: an annotation with no attribute is reported.
   */
  public function testScanDetectsSeededOrphanAnnotation(): void {
    $this->assertSame(['ContentEntityType'], $this->classDocblockAnnotations("<?php\n/**\n * X.\n *\n * @api\n *\n * @ContentEntityType(\n *   id = \"x\",\n *   label = @Translation(\"X\"),\n * )\n */\n#[Foo]\nclass A {}\n"));
    $this->assertSame([], $this->classDocblockAnnotations("<?php\n/**\n * X, uses @Translation(\"a\") inline.\n *\n * @api\n */\nclass B {}\n"));
  }

  /**
   * Annotation names that start a line of the class docblock, with a call.
   *
   * @return list<string>
   *   Annotation short names.
   */
  private function classDocblockAnnotations(string $source): array {
    if (!preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+\w+/m', $source, $class, PREG_OFFSET_CAPTURE)) {
      return [];
    }
    $head = substr($source, 0, $class[0][1]);
    $end = strrpos($head, '*/');
    $start = $end === FALSE ? FALSE : strrpos(substr($head, 0, $end), '/**');
    if ($start === FALSE) {
      return [];
    }
    preg_match_all('/^\s*\*\s*@(\w+)\(/m', substr($head, $start, $end - $start), $found);
    return $found[1];
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
