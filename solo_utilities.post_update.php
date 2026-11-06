<?php

/**
 * @file
 * Post-update functions for Solo Utilities.
 */

declare(strict_types=1);

use Drupal\Core\Utility\UpdateException;
use Drupal\solo_utilities\Migration\StoredClassMigrator;
use Drupal\solo_utilities\Settings\ChoiceNormalizer;
use Drupal\solo_utilities\Theme\SoloThemeDetection;
use Drupal\solo_utilities\Upgrade\SettingsMigrator;

/**
 * Moves Solo theme settings to their new names, one theme per pass.
 */
function solo_utilities_post_update_migrate_theme_settings(array &$sandbox): string {
  // Core records a post-update as done only when it returns, and aborts the
  // ones after a failure. So a failed theme keeps updb red and a re-run retries
  // it, skipping finished themes by their data. A site that installs this
  // module during the same update gets the migration from hook_modules_installed
  // instead: core marks a new module's post-updates as already run.
  $migrator = \Drupal::service(SettingsMigrator::class);

  if (!isset($sandbox['themes'])) {
    $sandbox['themes'] = $migrator->themes();
    $sandbox['total'] = count($sandbox['themes']);
    $sandbox['failed'] = [];
    $sandbox['summary'] = [];
    $sandbox['unprovable'] = $migrator->reportUnprovable();
  }

  $theme = array_shift($sandbox['themes']);
  if (is_string($theme)) {
    $report = [];
    if ($migrator->tryMigrate($theme, FALSE, $report)) {
      foreach (['unknown' => 'left in place', 'unmovable' => 'not moved'] as $list => $label) {
        if ($report[$list] !== []) {
          $sandbox['summary'][] = $theme . ' ' . $label . ': ' . implode(', ', $report[$list]);
        }
      }
    }
    else {
      $sandbox['failed'][] = $theme;
    }
  }

  if ($sandbox['themes'] !== []) {
    $sandbox['#finished'] = ($sandbox['total'] - count($sandbox['themes'])) / $sandbox['total'];
    return '';
  }
  $sandbox['#finished'] = 1;

  if ($sandbox['failed'] !== []) {
    throw new UpdateException('Solo settings were not migrated for: ' . implode(', ', $sandbox['failed']) . '. Those themes are unchanged; see the log, then run updates again.');
  }

  $lines = ['Solo settings migrated for ' . $sandbox['total'] . ' theme(s).'];
  if ($sandbox['unprovable'] !== []) {
    $lines[] = 'No provable theme on disk, not touched: ' . implode(', ', $sandbox['unprovable']);
  }
  return implode(' ', array_merge($lines, $sandbox['summary']));
}

/**
 * Renames Solo 1 classes in the inline CSS setting of Solo and its sub-themes.
 */
function solo_utilities_post_update_rename_classes_in_inline_css(): string {
  // Runs after migrate_theme_settings (post-updates run in name order), so
  // the setting is already at its Solo 2 key, custom_code.inline_css. Only
  // this theme-owned setting changes here; theme files, Block Class, Views,
  // text formats and content are the site's and change only through
  // drush solo:migrate-classes, which reports before it writes.
  try {
    $rows = StoredClassMigrator::fromContainer()->inlineCss(TRUE);
  }
  catch (\RuntimeException $e) {
    throw new UpdateException('Solo inline CSS classes were not renamed: ' . $e->getMessage() . ' Nothing was changed; run updates again.');
  }
  $renamed = count(array_filter($rows, static fn (array $row): bool => $row['note'] === ''));
  $review = count($rows) - $renamed;
  $message = 'Solo inline CSS: ' . $renamed . ' line(s) renamed.';
  if ($review > 0) {
    $message .= ' ' . $review . ' item(s) to check by hand: run drush solo:migrate-classes <theme> --stored to list them.';
  }
  return $message;
}

/**
 * Renames the Color Schemes Rules permissions to lowercase names.
 */
function solo_utilities_post_update_lowercase_color_schemes_permissions(): string {
  // Each role keeps exactly what it had, under the new name. Reads the raw
  // permission list, not hasPermission(), so an admin role (which answers
  // TRUE for everything) is not given explicit grants it never had. A second
  // run finds no old names and changes nothing.
  $renames = [
    'administer Color Schemes Rules' => 'administer color schemes rules',
    'view Color Schemes Rules' => 'view color schemes rules',
    'create Color Schemes Rules' => 'create color schemes rules',
    'edit Color Schemes Rules' => 'edit color schemes rules',
    'delete Color Schemes Rules' => 'delete color schemes rules',
  ];
  $changed = [];
  $skipped = [];
  /** @var \Drupal\user\RoleInterface[] $roles */
  $roles = \Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple();
  foreach ($roles as $role) {
    $held = $role->getPermissions();
    $moved = [];
    foreach ($renames as $old => $new) {
      if (in_array($old, $held, TRUE)) {
        $role->revokePermission($old);
        $role->grantPermission($new);
        $moved[] = $new;
      }
    }
    if ($moved === []) {
      continue;
    }
    // A role that also holds a permission no module defines any more cannot
    // be saved. Skip it and carry on: it keeps the old names, which are still
    // defined and still grant access, and a later run retries it.
    try {
      $role->save();
      $changed[] = $role->id() . ' (' . implode(', ', $moved) . ')';
    }
    catch (\Throwable $e) {
      \Drupal::logger('solo_utilities')->error('Color Schemes Rules permissions were not renamed on role @role: @message', [
        '@role' => $role->id(),
        '@message' => $e->getMessage(),
      ]);
      $skipped[] = (string) $role->id();
    }
  }

  // The stored entity type definition still names the old admin permission;
  // bring it in line with the code so the installed copy does not drift.
  $update_manager = \Drupal::entityDefinitionUpdateManager();
  $installed = $update_manager->getEntityType('color_schemes_rule');
  $definition_updated = FALSE;
  if ($installed !== NULL && $installed->getAdminPermission() !== 'administer color schemes rules') {
    $installed->set('admin_permission', 'administer color schemes rules');
    $update_manager->updateEntityType($installed);
    $definition_updated = TRUE;
  }

  $message = $changed === []
    ? 'Color Schemes Rules permissions: no role held an old name.'
    : 'Color Schemes Rules permissions renamed on ' . count($changed) . ' role(s): ' . implode('; ', $changed) . '.';
  if ($skipped !== []) {
    $message .= ' Not renamed, see the log: ' . implode(', ', $skipped) . '. These roles keep the deprecated names, which still work.';
  }
  if ($definition_updated) {
    $message .= ' The color_schemes_rule admin permission is now administer color schemes rules.';
  }
  return $message;
}

