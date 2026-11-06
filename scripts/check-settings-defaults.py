#!/usr/bin/env python3
"""Fail when a settings read carries its own fallback literal.

Solo's defaults have ONE owner: config/install/solo.settings.yml, read through
SettingsCatalog::defaultAt() by BoundThemeSettings::get(). A read such as
`$settings->get('a.b') ?? 'x'` or `$settings->bool('a.b', TRUE)` is a second
owner and drifts. This check scans the three source directories where those
reads live and reports every such fallback not listed in ALLOWED below.

  check:     python3 scripts/check-settings-defaults.py         exit 1 on any red
  self-test: python3 scripts/check-settings-defaults.py --self-test

Standard library only. Not a test: it reads PHP source and runs no site code.

Solo 2.0-only keys get their default from scripts/settings-gen/solo-2.0-defaults.yml (shipped
copy: config/map/solo-2.0-defaults.yml), which solo-schema-gen.py merges into the
install file; code never repeats them.

The shipped copy is also held to the install file: every path in it must exist in
solo/config/install/solo.settings.yml with the same value. The theme is found as a
sibling directory (the lane layout) or through SOLO_THEME_DIR; when neither exists
the check prints SKIP for that part and says so.

ALLOWED is a named debt, not an exemption policy. Each entry waits on an owner
decision (see the reason) and is deleted when that is settled. A stale entry
(one that no longer matches a fallback) is reported too, so the list cannot rot.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCAN_DIRS = ('src/Form/ThemeSettings', 'src/Css', 'src/Feature')

# A settings read with a string key, and what follows it: either a second
# argument or a null-coalescing fallback.
CALL = re.compile(
    r"->(?:get|string|int|bool|array)\(\s*(['\"])(?P<key>[^'\"]+)\1\s*"
    r"(?:,\s*(?P<arg>(?:[^()]|\([^()]*\))*?))?\)"
    r"(?:\s*\?\?\s*(?P<coalesce>\S))?"
)

DIFFERS = 'literal differs from solo.settings.yml (owner ruling Q1)'

ALLOWED = {
    ('src/Feature/CommentPreprocessor.php', 'comments.date_format'): DIFFERS,
}


MAP_FILE = 'config/map/solo-2.0-defaults.yml'
INSTALL_REL = os.path.join('config', 'install', 'solo.settings.yml')


def flat_yaml(text):
    """Read `key: raw` YAML with 2-space nesting into {dotted.path: raw text}."""
    out, stack = {}, []
    for number, line in enumerate(text.split('\n'), 1):
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        match = re.match(r'^( *)([A-Za-z0-9_]+):(?: (.*))?$', line)
        if not match or len(match.group(1)) % 2:
            raise ValueError('unhandled line %d: %s' % (number, line))
        depth = len(match.group(1)) // 2
        del stack[depth:]
        if match.group(3) is None or match.group(3).strip() == '':
            stack.append(match.group(2))
        else:
            out['.'.join(stack + [match.group(2)])] = match.group(3).rstrip()
    return out


def map_errors(map_text, install_text):
    """Return the reasons the shipped 2.0-only defaults and the install file disagree."""
    shipped, install = {}, flat_yaml(install_text)
    for number, line in enumerate(map_text.split('\n'), 1):
        if not line.strip() or line.lstrip().startswith('#'):
            continue
        match = re.match(r'^([a-z_0-9]+(?:\.[a-z_0-9]+)+): (.+?)\s*$', line)
        if not match:
            return ['%s line %d: unhandled line' % (MAP_FILE, number)]
        shipped[match.group(1)] = match.group(2)
    errors = []
    for path, value in shipped.items():
        if path not in install:
            errors.append('%s: "%s" is not in solo.settings.yml' % (MAP_FILE, path))
        elif install[path] != value:
            errors.append('%s: "%s" is %s here and %s in solo.settings.yml' % (MAP_FILE, path, value, install[path]))
    return errors if shipped else ['%s holds no defaults' % MAP_FILE]


def solo_install_file():
    """Find solo.settings.yml: SOLO_THEME_DIR, else the sibling solo directory; None when absent."""
    for base in (os.environ.get('SOLO_THEME_DIR'), os.path.join(os.path.dirname(ROOT), 'solo')):
        if base and os.path.isfile(os.path.join(base, INSTALL_REL)):
            return os.path.join(base, INSTALL_REL)
    return None


def check_map():
    """Return (errors, note) for the shipped defaults map against the install file."""
    install = solo_install_file()
    if install is None:
        return [], 'SKIP: solo theme not found (set SOLO_THEME_DIR); shipped defaults map not compared'
    with open(os.path.join(ROOT, MAP_FILE), encoding='utf-8') as handle:
        map_text = handle.read()
    with open(install, encoding='utf-8') as handle:
        return map_errors(map_text, handle.read()), ''


def fallbacks(text):
    """Yield (line, key) for every settings read that carries a fallback."""
    for match in CALL.finditer(text):
        if match.group('arg') or match.group('coalesce'):
            yield text.count('\n', 0, match.start()) + 1, match.group('key')


def scan(files):
    """Return (violations, used_allowed) over {relpath: text}."""
    violations, used = [], set()
    for rel, text in sorted(files.items()):
        for line, key in fallbacks(text):
            if (rel, key) in ALLOWED:
                used.add((rel, key))
            else:
                violations.append('%s:%d  fallback literal on "%s"' % (rel, line, key))
    return violations, used


def load():
    files = {}
    for sub in SCAN_DIRS:
        for base, _dirs, names in os.walk(os.path.join(ROOT, sub)):
            for name in names:
                if name.endswith('.php'):
                    path = os.path.join(base, name)
                    with open(path, encoding='utf-8') as handle:
                        files[os.path.relpath(path, ROOT)] = handle.read()
    return files


def check():
    files = load()
    if not files:
        print('RED: no PHP files found under ' + ', '.join(SCAN_DIRS))
        return 1
    violations, used = scan(files)
    stale = ['%s  allowlist entry "%s" matches no fallback; delete it' % key for key in sorted(set(ALLOWED) - used)]
    map_red, note = check_map()
    for line in violations + stale + map_red:
        print('RED: ' + line)
    if note:
        print(note)
    if violations or stale or map_red:
        return 1
    print('OK: no fallback literal outside the %d listed exceptions (%d files scanned)' % (len(ALLOWED), len(files)))
    return 0


def self_test():
    rel = 'src/Form/ThemeSettings/Section/Sample.php'
    clean = "$a = $settings->get('layout.width.global');\n$b = $settings->bool('comments.show_date');\n"
    cases = [
        ('clean file is green', clean, 0),
        ('coalesce literal re-added', clean + "$c = $settings->get('layout.regions_gap') ?? '16';\n", 1),
        ('second argument re-added', clean + "$c = $settings->int('back_to_top.scroll_threshold', 400);\n", 1),
        ('boolean fallback re-added', clean + "$c = $settings->get('x.y') ?? TRUE;\n", 1),
        ('multi-line array fallback', clean + "$c = $settings->get('x.y') ?? [\n];\n", 1),
        ('allowed exception stays green', clean + "$c = $settings->string('comments.date_format', 'medium');\n", 0),
        ('2.0-only literal re-added', clean + "$c = $settings->get('menus.toggle_speed') ?? 'normal';\n", 1),
    ]
    failed = 0
    allowed_rel = 'src/Feature/CommentPreprocessor.php'
    for name, text, expect in cases:
        target = allowed_rel if name.startswith('allowed') else rel
        violations, _used = scan({target: text})
        got = 1 if violations else 0
        status = 'ok ' if got == expect else 'BAD'
        failed += got != expect
        print('%s %-34s expected %s, got %s' % (status, name, 'red' if expect else 'green', 'red' if got else 'green'))
    # The live tree must be green, and must go red once a literal is re-added to it.
    files = load()
    live_violations, _used = scan(files)
    print('%s %-34s expected green, got %s' % ('ok ' if not live_violations else 'BAD', 'live tree',
                                               'green' if not live_violations else 'red'))
    failed += bool(live_violations)
    victim = 'src/Feature/BackToTop.php'
    mutated = dict(files)
    mutated[victim] = mutated[victim].replace("int('back_to_top.scroll_threshold')",
                                              "int('back_to_top.scroll_threshold', 400)", 1)
    mutation_applied = mutated[victim] != files[victim]
    mutated_violations, _used = scan(mutated)
    red = bool(mutated_violations) and mutation_applied
    print('%s %-34s expected red, got %s' % ('ok ' if red else 'BAD', 'live tree + re-added literal',
                                             'red' if red else 'green'))
    failed += not red
    install_text = "popup_login:\n  z_index: 10000\n  focus_trap: true\nmenus:\n  toggle_speed: normal\n"
    map_cases = [
        ('map agrees with install', "popup_login.z_index: 10000\nmenus.toggle_speed: normal\n", install_text, 0),
        ('map path missing in install', "popup_login.zz_gone: 1\n", install_text, 1),
        ('map value differs from install', "popup_login.z_index: 9999\n", install_text, 1),
        ('empty map', "# nothing\n", install_text, 1),
    ]
    for name, map_text, inst, expect in map_cases:
        got = 1 if map_errors(map_text, inst) else 0
        status = 'ok ' if got == expect else 'BAD'
        failed += got != expect
        print('%s %-34s expected %s, got %s' % (status, name, 'red' if expect else 'green', 'red' if got else 'green'))
    live_map, live_note = check_map()
    print('%s %-34s expected green, got %s%s' % ('ok ' if not live_map else 'BAD', 'live defaults map',
                                                 'green' if not live_map else 'red', ' (' + live_note + ')' if live_note else ''))
    failed += bool(live_map)
    print('SELF-TEST ' + ('FAILED' if failed else 'PASSED'))
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(self_test() if '--self-test' in sys.argv[1:] else check())
