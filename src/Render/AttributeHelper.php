<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Render;

use Drupal\Core\Template\Attribute;

/**
 * The one home of class handling on attributes in render arrays and variables.
 *
 * A class entry may be missing, a string, an array, or the value inside an
 * Attribute object, depending on who ran first. Callers no longer care.
 *
 * To reach a nested element, pass that element: addClasses($variables['label'],
 * 'x', '#attributes').
 *
 * @internal
 */
final class AttributeHelper {

  /**
   * Makes the class entry of an attributes array a list.
   *
   * An Attribute object is left alone: it manages its own class list.
   *
   * @param array<mixed> $parent
   *   The array holding the attributes.
   * @param string $key
   *   The key of the attributes under $parent.
   */
  public function ensureClassArray(array &$parent, string $key = 'attributes'): void {
    if (($parent[$key] ?? NULL) instanceof Attribute) {
      return;
    }
    if (!is_array($parent[$key] ?? NULL)) {
      $parent[$key] = [];
    }
    $parent[$key]['class'] = $this->classList($parent[$key]['class'] ?? []);
  }

  /**
   * Adds classes, without repeating one that is already there.
   *
   * @param array<mixed> $parent
   *   The array holding the attributes.
   * @param string|list<string> $classes
   *   The classes to add.
   * @param string $key
   *   The key of the attributes under $parent.
   */
  public function addClasses(array &$parent, string|array $classes, string $key = 'attributes'): void {
    $classes = $this->classList($classes);
    $this->ensureClassArray($parent, $key);
    if ($parent[$key] instanceof Attribute) {
      $parent[$key]->addClass($classes);
      return;
    }
    $parent[$key]['class'] = array_values(array_unique([...$parent[$key]['class'], ...$classes]));
  }

  /**
   * Normalizes any stored class value to a list of single class names.
   *
   * @param mixed $value
   *   A string of space-separated names, or an array of such strings.
   *
   * @return list<string>
   *   The names. Anything not a string is dropped.
   */
  public function classList(mixed $value): array {
    $names = [];
    foreach (is_array($value) ? $value : [$value] as $entry) {
      if (is_string($entry)) {
        array_push($names, ...(preg_split('/\s+/', $entry, -1, PREG_SPLIT_NO_EMPTY) ?: []));
      }
    }
    return $names;
  }

}
