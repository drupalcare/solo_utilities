# Solo Utilities: agent brief

**Who this is for:** whoever works this module next, human or agent, with no memory of how it got this way.

**Scope:** the `solo_utilities` module and its `solo_utilities_compat` sub-module. The Solo theme (`drupal/solo`) is a separate project and a separate repository. Report theme-side problems there; do not fix them from here.

## Before you change anything

1. **This module holds all of Solo's PHP.** Solo 2 keeps templates, CSS, JavaScript and settings data in the theme and moves every line of logic here: preprocess, theme suggestions, theme settings form sections, generated CSS, feature classes (`src/Hook/`, `src/Form/`, `src/Css/`, `src/Feature/`). A change to Solo's behavior is almost always a change here.
2. **Read the class before you edit it.** Most classes are `final` and marked `@internal`. Only four things are public API (list below); everything else may change in any release.
3. **Check the README first.** It documents every Drush command and the upgrade page. A change to their behavior is a change to the README in the same commit.

## Where facts live (one owner each)

| Fact | Lives in |
|---|---|
| Supported core and PHP | `solo_utilities.info.yml` and `composer.json` (`^11.3 \|\| ^12`, PHP 8.3). CI jobs in `.gitlab-ci.yml` follow them. |
| Old setting name to new setting name | `config/map/solo-config-map.yml`, read by `src/Upgrade/SettingsMap.php`. The map is a generated copy; do not hand-edit it. |
| Old class and id to new class and id | `config/map/solo-class-map.yml`, read by `src/Migration/SoloClassMap.php`. Solo's `docs/CLASS-MIGRATION.md` is generated from it. |
| Solo 1 PHP function names (for the readiness scan) | `config/map/solo1-function-names.yml` |
| Template variables | `config/map/solo-template-variables.yml` |
| Services | `solo_utilities.services.yml` |
| Routes and permissions | `solo_utilities.routing.yml`, `solo_utilities.permissions.yml` |
| Drush commands | `src/Drush/Commands/` (`solo:subtheme`, `solo:preflight`, `solo:settings-restore`, `solo:migrate-classes`, `solo:migrate-classes-rollback`) |
| Upgrade logic | `src/Upgrade/` (settings migrator, upgrade overview), `src/Hook/SettingsUpgradeHooks.php`, `src/Hook/UpgradePageHooks.php` |

## What must not change

- **The public API:** `ColorSchemesRuleInterface`, `Entity\ColorSchemesRule`, `Entity\NodeWidth`, `Theme\SoloThemeDetection`. Their ids, stored keys and the `solo_theme_node_width` table hold site data.
- **Routes, permissions, library names and the block third-party setting keys** `solo_block_title_visibility` and `solo_block_title_tag`.
- **The Solo 1 to 2 upgrade path.** Settings are migrated by name from the map, the earlier settings are kept in the database for `drush solo:settings-restore`, and `drush solo:migrate-classes` is a dry run unless `--apply` is passed. Do not weaken any of those guards.
- **Hooks are classes with `#[Hook]`.** New hook code goes in `src/Hook/`, not in `solo_utilities.module`.
- **Every PHP file starts with `declare(strict_types=1);`.** All 106 files under `src/` do today.
- **Update functions:** the docblock of a `hook_update_N()` or `hook_post_update_NAME()` is one summary line. Drupal prints the whole docblock as the description in the `drush updatedb` table. Explanations go in `//` comments inside the function.

## After a change to a constructor or `services.yml`

Rebuild the container (`drush cr`) and load a page over HTTP. A site whose compiled container still passes the old argument list fails with a 500 error, and `drush status` can still answer normally.

## Commands that check a change

Run from a Drupal project that has this module and the Solo theme installed.

```bash
vendor/bin/phpcs --standard=web/modules/contrib/solo_utilities/phpcs.xml.dist web/modules/contrib/solo_utilities
vendor/bin/phpstan analyse -c web/modules/contrib/solo_utilities/phpstan.neon.dist
```

The drupal.org GitLab pipeline (`.gitlab-ci.yml`) runs the project's tests against current core, the previous minor and the next major. A pipeline that is red on the commit you release is a blocker.

## Reporting a problem

Public bugs go to the [issue queue](https://www.drupal.org/project/issues/solo_utilities). Security problems do not: see [SECURITY.md](SECURITY.md).
