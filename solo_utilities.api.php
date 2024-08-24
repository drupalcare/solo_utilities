<?php

/**
 * @file
 * API documentation and hook implementations for the Solo Utilities module.
 */

/**
 * Implements hook_color_schemes_rule_info_alter().
 *
 * Allows modules to alter the information about Color Schemes Rules.
 *
 * @see hook_color_schemes_rule_info()
 */
function solo_utilities_color_schemes_rule_info_alter(array &$rules) {
  // Example: Add a new condition to all existing Color Schemes Rules.
  foreach ($rules as &$rule) {
    $rule['conditions'][] = 'new_condition';
  }
}

/**
 * Defines Color Schemes Rules.
 *
 * @return array
 *   An associative array containing information about Color Schemes Rules.
 *
 * @see hook_color_schemes_rule_info_alter()
 */
function solo_utilities_color_schemes_rule_info() {
  $rules = [];

  // Example rule definition.
  $rules['example_rule'] = [
    'label' => t('Example Rule'),
    'library' => 'module_name/library_name',
    'conditions' => [
      'node_type' => ['page'],
      'user_role' => ['authenticated'],
    ],
  ];

  return $rules;
}
