# Settings generator

Owns Solo 2.0's settings schema, install defaults and shipped maps. Never edit the outputs by hand.

- `solo-schema-gen.py` writes solo/config/schema/solo.schema.yml, solo/config/install/solo.settings.yml, the
  solo_subtheme schema and install files, and config/map/*.yml in this module.
- `solo-config-map.py` builds `solo-config-map.yml` from the Solo 1.0.x-dev files (read with `git show`).
- `solo-2.0-defaults.yml` and `solo-2.0-constraints.yml` are the inputs that own 2.0-only defaults and bounds.
  `solo-2.0-constraints.yml` is valid YAML with unique keys: one constraint is `path: Name option=value`; several on one path are a
  block list (`path:` then `  - Length max=7`, `  - Regex pattern=/x/`). The generator reads both forms and refuses a repeated path.
- `solo-2.0-changed-defaults.yml` owns the 1.x defaults that 2.0 changes on purpose (for example, social links start empty). Never edit the generated install files by hand; add the change here.
- `solo-2.0-types.yml` owns leaves typed `label` or `text_format` (translatable text).
- `solo-2.0-choices.yml` maps a schema path to the `SettingsOptions` method whose literal keys become its Choice constraint.
- `solo-2.0-compat.yml` owns cross-field rules (C3, C5, C11, C14). It is written as src/Settings/CompatibilityRules.php; the form (#states) and SettingsFormValidator use it by rule id, and `--check` goes red when either stops using a rule or uses an undeclared one.

The Solo theme is the sibling `solo` directory, or the path in `SOLO_THEME_DIR`. It must be a git checkout that has
the `1.0.x-dev` ref.

    python3 -I scripts/settings-gen/solo-config-map.py --write   # only when the 1.0.x-dev source changes
    python3 -I scripts/settings-gen/solo-schema-gen.py --write
    python3 -I scripts/settings-gen/solo-schema-gen.py --check
    python3 -I scripts/settings-gen/solo-schema-gen.py --self-test

CI runs `--check`, `--self-test` and the `scripts/check-*.py` scripts (bounds, defaults, theme detection, regions) in the `settings-gen-checks` job.
