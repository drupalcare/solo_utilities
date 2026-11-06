# Changelog

All notable changes to Solo Utilities are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The last 1.x release is 1.0.10.

## [2.0.0] - Unreleased

- Extend and Appearance pages: every module and theme description is a short, professional statement that begins with a verb; credits, links and version notes moved out of the info files.

### Changed
- Text follows the drupal.org standards: the module description begins with a verb, settings form titles are in sentence case without "Tick this box to", settings say "content" instead of "node" and "site" instead of "Drupal", "YouTube" and "LinkedIn" are spelled as the brands spell them, and the README follows the drupal.org README template.
- Comments: a relative comment date ("5 minutes ago") now expires from the render cache when its text would change, instead of staying frozen.
- Comments: the heading level and the form position settings only accept the values the settings form offers (a Choice constraint in Solo's schema), because the heading level is printed as a tag name.
- The per-node width field on the node form and the block title visibility options read Solo 2's setting names (`layout.width.per_node_enabled`, `blocks.title_visibility_enabled`). Before, they read the 1.x names, so turning either setting on in Solo 2 had no effect.
- `drush solo:subtheme` no longer warns that the new theme's info file still names the starter: the `generator:` line core writes there records where the theme came from and is not a missed rename.
- Body classes: `path-node-N`, `path-taxonomy-term-N` and `path-vocabulary-X` are added on every page that carries the node or term (edit form, revisions), as in 1.x. Before, only the view page had them.
- The menu keyboard library is attached after the breakpoint libraries again, as in 1.x.
- A popup login background color stored without its `#` is read again, as in 1.x.
- `StoredClassMigrator::fromContainer()` is removed; the post-update builds the migrator from its services. Classes that are not public API are marked `@internal`.

- The preloader's inline critical CSS hides the preloader when scripting is off, so a visitor without JavaScript never sees a page stuck behind it.
- `drush solo:migrate-classes --content` renames an id only in a same-page link (`href="#id"`); a link to an old id on another page or site is listed as `other-page-link` for review instead of being renamed.
- Requires Drupal `^11.3 || ^12` and PHP 8.3.
- Holds all of the Solo theme's PHP: preprocess, theme suggestions, theme settings form sections (one class per area), generated CSS and feature classes. Solo 2 keeps only templates, CSS, JavaScript and settings data.
- Solo 2 requires this module.
- Solo and this module work without the Search module, which Drupal 12 core no longer ships.
- JavaScript follows Drupal core's ESLint and Prettier rules. As in core's own JavaScript, files no longer start with `'use strict';`.
- Spelling: a project word file lets the spell-check job pass; comments use American English.
- The footer copyright shows the site name in place of the shipped "Company Name" text (saved text you changed prints as typed), and the search block placeholder is the short, translatable "Search".

### Changed (sub-theme generator and entity definition)

- Help page: follows the drupal.org help text standard (translatable About and Uses sections) instead of printing README.md as raw text.
- `drush solo:subtheme` keeps its name, arguments and options, and now runs core's `generate-theme` command on Solo's `solo_subtheme` starterkit (the theme ships `solo_subtheme.starterkit.yml` and a `StarterKit` class).
- The Color Schemes Rule and Node Width entity types are declared by their attributes only; the Drupal 10 annotations are gone.

### Changed (Color Schemes Rules)

- Rules are applied in the weight order set on the admin list (then by label). Before, the weight was ignored. `drush updatedb` renumbers rules that share a weight so they keep their earlier order.
- A page now varies by what each rule's conditions depend on (role, path and so on), so a cached page no longer shows the wrong color scheme.
- The five permissions have lowercase names, such as `administer color schemes rules`. `drush updatedb` moves each role's grants to the new names. The old names stay defined, marked deprecated, and keep working until 3.0.0. The entity's admin permission is now `administer color schemes rules` instead of `administer site configuration`.
- `ColorSchemesNegotiator::evaluateRules()` is deprecated; use `negotiate()`, which returns the scheme with its cache metadata.

### Added

- Theme detection, block title, node width and readme services.
- A readiness scanner behind `drush solo:preflight`, which lists what a sub-theme or custom module must change for Solo 2.
- The Solo upgrade page at `/admin/config/system/solo-upgrade` (**Administer themes** permission), with **Move settings to the new names** and **Restore earlier settings** actions.
- `drush solo:settings-restore` to write the earlier settings back under the old names.
- `drush solo:migrate-classes` and `drush solo:migrate-classes-rollback` to rename Solo 1 classes and ids and to undo the run.
- The `solo_utilities_compat` sub-module for custom code that still reads Solo settings by their old names or calls the Solo 1 helper functions.

### Upgrade notes

- Settings are migrated from the Solo 1 names to the Solo 2 names by `drush updatedb`; the earlier settings are kept in the database until restored or replaced.
- After `drush updatedb`, export configuration (`drush config:export`): the role files in your sync directory still list the old Color Schemes Rules permission names, and importing them unchanged re-adds the deprecated names; export after updb to drop them.
- Export your configuration after the migration (`drush config:export`).
- Full route, rollback and the four things the update cannot change for you: see the Solo README, "Upgrading from Solo 1".
