# Changelog

All notable changes to Solo Utilities are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The last 1.x release is 1.0.10.

## [2.0.0] - Unreleased

### Changed

- Requires Drupal `^11.3 || ^12` and PHP 8.3.
- Holds all of the Solo theme's PHP: preprocess, theme suggestions, theme settings form sections (one class per area), generated CSS and feature classes. Solo 2 keeps only templates, CSS, JavaScript and settings data.
- Solo 2 requires this module.
- Solo and this module work without the Search module, which Drupal 12 core no longer ships.

### Added

- Theme detection, block title, node width and readme services.
- A readiness scanner behind `drush solo:preflight`, which lists what a sub-theme or custom module must change for Solo 2.
- The Solo upgrade page at `/admin/config/system/solo-upgrade` (**Administer themes** permission), with **Move settings to the new names** and **Restore earlier settings** actions.
- `drush solo:settings-restore` to write the earlier settings back under the old names.
- `drush solo:migrate-classes` and `drush solo:migrate-classes-rollback` to rename Solo 1 classes and ids and to undo the run.
- The `solo_utilities_compat` sub-module for custom code that still reads Solo settings by their old names or calls the Solo 1 helper functions.

### Upgrade notes

- Settings are migrated from the Solo 1 names to the Solo 2 names by `drush updatedb`; the earlier settings are kept in the database until restored or replaced.
- Export your configuration after the migration (`drush config:export`).
- Full route, rollback and the four things the update cannot change for you: see the Solo README, "Upgrading from Solo 1".
