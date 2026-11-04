# Solo Utilities Compatibility

Solo 2 renames its stored settings. Code that calls `getSetting('old_name')` or
`theme_get_setting('old_name')` would receive NULL with no error. This module
answers those calls from the new name and logs each old name once.

Install it only when `drush solo:preflight` reports a `settings_call`. Fix each
reported call, then uninstall this module.

It does not cover `\Drupal::config('<theme>.settings')->get('old_name')`,
settings-form alters, config writes or overrides in `settings.php`. Those need a
code change; `drush solo:preflight` lists them by file and line.

## Helper functions

The module also defines five Solo Utilities helper functions that Solo 1
sub-themes may call. Each forwards to a service and holds no logic:
`_solo_utilities_helper_render_readme`, `solo_utilities__is_solo_in_theme_hierarchy`,
`solo_utilities__is_solo_or_sub_in_hierarchy_active`,
`solo_utilities__is_block_title_visibility_enabled` and
`solo_utilities__block_title_setting`. Calls to them fatal without this module.
Change the caller to the service (`SoloThemeDetection`, `BlockTitleSettings`,
`ReadmeRenderer`), then uninstall the module. The two form callbacks stay in
Solo Utilities itself, because open forms store them by name.
