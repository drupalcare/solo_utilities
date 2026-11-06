#!/usr/bin/env python3
"""Fail when Solo's region facts disagree between the files that carry them.

Solo's regions have ONE owner for names and labels: solo/solo.info.yml. This check holds the other places to it:
the sub-theme's info file (core needs its own copy), LayoutResolver::GROUP_REGIONS (group membership, the one owner
of which region sits in which group), ThemeCssBuilder::REGION_SELECTORS (a selector per region), the schema's
regions mapping, and SettingsOptions::groupLabels() (it must read both owners, never carry a region label).

  check:     python3 -I scripts/check-regions.py
  self-test: python3 -I scripts/check-regions.py --self-test

The theme is the sibling `solo` directory or SOLO_THEME_DIR; without it the check exits 1 (a skipped check is not green).
Standard library only. Not a test: it reads source and runs no site code.
"""
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SOLO = os.environ.get('SOLO_THEME_DIR') or os.path.join(os.path.dirname(ROOT), 'solo')
# Regions the CSS builder styles that are not Solo info regions: containers, the footer copyright line.
NOT_INFO = {'page_wrapper', 'top_container', 'main_container', 'bottom_container', 'footer_container', 'credit_copyright'}


def read(*parts):
    with open(os.path.join(*parts)) as handle:
        return handle.read()


def info_regions(text):
    """Return region machine names to labels from an info file's regions: block."""
    out, inside = {}, False
    for line in text.split('\n'):
        if re.match(r'^regions:\s*$', line):
            inside = True
        elif inside and re.match(r'^  ([a-z_0-9]+): (.+)$', line):
            m = re.match(r'^  ([a-z_0-9]+): (.+)$', line)
            out[m.group(1)] = m.group(2).strip().strip('\'"')
        elif inside and re.match(r'^\S', line):
            break
    return out


def php_const(text, name):
    m = re.search(r'const %s = \[\n(.*?)\n  \];' % name, text, re.S)
    return m.group(1) if m else ''


def problems(sources):
    """Return the list of disagreements found in the given source texts."""
    out = []
    solo = info_regions(sources['solo_info'])
    sub = info_regions(sources['subtheme_info'])
    if not solo:
        return ['solo.info.yml has no regions block']
    if sub != solo:
        out.append('solo_subtheme.info.yml regions differ from solo.info.yml: %s' % sorted(set(solo.items()) ^ set(sub.items())))
    groups = {}
    for m in re.finditer(r"'([a-z]+)' => \[([^\]]*)\]", php_const(sources['resolver'], 'GROUP_REGIONS')):
        groups[m.group(1)] = re.findall(r"'([a-z_]+)'", m.group(2))
    if not groups:
        out.append('LayoutResolver::GROUP_REGIONS not found')
    for group, regions in groups.items():
        for region in regions:
            if region not in solo:
                out.append('GROUP_REGIONS %s names %s, which solo.info.yml does not declare' % (group, region))
    selectors = set(re.findall(r"^    '([a-z_]+)' =>", php_const(sources['css'], 'REGION_SELECTORS'), re.M))
    if not selectors:
        out.append('ThemeCssBuilder::REGION_SELECTORS not found')
    for region in sorted(selectors - NOT_INFO - set(solo)):
        out.append('REGION_SELECTORS has %s, which solo.info.yml does not declare' % region)
    for group, regions in groups.items():
        for region in regions:
            if region not in selectors:
                out.append('REGION_SELECTORS has no selector for grouped region %s' % region)
    schema_regions = set(re.findall(r'^        ([a-z_0-9]+):\s*$', sources['schema'].split('\n    regions:\n', 1)[-1].split('\n    region_groups:\n', 1)[0], re.M))
    schema_regions = {r for r in schema_regions if r in solo or r in NOT_INFO}
    for region in sorted(selectors - NOT_INFO - schema_regions):
        out.append('schema regions mapping has no %s' % region)
    options = php_const(sources['options'], 'x') or sources['options']
    labels = re.search(r'public function groupLabels\(\): array \{(.*?)\n  \}\n', options, re.S)
    body = labels.group(1) if labels else ''
    if not labels:
        out.append('SettingsOptions::groupLabels() not found')
    elif 'LayoutResolver::GROUP_REGIONS' not in body:
        out.append('groupLabels() does not read LayoutResolver::GROUP_REGIONS')
    elif re.search(r"\$this->t\('(?:Sidebar|Left|Right|Content|(?:Top|Bottom|Footer) (?:First|Second|Third|Fourth) Container)", body):
        out.append('groupLabels() carries a region label; labels belong to solo.info.yml')
    return out


def load():
    return {
        'solo_info': read(SOLO, 'solo.info.yml'),
        'subtheme_info': read(SOLO, 'solo_subtheme', 'solo_subtheme.info.yml'),
        'schema': read(SOLO, 'config', 'schema', 'solo.schema.yml'),
        'resolver': read(ROOT, 'src', 'Layout', 'LayoutResolver.php'),
        'css': read(ROOT, 'src', 'Css', 'ThemeCssBuilder.php'),
        'options': read(ROOT, 'src', 'Form', 'SettingsOptions.php'),
    }


def self_test(sources):
    base = problems(sources)
    if base:
        print('self-test needs a green baseline: %s' % base)
        return 1
    seeds = {
        'subtheme label drift': ('subtheme_info', "sidebar_first: 'Left Sidebar'", "sidebar_first: 'Sidebar First'", 'differ from solo.info.yml'),
        'group names an unknown region': ('resolver', "'top_first', 'top_second', 'top_third'", "'top_first', 'top_second', 'zz_top'", 'zz_top'),
        'grouped region without a selector': ('css', "    'footer_third' =>", "    'zz_footer_third' =>", 'no selector for grouped region footer_third'),
        'selector for an unknown region': ('css', "    'welcome_text' =>", "    'zz_welcome' =>", 'zz_welcome'),
        'schema loses a region': ('schema', '        footer_menu:\n', '        zz_footer_menu:\n', 'no footer_menu'),
        'labels back in the options class': ('options', "$labels[$group]['regions'][$region] =", "$labels[$group]['regions'][$region] = $this->t('Sidebar First'); $x =", 'carries a region label'),
        'labels stop reading the groups': ('options', 'LayoutResolver::GROUP_REGIONS as $group', 'ZZ::GROUPS as $group', 'does not read'),
    }
    failed = 0
    for name, (key, old, new, needle) in seeds.items():
        if old not in sources[key]:
            print('NOT RED  %s (seed anchor moved)' % name)
            failed += 1
            continue
        found = problems(dict(sources, **{key: sources[key].replace(old, new, 1)}))
        ok = any(needle in p for p in found)
        print(('RED ok   ' if ok else 'NOT RED  ') + name)
        failed += 0 if ok else 1
    print('self-test: %s' % ('FAILED %d' % failed if failed else 'all seeds red'))
    return 1 if failed else 0


def main():
    if not os.path.isfile(os.path.join(SOLO, 'solo.info.yml')):
        print('FAIL: the Solo theme is not at %s (set SOLO_THEME_DIR)' % SOLO)
        return 1
    sources = load()
    if '--self-test' in sys.argv:
        return self_test(sources)
    found = problems(sources)
    print('\n'.join(found) if found else 'OK: region names, labels, groups, selectors and schema agree (%d regions)' % len(info_regions(sources['solo_info'])))
    return 1 if found else 0


if __name__ == '__main__':
    sys.exit(main())
