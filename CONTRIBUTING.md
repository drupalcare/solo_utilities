# Contributing to Solo Utilities

Thanks for helping. Solo Utilities is the module that holds all of the Solo theme's PHP, so a change here changes how Solo behaves on every site that uses it.

Read [AGENTS.md](AGENTS.md) first. It lists where each fact lives and what must not change.

## Where to work

- **Issues and merge requests:** the [Solo Utilities issue queue](https://www.drupal.org/project/issues/solo_utilities) on drupal.org. Source: <https://git.drupalcode.org/project/solo_utilities>.
- **Security problems:** do not post them in the queue. See [SECURITY.md](SECURITY.md).
- **Theme problems** (templates, CSS, JavaScript, the starter sub-theme) belong to the [Solo issue queue](https://www.drupal.org/project/issues/solo).

## Requirements for a change

- Target Drupal `^11.3 || ^12` and PHP 8.3, as declared in `solo_utilities.info.yml`.
- Follow Drupal coding standards (`Drupal` and `DrupalPractice`).
- Start every PHP file with `declare(strict_types=1);`.
- Write hooks as classes in `src/Hook/` with the `#[Hook]` attribute.
- Keep the public API as it is: `ColorSchemesRuleInterface`, `Entity\ColorSchemesRule`, `Entity\NodeWidth` and `Theme\SoloThemeDetection`. Mark new classes `@internal`.
- Make strings translatable and output escaped. Values that reach CSS, markup or a URL go through `src/Security/`.
- American English in code comments, docs and user-facing text.
- An update function's docblock is one summary line; put the explanation in `//` comments inside the function.
- If a change alters a Drush command, the upgrade page, a setting or a requirement, update `README.md` and `CHANGELOG.md` in the same change.

## Local checks

Run these from a Drupal project that has the module and the Solo theme installed:

```bash
vendor/bin/phpcs --standard=web/modules/contrib/solo_utilities/phpcs.xml.dist web/modules/contrib/solo_utilities
vendor/bin/phpstan analyse -c web/modules/contrib/solo_utilities/phpstan.neon.dist
```

After you change a constructor or `solo_utilities.services.yml`, run `drush cr` and load a page. A stale compiled container gives a 500 error that `drush status` does not show.

The GitLab pipeline in `.gitlab-ci.yml` runs the project's tests against current core, the previous minor and the next major. A change that adds behavior comes with a test that fails without it.

## Merge request checklist

- [ ] The change matches the issue and nothing else.
- [ ] PHPCS and PHPStan pass with the files in this repository.
- [ ] No public API, route, permission, library name or stored key changed without a note in `CHANGELOG.md`.
- [ ] A change to the 1.x to 2.0 upgrade keeps the settings backup and the dry run default.
- [ ] `README.md` and `CHANGELOG.md` are updated where behavior changed.
- [ ] The pipeline is green.
