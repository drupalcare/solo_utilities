<?php

declare(strict_types=1);

namespace Drupal\solo_utilities\Render;

use Drupal\Core\Render\Element;

/**
 * The one home of walking form elements to learn what they hold.
 *
 * Every walk has a depth limit, as a form can hold circular references.
 *
 * @internal
 */
final class FormElementInspector {

  /**
   * How deep a walk goes.
   */
  private const MAX_DEPTH = 20;

  public function __construct(
    private readonly AttributeHelper $attributes,
  ) {}

  /**
   * Counts the element types below a container.
   *
   * Beside each #type, it counts a `fieldset` for a child keyed `wrapper`, and
   * a `date-range-field` for a child keyed `min` or `max` or holding both.
   *
   * @param array<mixed> $element
   *   The container element.
   *
   * @return array<string, int>
   *   Counts keyed by type.
   */
  public function fieldTypes(array $element): array {
    $types = [];
    $this->collectFieldTypes($element, $types, 0);
    return $types;
  }

  /**
   * Whether an element holds both a minimum and a maximum field.
   *
   * Looks at direct children and one level below, by child key or by a
   * `[min]` or `[max]` in the child's #name.
   *
   * @param array<mixed> $element
   *   The element.
   */
  public function hasMinMaxFields(array $element): bool {
    $found = ['min' => FALSE, 'max' => FALSE];
    foreach ($this->childKeys($element) as $key) {
      $this->noteMinMaxKey($key, $found);
      if (!is_array($element[$key] ?? NULL)) {
        continue;
      }
      foreach ($this->childKeys($element[$key]) as $child_key) {
        $this->noteMinMaxKey($child_key, $found);
        $name = $element[$key][$child_key]['#name'] ?? '';
        $name = is_array($name) ? reset($name) : $name;
        if (is_string($name)) {
          $found['min'] = $found['min'] || str_contains($name, '[min]');
          $found['max'] = $found['max'] || str_contains($name, '[max]');
        }
      }
    }
    return $found['min'] && $found['max'];
  }

  /**
   * Marks entity reference fields, at any depth, for styling and behaviors.
   *
   * An entity_autocomplete gets data-entity-type, data-bundle and the wrapper
   * class solo-form__entity-ref. A select with #entity_type gets the wrapper class
   * solo-form__entity-select.
   *
   * @param array<mixed> $elements
   *   The form elements, changed in place.
   */
  public function markEntityReferenceFields(array &$elements): void {
    $this->markEntityReferences($elements, 0);
  }

  /**
   * Scalar values of a nested array, flattened; the first of a key wins.
   *
   * For form state values under #tree, where fieldsets add nesting that says
   * nothing about the setting. Arrays are walked and not themselves kept.
   *
   * @param array<mixed> $values
   *   The nested values.
   *
   * @return array<string|int, mixed>
   *   The flat values.
   */
  public function flattenValues(array $values): array {
    $flat = [];
    foreach ($values as $key => $value) {
      if (is_array($value)) {
        $flat += $this->flattenValues($value);
      }
      else {
        $flat[$key] = $value;
      }
    }
    return $flat;
  }

  /**
   * Adds the types below one element to the counts.
   *
   * @param array<mixed> $element
   *   The element.
   * @param array<string, int> $types
   *   The counts so far.
   * @param int $depth
   *   The current depth.
   */
  private function collectFieldTypes(array $element, array &$types, int $depth): void {
    if ($depth >= self::MAX_DEPTH) {
      return;
    }
    foreach ($this->childKeys($element) as $key) {
      $child = $element[$key] ?? NULL;
      if (!is_array($child)) {
        continue;
      }
      $type = $child['#type'] ?? NULL;
      if (is_string($type)) {
        $types[$type] = ($types[$type] ?? 0) + 1;
      }
      if ($key === 'wrapper' || $type === 'fieldset') {
        $types['fieldset'] = ($types['fieldset'] ?? 0) + 1;
      }
      if ($key === 'min' || $key === 'max') {
        $types['date-range-field'] = ($types['date-range-field'] ?? 0) + 1;
      }
      if (isset($child['min'], $child['max'])) {
        $types['date-range-field'] = ($types['date-range-field'] ?? 0) + 1;
      }
      $this->collectFieldTypes($child, $types, $depth + 1);
    }
  }

  /**
   * Notes a child key that names a minimum or maximum field.
   *
   * @param string|int $key
   *   The child key.
   * @param array{min: bool, max: bool} $found
   *   What has been found so far.
   */
  private function noteMinMaxKey(string|int $key, array &$found): void {
    if ($key === 'min' || $key === 'max') {
      $found[$key] = TRUE;
    }
  }

  /**
   * Marks the entity reference fields below one element.
   *
   * @param array<mixed> $elements
   *   The elements, changed in place.
   * @param int $depth
   *   The current depth.
   */
  private function markEntityReferences(array &$elements, int $depth): void {
    if ($depth >= self::MAX_DEPTH) {
      return;
    }
    foreach ($this->childKeys($elements) as $key) {
      if (!is_array($elements[$key] ?? NULL)) {
        continue;
      }
      $type = $elements[$key]['#type'] ?? NULL;
      if ($type === 'entity_autocomplete') {
        $bundles = $elements[$key]['#selection_settings']['target_bundles'] ?? [];
        $elements[$key]['#attributes']['data-entity-type'] = (string) ($elements[$key]['#target_type'] ?? '');
        $elements[$key]['#attributes']['data-bundle'] = is_array($bundles) ? implode(',', array_filter($bundles, 'is_string')) : '';
        $this->attributes->addClasses($elements[$key], 'solo-form__entity-ref', '#wrapper_attributes');
      }
      elseif ($type === 'select' && isset($elements[$key]['#entity_type'])) {
        $this->attributes->addClasses($elements[$key], 'solo-form__entity-select', '#wrapper_attributes');
      }
      $this->markEntityReferences($elements[$key], $depth + 1);
    }
  }

  /**
   * The child keys of an element; none when it holds an invalid child.
   *
   * Element::children() throws on a non-array child. A malformed element from
   * another module must not stop a page over a styling hint.
   *
   * @param array<mixed> $element
   *   The element.
   *
   * @return list<string|int>
   *   The child keys.
   */
  public function childKeys(array $element): array {
    try {
      return Element::children($element);
    }
    catch (\InvalidArgumentException) {
      return [];
    }
  }

}
