# Solo Utilities

Solo Utilities is a module designed to extend the Solo theme with additional
features and customization options. The initial release of Solo Utilities
introduces the ability to set custom widths for individual nodes, giving site
builders and content creators more control over the presentation of their
content. Future updates will include new features to further extend the Solo
theme.

## Table of Contents

1. [Features](#features)
2. [Requirements](#requirements)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Usage](#usage)
6. [Public API](#public-api)
7. [Future Plans](#future-plans)
8. [Maintainers](#maintainers)
9. [License](#license)

## Features

- **Custom Node Widths**: Set custom widths for individual nodes to tailor the
  display of your content.
- **Create a Solo sub-theme with one command**: `drush solo:subtheme my_theme`
  (Drush 12 or later). See [Create a sub-theme](#create-a-sub-theme).
- **Future Enhancements**: Solo Utilities will be updated with new features
  that extend the Solo theme.

## Requirements

- **Solo theme**: Solo 2 requires this module and holds no PHP logic of its
  own. Solo's upgrade steps are in the Solo README, under "Upgrading from Solo 1".
- **Node module**: Core Drupal module that must be enabled.

## Installation

1. **Download the module**: Place the Solo Utilities module in the
   `modules/custom` directory of your Drupal installation.
2. **Enable the module**:
   - **Using Drush**: Run `drush en solo_utilities -y` in the command line.
   - **Using Drupal Admin Interface**: Navigate to **Extend**, find the **Solo
     Utilities** module, enable it, and save the configuration.

## Configuration

1. Ensure the Solo theme is active and configured correctly.
2. Navigate to the theme settings of the Solo theme.
3. If the Solo Utilities module is enabled, you will see an option to enable
   custom widths for individual nodes.
4. Configure the settings as needed.

## Usage

1. When creating or editing a node, you will see a dropdown to select a custom
   width for the node.
2. Choose the desired width or select **None** to use the default width.

### Create a sub-theme

You need a sub-theme only to override Solo's Twig templates. Theme
settings survive Solo updates, and your own CSS and JavaScript go in
**Global Site Settings > Custom Code**.

```
drush solo:subtheme my_theme
drush theme:install my_theme
drush config:set system.theme default my_theme -y
```

The command finds Solo wherever it is installed, copies its `solo_subtheme`
starter to `themes/custom/my_theme` under your web root, and renames
every file, namespace, function and label to the new name. Add
`--label="My Theme"` to choose the human-readable name.

The machine name follows Drupal's rules: lowercase letters, numbers and
underscores, starting with a letter, at most 50 characters. It must not be
used by another theme, module or profile on the site, or be a name Drupal
reserves (`src`, `lib`, `vendor`, `assets`, `css`, `files`, `images`, `js`,
`misc`, `templates`, `includes`, `fixtures`, `drupal`). A short name unique
to your site works best, for example `mysite_theme`. The command refuses
a name that breaks these rules, and never overwrites an existing folder.

### Check your sub-themes for Solo 2

```
drush solo:preflight
```

Reads every Solo sub-theme on disk and custom module, and lists calls to
Solo's PHP functions, `@solo/` template paths, `libraries-override` entries for Solo
libraries, and templates that copy a Solo template. It writes nothing and
changes no setting. The exit code is 0 when nothing needs a change and 1
when something is listed.

### The Solo upgrade page

Go to **Configuration > System > Solo upgrade**
(`/admin/config/system/solo-upgrade`). It needs the **Administer themes**
permission, and installing this module links to it.

For Solo and every sub-theme on disk, the page shows the base theme, which regions
hold enabled blocks and how many, and whether the settings use the new names. A
green list says what was done automatically. An amber list says what needs you,
with the file and line for custom code. This page never changes sub-theme code.

If a theme is waiting, **Move settings to the new names** runs the migration for
all of them after a confirmation. The earlier settings of each changed theme are
kept in the database.

To go back, use the **Restore earlier settings** button. It appears on the page
only while earlier settings are held. It asks for a confirmation and then writes
those settings back under the old names, for every theme that has a copy. The
same restore is available from the command line:

```
drush solo:settings-restore
```

Run the restore before you roll the code back. It discards settings saved after
the migration, and it cannot be undone. Until you roll back to Solo 1, or move
the settings to the new names again from this page, Solo 2 shows default
settings, because it does not read the old names. A configuration export that
already holds the new names is not changed. Export your configuration after the
migration.

### Rename Solo 1 classes and ids

```
drush solo:migrate-classes mytheme
```

Renames Solo 1 classes and ids to their Solo 2 names in a theme or module
folder. Without `--apply` it is a dry run: it counts what would change and
writes nothing. Add `--report=solo-report.md` for a full list.

- `--stored` also renames classes kept in configuration: Solo's inline CSS and
  custom code settings, Block Class, Views and text format allowed tags. Files
  under `sites/*/files` are listed, never written.
- `--content` also renames Solo ids in formatted text (value and summary),
  paragraphs, Layout Builder inline blocks and menu link attributes. The
  published revision and the latest draft are rewritten; older revisions are
  counted and left. A paragraph or inline block is never saved alone: its page
  is saved too, so the page shows the new text. Layout Builder section and
  component settings are listed only.

Changing content needs a database backup first. With `--apply --content`, the
command refuses and writes nothing unless you also pass `--backup-confirmed`
and `--report`:

```
drush solo:migrate-classes mytheme --stored --content --apply --backup-confirmed --report=solo-report.md
```

Each changed item is saved as a new revision. The last-changed date is kept,
and the revision author is user 1 unless you pass `--revision-user`. Use
`--limit` and `--from` to work in parts, and `--max-failures` (default 10) to
stop early. The exit code is 1 when anything failed or was refused.

The run writes a rollback log beside the report
(`solo-report.md.rollback.jsonl`). It holds site content, so keep it private.
To undo the content and configuration changes, dry run first, then apply:

```
drush solo:migrate-classes-rollback solo-report.md.rollback.jsonl
drush solo:migrate-classes-rollback solo-report.md.rollback.jsonl --apply
```

A value is restored only when it still holds what the run wrote; anything
changed since is listed as a conflict and left. For a full undo, restore your
database backup.

## Public API

Only the following is public API. Every other class is marked `@internal` and
may change in any release.

| Class | Purpose |
|---|---|
| `Drupal\solo_utilities\ColorSchemesRuleInterface` | The contract of a color schemes rule. |
| `Drupal\solo_utilities\Entity\ColorSchemesRule` | The `color_schemes_rule` config entity. Its id and stored keys are data. |
| `Drupal\solo_utilities\Entity\NodeWidth` | The `node_width` content entity. Its table, `solo_theme_node_width`, holds site data. |
| `Drupal\solo_utilities\Theme\SoloThemeDetection` | Answers whether a theme is Solo or a Solo sub-theme. |

The routes, permissions, library names and block third-party setting keys
(`solo_block_title_visibility`, `solo_block_title_tag`) are kept as they are.

## Future Plans

Solo Utilities is designed to be a foundational module for extending the Solo
theme. Future updates will include additional features and improvements to
further extend the capabilities of the Solo theme.

## Maintainers

- **Alaa Haddad** - [Alaa Haddad](https://www.alaahaddad.com)

## License

This project is licensed under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html).
