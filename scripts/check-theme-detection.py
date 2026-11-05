#!/usr/bin/env python3
"""Fail when code outside SoloThemeDetection walks the theme hierarchy itself.

"Is this Solo or a sub-theme, and what is its chain?" has ONE owner:
src/Theme/SoloThemeDetection.php (interface SoloThemeDetectionInterface). A
private read of `base_themes`, `info['base theme']`, `->sub_themes` or a loop
over `listInfo()` is a second owner and drifts. This check scans src/, the
*.module and *.install files and modules/ and reports every such read not
listed in ALLOWED below.

  check:     python3 scripts/check-theme-detection.py         exit 1 on any red
  self-test: python3 scripts/check-theme-detection.py --self-test

Standard library only. Not a test: it reads PHP source and runs no site code.
Comment lines are skipped; tests/ is not scanned.

ALLOWED is a named debt, not an exemption policy. Each entry is a copy a later
step removes (plan A3, planning/solo-global-services-plan.md) and is deleted
then. A stale entry (one that no longer matches a read) is reported too, so the
list cannot rot.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OWNER = 'src/Theme/SoloThemeDetection.php'
SCAN = ('src', 'modules')
SCAN_FILES = ('solo_utilities.module', 'solo_utilities.install')
EXTENSIONS = ('.php', '.module', '.install', '.inc')

TOKENS = {
    'base_themes': re.compile(r"\bbase_themes\b"),
    "info['base theme']": re.compile(r"""\[\s*(['"])base theme\1\s*\]"""),
    '->sub_themes': re.compile(r"->\s*sub_themes\b"),
    'listInfo()': re.compile(r"->\s*listInfo\s*\("),
}

ALLOWED = {}


def is_comment(line):
    stripped = line.lstrip()
    return stripped.startswith(('*', '//', '/*', '#'))


def reads(text):
    """Yield (line number, token) for every private hierarchy read."""
    for number, line in enumerate(text.splitlines(), 1):
        if is_comment(line):
            continue
        for token, pattern in TOKENS.items():
            if pattern.search(line):
                yield number, token


def scan(files):
    """Return (violations, used_allowed) over {relpath: text}."""
    violations, used = [], set()
    for rel, text in sorted(files.items()):
        if rel == OWNER:
            continue
        for number, token in reads(text):
            if (rel, token) in ALLOWED:
                used.add((rel, token))
            else:
                violations.append('%s:%d  private theme-hierarchy read "%s"; use SoloThemeDetectionInterface' % (rel, number, token))
    return violations, used


def load():
    files = {}
    paths = []
    for sub in SCAN:
        for base, _dirs, names in os.walk(os.path.join(ROOT, sub)):
            paths.extend(os.path.join(base, name) for name in names if name.endswith(EXTENSIONS))
    paths.extend(os.path.join(ROOT, name) for name in SCAN_FILES if os.path.isfile(os.path.join(ROOT, name)))
    for path in paths:
        with open(path, encoding='utf-8') as handle:
            files[os.path.relpath(path, ROOT)] = handle.read()
    return files


def check():
    files = load()
    if OWNER not in files:
        print('RED: owner file %s not found' % OWNER)
        return 1
    violations, used = scan(files)
    stale = ['%s  allowlist entry "%s" matches no read; delete it' % key for key in sorted(set(ALLOWED) - used)]
    for line in violations + stale:
        print('RED: ' + line)
    if violations or stale:
        return 1
    print('OK: no private theme-hierarchy read outside %s except %d listed debts (%d files scanned)' % (OWNER, len(ALLOWED), len(files)))
    return 0


def self_test():
    rel = 'src/Upgrade/Sample.php'
    clean = "$ok = $this->detection->depth($name);\n// base_themes in a comment is fine\n"
    cases = [
        ('clean file is green', clean, 0),
        ('base_themes read', clean + "$b = $theme->base_themes ?? [];\n", 1),
        ('info base theme read', clean + "$b = $theme->info['base theme'];\n", 1),
        ('sub_themes read', clean + "$b = $theme->sub_themes;\n", 1),
        ('listInfo loop', clean + "foreach ($this->themeHandler->listInfo() as $t) {}\n", 1),
    ]
    failed = 0
    for name, text, expect in cases:
        violations, _used = scan({rel: text})
        got = 1 if violations else 0
        failed += got != expect
        print('%s %-34s expected %s, got %s' % ('ok ' if got == expect else 'BAD', name, 'red' if expect else 'green', 'red' if got else 'green'))
    owner_violations, _used = scan({OWNER: "$b = $theme->base_themes;\n"})
    owner_ok = not owner_violations
    failed += not owner_ok
    print('%s %-34s expected green, got %s' % ('ok ' if owner_ok else 'BAD', 'owner file is exempt', 'green' if owner_ok else 'red'))
    # The live tree must be green, and must go red once a private read is added.
    files = load()
    live_violations, used = scan(files)
    stale = set(ALLOWED) - used
    live_ok = not live_violations and not stale
    failed += not live_ok
    print('%s %-34s expected green, got %s' % ('ok ' if live_ok else 'BAD', 'live tree', 'green' if live_ok else 'red'))
    victim = 'src/Upgrade/UpgradeOverview.php'
    mutated = dict(files)
    mutated[victim] = mutated.get(victim, '') + "\n$x = $theme->base_themes;\n"
    mutated_violations, _used = scan(mutated)
    red = bool(mutated_violations) and victim in files
    failed += not red
    print('%s %-34s expected red, got %s' % ('ok ' if red else 'BAD', 'live tree + planted read', 'red' if red else 'green'))
    # A stale allowlist entry must be seen: list a debt no file carries.
    fake = ('src/Upgrade/Sample.php', 'base_themes')
    ALLOWED[fake] = 'self-test only'
    try:
        _violations, used_fake = scan({rel: clean})
        stale_seen = fake in (set(ALLOWED) - used_fake)
    finally:
        del ALLOWED[fake]
    failed += not stale_seen
    print('%s %-34s expected stale entry, got %s' % ('ok ' if stale_seen else 'BAD', 'stale allowlist entry', 'seen' if stale_seen else 'missed'))
    print('SELF-TEST ' + ('FAILED' if failed else 'PASSED'))
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(self_test() if '--self-test' in sys.argv[1:] else check())