/**
 * Keeps the color scheme each page shows now that rules sort by weight.
 */
function solo_utilities_post_update_color_schemes_rule_weights(): string {
  // Rules used to run in the order loadMultiple() returned them and ignored
  // their weight. They now run by weight, then label. Where two or more rules
  // share a weight, the label would decide, which can differ from the old
  // order. This renumbers every rule 0, 1, 2 ... by (weight, old position), so
  // tied rules keep their old relative order and rules with different weights
  // keep theirs. It runs only while a tie exists; afterwards there is none, so
  // a second run changes nothing.
  $storage = \Drupal::entityTypeManager()->getStorage('color_schemes_rule');
  $rules = $storage->loadMultiple();
  $weights = [];
  foreach ($rules as $rule) {
    $weights[] = (int) $rule->get('weight');
  }
  if (count($weights) === count(array_unique($weights))) {
    return 'Color Schemes Rules: no two rules share a weight; nothing changed.';
  }

  $order = [];
  $position = 0;
  foreach ($rules as $id => $rule) {
    $order[] = ['id' => $id, 'weight' => (int) $rule->get('weight'), 'position' => $position++];
  }
  usort($order, static fn (array $a, array $b): int => [$a['weight'], $a['position']] <=> [$b['weight'], $b['position']]);

  $changed = [];
  foreach ($order as $new_weight => $item) {
    $rule = $rules[$item['id']];
    if ((int) $rule->get('weight') !== $new_weight) {
      $changed[] = $item['id'] . ' ' . $item['weight'] . ' to ' . $new_weight;
      $rule->set('weight', $new_weight);
      $rule->save();
    }
  }
  return 'Color Schemes Rules renumbered to keep their order: ' . implode(', ', $changed) . '.';
}

/**
 * Resets Solo settings values that are not an allowed choice to their default.
 */
function solo_utilities_post_update_normalise_choice_settings(): string {
  // Idempotent: a value already allowed is never touched, so a second run
  // finds nothing. The rule lives in ChoiceNormalizer, shared with the 1.x
  // migration and the config import transform. Each reset is logged there.
  $normalizer = \Drupal::service(ChoiceNormalizer::class);
  $themes = array_keys(\Drupal::service(SoloThemeDetection::class)->installedThemes());
  $count = 0;
  foreach ($themes as $theme) {
    $config = \Drupal::configFactory()->getEditable($theme . '.settings');
    if ($config->isNew()) {
      continue;
    }
    $result = $normalizer->normalize($config->getRawData(), $theme . '.settings');
    foreach ($result['changes'] as $path => $change) {
      $config->set($path, $change['new']);
    }
    if ($result['changes'] !== []) {
      $config->save();
      $count += count($result['changes']);
    }
  }
  return 'Solo settings: ' . $count . ' value(s) outside the allowed choices reset to their default.';
}

/**
 * Raises a stored popup login z-index that is below the sidebar menu layer.
 */
function solo_utilities_post_update_raise_popup_login_z_index(): string {
  // The popup z-index now has a floor of 5002, above the primary sidebar menu
  // (5001) and the fixed search and cookies (5000). A stored value below the
  // floor is set to 10000, the install default. Idempotent: a value at or above
  // the floor is never touched, and 10000 is above it, so a second run finds
  // nothing. A missing or non-numeric value is left alone.
  $floor = 5002;
  $themes = array_keys(\Drupal::service(SoloThemeDetection::class)->installedThemes());
  $changed = [];
  foreach ($themes as $theme) {
    $config = \Drupal::configFactory()->getEditable($theme . '.settings');
    if ($config->isNew()) {
      continue;
    }
    $value = $config->get('popup_login.z_index');
    if (is_numeric($value) && (int) $value < $floor) {
      $config->set('popup_login.z_index', 10000)->save();
      $changed[] = $theme . ' ' . (int) $value;
    }
  }
  return 'Solo popup login z-index raised to 10000 where it was below ' . $floor . ': ' . ($changed === [] ? 'none' : implode(', ', $changed)) . '.';
}
