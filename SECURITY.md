# Security policy

Solo Utilities follows the Drupal Security Team's process for handling security issues.

## Reporting a vulnerability

**Do not** report security issues in the public issue queue. Report them privately through the Drupal Security Team:

- <https://www.drupal.org/security-team/report-issue>

Please include the module version, the Drupal core version, whether the Solo theme is installed and which version, and clear steps to reproduce the problem. Background on how the team works:

- <https://www.drupal.org/security-team>
- <https://www.drupal.org/drupal-security-team/security-advisory-process-and-permissions-policy>

## Supported versions

The 2.0.x branch is the development line and requires Drupal `^11.3 || ^12` and PHP 8.3 (`solo_utilities.info.yml`). The 1.0.x branch is the line for Drupal 10. Whether a release is covered by a security advisory is decided by the Drupal Security Team on drupal.org; this file does not promise coverage.

## Permissions that are equivalent to site takeover

Four permissions are marked `restrict access: true` (`solo_utilities.permissions.yml`), which is Drupal's own way of saying a role holding them is trusted with the site. Grant them deliberately:

- `administer Color Schemes Rules`
- `create Color Schemes Rules`
- `edit Color Schemes Rules`
- `delete Color Schemes Rules`

`view Color Schemes Rules` is not restricted.

The Solo upgrade page and its run and restore routes (`/admin/config/system/solo-upgrade`) require Drupal's `administer themes` permission. The restore route writes earlier settings back and cannot be undone, so grant that permission only to roles you trust with the theme.

## Things this module writes that you should protect

- **The class and content migration** (`drush solo:migrate-classes`) writes a rollback log beside its report (`solo-report.md.rollback.jsonl`). The log holds site content. Keep it private and out of the web root.
- **Content changes need a database backup.** With `--apply --content` the command refuses to run unless `--backup-confirmed` and `--report` are passed.
- **Untrusted values.** Input that reaches CSS or a URL passes through `src/Security/CssValueValidator.php` and `src/Security/UrlSafety.php`. A change that lets a stored value reach CSS, markup or a URL without them is a security change and needs a review of its own.
