#!/usr/bin/env python3
"""Fail when the settings form carries a bound of its own.

Solo's numeric ranges and text lengths have ONE owner: the config constraints in
solo/config/schema/solo.schema.yml (generated from scripts/settings-gen/solo-2.0-constraints.yml).
SchemaBounds::applyTo() turns them into #min, #max and #maxlength. An integer literal
such as `'#min' => 3` in a section or in SettingsElementFactory is a second owner
and drifts. This check reports every one.

  check:     python3 scripts/check-settings-bounds.py         exit 1 on any red
  self-test: python3 scripts/check-settings-bounds.py --self-test

Standard library only. Not a test: it reads PHP source and runs no site code.
Out of scope on purpose: forms outside the theme settings form (for example
ColorSchemesRuleForm), which have no settings schema path.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCAN_DIRS = ('src/Form/ThemeSettings/Section',)
SCAN_FILES = ('src/Form/SettingsElementFactory.php',)

BOUND = re.compile(r"""(['"])#(?P<name>min|max|maxlength)\1\s*=>\s*-?\d+""")


def literals(text):
    """Yield (line, property) for every integer bound literal."""
    for match in BOUND.finditer(text):
        yield text.count('\n', 0, match.start()) + 1, '#' + match.group('name')


def scan(files):
    """Return the violations over {relpath: text}."""
    return ['%s:%d  integer literal for %s' % (rel, line, name)
            for rel, text in sorted(files.items()) for line, name in literals(text)]


def load():
    paths = list(SCAN_FILES)
    for sub in SCAN_DIRS:
        for base, _dirs, names in os.walk(os.path.join(ROOT, sub)):
            paths += [os.path.relpath(os.path.join(base, n), ROOT) for n in names if n.endswith('.php')]
    files = {}
    for rel in paths:
        with open(os.path.join(ROOT, rel), encoding='utf-8') as handle:
            files[rel] = handle.read()
    return files


def check():
    files = load()
    if len(files) < 2:
        print('RED: found %d PHP files to scan; expected the sections and the element factory' % len(files))
        return 1
    violations = scan(files)
    for line in violations:
        print('RED: ' + line)
    if violations:
        return 1
    print('OK: no integer #min, #max or #maxlength literal (%d files scanned)' % len(files))
    return 0


def self_test():
    clean = "$e = $this->bounds->applyTo(['#type' => 'number', '#step' => 1, '#size' => 10], 'a.b');\n"
    cases = [
        ('clean file is green', clean, 0),
        ('#min literal', clean + "$e = ['#min' => 3];\n", 1),
        ('#max literal', clean + "$e = ['#max' => 60];\n", 1),
        ('#maxlength literal', clean + "$e = ['#maxlength' => 7];\n", 1),
        ('double-quoted key', clean + '$e = ["#max" => 100];' + "\n", 1),
        ('negative literal', clean + "$e = ['#min' => -5];\n", 1),
        ('computed bound stays green', clean + "$e = ['#max' => $max];\n", 0),
    ]
    failed = 0
    for name, text, expect in cases:
        got = 1 if scan({'src/Form/Sample.php': text}) else 0
        failed += got != expect
        print('%s %-30s expected %s, got %s' % ('ok ' if got == expect else 'BAD', name,
                                                'red' if expect else 'green', 'red' if got else 'green'))
    files = load()
    live = scan(files)
    print('%s %-30s expected green, got %s' % ('ok ' if not live else 'BAD', 'live tree', 'green' if not live else 'red'))
    failed += bool(live)
    victim = 'src/Form/SettingsElementFactory.php'
    mutated = dict(files)
    mutated[victim] = mutated[victim].replace("'#size' => 10,", "'#size' => 10,\n      '#maxlength' => 7,", 1)
    red = mutated[victim] != files[victim] and bool(scan(mutated))
    print('%s %-30s expected red, got %s' % ('ok ' if red else 'BAD', 'live tree + literal re-added', 'red' if red else 'green'))
    failed += not red
    print('SELF-TEST ' + ('FAILED' if failed else 'PASSED'))
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(self_test() if '--self-test' in sys.argv[1:] else check())
