<?php

/**
 * @file
 * Post-update functions for Solo Utilities.
 */

declare(strict_types=1);

use Drupal\Core\Utility\UpdateException;
use Drupal\solo_utilities\Migration\StoredClassMigrator;
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
