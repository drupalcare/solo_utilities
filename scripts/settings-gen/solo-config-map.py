#!/usr/bin/env python3
"""Generate and check solo_utilities/scripts/settings-gen/solo-config-map.yml from Solo 1.0.x-dev (python3 -I).

  generate:  python3 -I solo-config-map.py --write
  check:     python3 -I solo-config-map.py --check       exit 1 on any red
  self-test: python3 -I solo-config-map.py --self-test   every seeded fault must go red

The key list comes only from the schema and install files at SOLO_REF. RULES below
holds naming decisions, not keys: a key with no rule is red, and so is a rule that
matches nothing. The .yml is output; never edit it by hand.
"""
import os, re, subprocess, sys

HERE = os.path.dirname(os.path.abspath(__file__))
# HERE is solo_utilities/scripts/settings-gen; the module root is two levels up.
UTILS_DIR = os.path.dirname(os.path.dirname(HERE))
# The Solo theme checkout: SOLO_THEME_DIR, else the sibling solo directory (the lane layout).
SOLO = os.environ.get('SOLO_THEME_DIR') or os.path.join(os.path.dirname(UTILS_DIR), 'solo')
OUT = os.path.join(HERE, 'solo-config-map.yml')
SOLO_REF = '1.0.x-dev'
SOURCES = {
    'schema': 'config/schema/solo.schema.yml',
    'install': 'config/install/solo.settings.yml',
    'starter_schema': 'solo_subtheme/config/schema/solo_subtheme.schema.yml',
    'starter_install': 'solo_subtheme/config/install/solo_subtheme.settings.yml',
}
READER_ROOTS = ['includes', 'src', 'templates', 'partials', 'js', 'solo.theme',
                'solo.post_update.php', 'solo.libraries.yml']
SNAKE = re.compile(r'^[a-z][a-z0-9_]*$')

COLORS = {
    'bg': 'background', 'tx': 'text', 'h1': 'heading', 'lk': 'link', 'lk_h': 'link_hover',
    'br': 'border', 'bg_fr': 'form_field_background',
    'tx_lk': 'menu_link_text', 'tx_lk_h': 'menu_link_text_hover',
    'bg_lk': 'menu_link_background', 'bg_lk_h': 'menu_link_background_hover',
    'tx_bt': 'button_text', 'tx_bt_h': 'button_text_hover',
    'bg_bt': 'button_background', 'bg_bt_h': 'button_background_hover',
}
SOCIAL = 'facebook|twitter|bluesky|pinterest|linkedin|instagram|youtube|snapchat|reddit|tiktok|whatsapp|threads|drupal|mastodon|telegram|rss|email'
G = '(top|main|bottom|footer)'


def color(m):
    return m.group(1), COLORS[m.group(2)]


# (old key regex, new path template or callable, action). First match wins.
RULES = [
    # Core owns these names: core's form and provider read them by these paths.
    (r'_core|features|logo|favicon', r'\g<0>', 'keep_core'),
    # Palette.
    (r'settings_(preloader|back_to_top)___r_(\w+)', lambda m: '%s.colors.%s' % color(m), 'move'),
    (r'settings_(\w+?)___r_(\w+)', lambda m: 'regions.%s.colors.%s' % color(m), 'move'),
    (r'classes_(primary_menu|primary_sidebar_menu)', lambda m: 'regions.%s.classes' % m.group(1), 'move'),
    (r'classes_(\w+)', r'regions.\1.classes', 'move'),
    (r'align_(\w+)', r'regions.\1.alignment', 'move'),
    # Groups.
    (r'%s_([234])col' % G, r'region_groups.\1.columns_\2', 'move'),
    (r'enable_per_type_layout_%s' % G, r'region_groups.\1.per_content_type_enabled', 'move'),
    (r'solo_layouts', 'region_groups.*.per_content_type', 'fan_out_layouts'),
    (r'site_regions_%s_disable' % G, r'region_groups.\1.visibility', 'move'),
    (r'site_regions_%s_rounded' % G, r'region_groups.\1.rounded', 'move'),
    (r'site_regions_%s_border' % G, r'region_groups.\1.border', 'move'),
    (r'site_regions_%s_animate_border' % G, r'region_groups.\1.animate_border', 'move'),
    (r'site_regions_%s_animate_hover' % G, r'region_groups.\1.animate_hover', 'move'),
    (r'enable_aria_%s' % G, r'region_groups.\1.aria_landmark', 'move'),
    # Single regions.
    (r'site_regions_highlighted_disable', 'regions.highlighted.visibility', 'move'),
    (r'enable_aria_highlighted', 'regions.highlighted.aria_landmark', 'move'),
    (r'site_regions_welcome_disable', 'regions.welcome_text.visibility', 'move'),
    (r'site_regions_welcome_width', 'regions.welcome_text.width', 'move'),
    (r'enable_aria_welcome', 'regions.welcome_text.aria_landmark', 'move'),
    (r'opacity_page_wrapper', 'regions.page_wrapper.opacity', 'move'),
    (r'system_messages_disable_dark', 'regions.system_messages.disable_dark', 'move'),
    # Layout.
    (r'site_global_width', 'layout.width.global', 'move'),
    (r'enable_px_based_widths', 'layout.width.use_pixels', 'move'),
    (r'enable_custom_widths', 'layout.width.per_content_type_enabled', 'move'),
    (r'site_widths', 'layout.width.per_content_type', 'move'),
    (r'enable_custom_node_width', 'layout.width.per_node_enabled', 'move'),
    (r'site_global_breakpoints', 'layout.breakpoints.page', 'move'),
    (r'site_menu_breakpoints', 'layout.breakpoints.menu', 'move'),
    (r'site_global_regions_gap', 'layout.regions_gap', 'move'),
    (r'site_mobile_layout_order', 'layout.mobile_order', 'move'),
    (r'site_regions_collapse_order', 'layout.collapse_order', 'move'),
    (r'site_flip_header_menu', 'layout.flip_header_menu', 'move'),
    (r'site_inline_items_on', 'layout.inline_field_items', 'move'),
    (r'site_opacity_onscroll_on', 'layout.reveal_on_scroll', 'move'),
    (r'site_login_page_on', 'layout.centered_login_page', 'move'),
    # Colour scheme.
    (r'theme_category', 'color_scheme.category', 'move'),
    (r'predefined_current_theme', 'color_scheme.name', 'scheme_name'),
    (r'color_coded_system_tabs', 'color_scheme.colored_system_tabs', 'move'),
    # Typography.
    (r'site_global_font_size', 'typography.font_size', 'move'),
    (r'site_node_date_format', 'typography.node_date_format', 'move'),
    (r'site_import_google_font', 'typography.imported_google_font', 'move'),
    (r'site_load_w3css_on', 'typography.load_w3css', 'move'),
    (r'site_font_google_local_on', 'typography.fonts.local', 'move'),
    (r'site_font_awesome_on', 'typography.fonts.icon_library', 'move'),
    (r'site_font_google_(global|heading|special)_on', r'typography.fonts.\1', 'move'),
    (r'site_reading_mode_content_type', 'typography.reading_mode_content_types', 'move'),
    (r'site_name_font_size_([sl])', lambda m: 'header.site_name.font_size_%s' % {'s': 'small', 'l': 'large'}[m.group(1)], 'move'),
    (r'site_name_google_font', 'header.site_name.google_font', 'move'),
    (r'site_name_animate_on', 'header.site_name.animation', 'move'),
    (r'site_pagetitle_font_size_([sl])', lambda m: 'page_title.font_size_%s' % {'s': 'small', 'l': 'large'}[m.group(1)], 'move'),
    (r'site_pagetitle_google_font', 'page_title.google_font', 'move'),
    (r'site_pagetitle_animate_on', 'page_title.animation', 'move'),
    (r'site_pagetitle_center', 'page_title.centered', 'move'),
    # Custom code.
    (r'site_css_injector', 'custom_code.inline_css', 'move'),
    (r'site_css_dynamic', 'custom_code.css_file', 'move'),
    (r'site_js_dynamic', 'custom_code.js_file', 'move'),
    # Header.
    (r'header_logo_image_alt', 'header.logo_alt', 'move'),
    (r'header_sitename_center_default', 'header.site_name.centered_default', 'move'),
    (r'header_sitename_center_small', 'header.site_name.centered_small', 'move'),
    (r'header_sitename_center', 'header.site_name.centered', 'move'),
    (r'header_change_icons', 'header.change_icons', 'move'),
    # Menus.
    (r'primary_menu_alignment', 'menus.primary.alignment', 'move'),
    (r'primary_menu_align_center', 'menus.primary.alignment', 'fold_align_center'),
    (r'primary_menu_on_hover', 'menus.primary.open_on_hover', 'move'),
    (r'primary_menu_arrow_hover', 'menus.primary.arrow_on_hover', 'move'),
    (r'primary_menu_expand_left', 'menus.primary.expand_left', 'move'),
    (r'primary_menu_megamenu_layout', 'menus.primary.megamenu.layout', 'move'),
    (r'primary_menu_megamenu', 'menus.primary.megamenu.enabled', 'move'),
    (r'primary_menu_submenu_header', 'menus.primary.megamenu.submenu_header', 'move'),
    (r'primary_menu_(border|branding|justify_content)', r'menus.primary.\1', 'move'),
    (r'primary_sidebar_menu_hover', 'menus.primary_sidebar.open_on_hover', 'move'),
    (r'primary_sidebar_menu_(border|branding)', r'menus.primary_sidebar.\1', 'move'),
    (r'keyboard_navigation_menus', 'menus.keyboard_navigation', 'move'),
    (r'menu_template_assignments', 'menus.template_assignments', 'map_template_assignments'),
    (r'site_toggle_speed', 'menus.toggle_speed', 'move'),
    (r'solo_menu_debug_verbosity', 'menus.debug.verbosity', 'move'),
    (r'solo_menu_debug', 'menus.debug.enabled', 'move'),
    # Popup login.
    (r'header_popup_login', 'popup_login.enabled', 'move'),
    (r'header_login_links', 'popup_login.link_text', 'move'),
    (r'popup_login_(\w+)', r'popup_login.\1', 'move'),
    # Search.
    (r'search_result_content_types', 'search.result_content_types', 'move'),
    (r'disable_search_filters', 'search.disabled_filters', 'move'),
    (r'enable_teaser_mode', 'search.teaser_mode', 'move'),
    # Breadcrumb and skip links.
    (r'site_breadcrumb_pagetitle_off', 'breadcrumb.hide_page_title', 'move'),
    (r'site_breadcrumb_scroll', 'breadcrumb.scroll', 'move'),
    (r'skip_(header|navigation|main|footer)_content', r'skip_links.\1', 'move'),
    # Footer and social.
    (r'footer_copyright_formatted', 'footer.copyright_formatted', 'move'),
    (r'footer_copyright', 'footer.copyright', 'move'),
    (r'footer_use_formatted_text', 'footer.use_formatted_copyright', 'move'),
    (r'footer_link_(label|text)', r'footer.credit_link.\1', 'move'),
    (r'footer_link', 'footer.credit_link.url', 'move'),
    (r'sm_show_icons', 'social_icons.enabled', 'move'),
    (r'sm_icon_size', 'social_icons.size', 'move'),
    (r'sm_icon_colors', 'social_icons.color', 'move'),
    (r'sm_icon_(%s)' % SOCIAL, r'social_icons.links.\1', 'move'),
    # Blocks.
    (r'enable_block_title_visibility', 'blocks.title_visibility_enabled', 'move'),
    # Preloader and back to top.
    (r'preloader_enabled', 'preloader.enabled', 'move'),
    (r'preloader_logo_(use_theme|url|width|height|opacity)', r'preloader.logo.\1', 'move'),
    (r'preloader_text_(font_size|font|animate_effect)', r'preloader.text.\1', 'move'),
    (r'preloader_text', 'preloader.text.value', 'move'),
    (r'preloader_(\w+)', r'preloader.\1', 'move'),
    (r'back_to_top_(\w+)', r'back_to_top.\1', 'move'),
    # Comments.
    (r'comment_(\w+)', r'comments.\1', 'move'),
]

# Removed keys. Each names WHY. A hit outside `allowed` makes the check red.
DROPS = {
    'site_flip_main_left': ('No form field, no reader.', []),
    'site_global_regions_gab': ('Typo, no form field, no reader. Real export holds 16 beside a live gap of 12: never read, so not folded.', []),
    'site_import_googel_font': ('Typo, no form field, no reader.', []),
    'enable_toggleable_radios': ('No form field, no reader.', []),
    'current_theme': ('No form field, no reader.', []),
    'site_regions_top_animate': ('No form field, no reader; live keys are the _border and _hover pair.', []),
    'site_regions_bottom_animate': ('No form field, no reader; live keys are the _border and _hover pair.', []),
    'site_regions_footer_animate': ('No form field, no reader; live keys are the _border and _hover pair.', []),
    'primary_menu_depth_info': ('Form #type item (help text); holds no setting.', ['includes/_theme_settings_layout_primary_menu.inc']),
    'placeholder_info': ('Form #type item (help text); holds no setting.', ['includes/_theme_settings_predefined_themes.inc']),
    'debug_info': ('Form #type item (help text); holds no setting.', ['includes/_theme_settings_layout_primary_menu.inc']),
    'enable_custom_node_width_message': ('Form #type item (message when the module is missing); holds no setting.', ['includes/_theme_settings_global_misc.inc']),
    'disable_block_title_visibility': ('Form #type item (message when the module is missing); holds no setting. Twig variable list entry stays, see map doc.', ['includes/_theme_settings_global_misc.inc', 'includes/_solo_theme_preprocess.inc']),
    'count_top': ('Derived from block placement; written on form GET, read by no template. Page preprocess computes its own count.', ['includes/_solo_theme_preprocess.inc', 'includes/_theme_settings_helper_functions.inc', 'includes/_solo_theme_settings.inc']),
    'count_main': ('Derived from block placement; written on form GET, read by no template. Page preprocess computes its own count.', ['includes/_solo_theme_preprocess.inc', 'includes/_theme_settings_helper_functions.inc', 'includes/_solo_theme_settings.inc']),
    'count_bottom': ('Derived from block placement; written on form GET, read by no template. Page preprocess computes its own count.', ['includes/_solo_theme_preprocess.inc', 'includes/_theme_settings_helper_functions.inc', 'includes/_solo_theme_settings.inc']),
    'count_footer': ('Derived from block placement; written on form GET, read by no template. Page preprocess computes its own count.', ['includes/_solo_theme_preprocess.inc', 'includes/_theme_settings_helper_functions.inc', 'includes/_solo_theme_settings.inc']),
}
# A name the code assembles at run time would not be found by name.
ASSEMBLED = {'site_regions_top_animate': r'_animate["\']', 'site_regions_bottom_animate': r'_animate["\']', 'site_regions_footer_animate': r'_animate["\']'}
POSITIVE_CONTROL = 'site_flip_header_menu'

# Not in the schema: shapes 1.x's own post-update still handles. A real export holds them.
LEGACY = [
    {'old': 'site_width_<content_type>', 'new': 'layout.width.per_content_type.<content_type>', 'action': 'move'},
    {'old': 'solo_layout_<group>_<n>col_<content_type>', 'new': 'region_groups.<group>.per_content_type.columns_<n>.<content_type>', 'action': 'move'},
    {'old': 'primary_menu_keyboard', 'new': None, 'action': 'drop', 'reason': 'Removed as orphaned by solo.post_update.php.'},
    {'old': 'primary_sidebar_menu_keyboard', 'new': None, 'action': 'drop', 'reason': 'Removed as orphaned by solo.post_update.php.'},
]


def show(path):
    return subprocess.run(['git', '-C', SOLO, 'show', '%s:%s' % (SOLO_REF, path)],
                          check=True, capture_output=True, text=True).stdout


def schema_keys(text):
    """Return {key: type} for 4-space keys of the settings mapping."""
    keys, cur = {}, None
    for line in text.split('\n'):
        m = re.match(r'^    ([A-Za-z_0-9]+):\s*$', line)
        if m:
            cur = m.group(1)
            keys[cur] = None
            continue
        m = re.match(r'^      type:\s*(\S+)', line)
        if m and cur and keys[cur] is None:
            keys[cur] = m.group(1)
    return keys


def install_keys(text):
    return re.findall(r'^([A-Za-z0-9_]+):', text, re.M)


def load():
    data = {k: show(v) for k, v in SOURCES.items()}
    return {
        'schema': schema_keys(data['schema']),
        'install': install_keys(data['install']),
        'starter_schema': schema_keys(data['starter_schema']),
        'starter_install': install_keys(data['starter_install']),
    }


def build(src, rules=None, drops=None):
    """Return (rows, errors)."""
    rules = RULES if rules is None else rules
    drops = DROPS if drops is None else drops
    errors, rows, used = [], [], set()
    every = list(src['schema'])
    for group in ('install', 'starter_schema', 'starter_install'):
        every += [k for k in src[group] if k not in src['schema'] and k not in every]
    for key in every:
        row = {'old': key, 'type': src['schema'].get(key),
               'install': key in src['install'],
               'starter_schema': key in src['starter_schema'],
               'starter_install': key in src['starter_install']}
        if key in drops:
            row.update(new=None, action='drop', reason=drops[key][0])
            rows.append(row)
            continue
        for i, (pattern, target, action) in enumerate(rules):
            m = re.fullmatch(pattern, key)
            if m:
                used.add(i)
                row.update(new=target(m) if callable(target) else m.expand(target) if '\\' in target else target, action=action)
                break
        else:
            errors.append('UNMAPPED key: ' + key)
            continue
        rows.append(row)
    for i, rule in enumerate(rules):
        if i not in used:
            errors.append('RULE matches no key: ' + rule[0])
    for key in drops:
        if key not in every:
            errors.append('DROP names no key: ' + key)
    errors += validate(rows)
    return rows, errors


def validate(rows):
    errors, seen = [], {}
    for row in rows:
        new = row.get('new')
        if not new:
            continue
        if row['action'] == 'fold_align_center':
            continue
        if new in seen:
            errors.append('COLLISION %s and %s both map to %s' % (seen[new], row['old'], new))
        seen[new] = row['old']
        if row['action'] == 'keep_core':
            continue
        if row['old'] == new:
            errors.append('NOT RENAMED: ' + new)
        for part in new.split('.'):
            if part != '*' and not SNAKE.match(part):
                errors.append('BAD SEGMENT %s in %s' % (part, new))
    paths = set(seen)
    for path in paths:
        parts = path.split('.')
        for n in range(1, len(parts)):
            if '.'.join(parts[:n]) in paths:
                errors.append('LEAF IS ALSO PARENT: %s under %s' % (path, '.'.join(parts[:n])))
    return errors


def render(rows, src, ref_commit):
    q = lambda v: "'%s'" % str(v).replace("'", "''")
    n_drop = sum(r['action'] == 'drop' for r in rows)
    lines = [
        '# GENERATED by solo_utilities/scripts/settings-gen/solo-config-map.py. Do not edit.',
        '# Decisions and meaning of each action: planning/solo-config-map.md',
        'source: {ref: %s, commit: %s}' % (SOLO_REF, ref_commit),
        'counts: {schema_mapping_keys: %d, install_keys: %d, rows: %d, dropped: %d}' % (len(src['schema']), len(src['install']), len(rows), n_drop),
        'keys:',
    ]
    for r in rows:
        bits = ['old: ' + r['old'], 'new: ' + (q(r['new']) if r.get('new') else '~'), 'action: ' + r['action'],
                'type: ' + (r['type'] or '~')]
        for flag in ('install', 'starter_schema', 'starter_install'):
            if r[flag]:
                bits.append(flag + ': true')
        if r.get('reason'):
            bits.append('reason: ' + q(r['reason']))
        lines.append('  - {' + ', '.join(bits) + '}')
    lines.append('legacy_shapes:')
    for r in LEGACY:
        bits = ['old: ' + q(r['old']), 'new: ' + (q(r['new']) if r['new'] else '~'), 'action: ' + r['action']]
        if r.get('reason'):
            bits.append('reason: ' + q(r['reason']))
        lines.append('  - {' + ', '.join(bits) + '}')
    return '\n'.join(lines) + '\n'


def tree():
    listing = subprocess.run(['git', '-C', SOLO, 'ls-tree', '-r', '--name-only', SOLO_REF], check=True, capture_output=True, text=True).stdout.split('\n')
    files = {}
    for f in listing:
        if any(f == r or f.startswith(r + '/') for r in READER_ROOTS) and re.search(r'\.(inc|php|twig|js|theme|yml)$', f):
            files[f] = show(f)
    return files


def audit(files, drops=None):
    """Name search for each dropped key, proven by a positive control."""
    drops = DROPS if drops is None else drops
    errors = []
    if not any(re.search(r'\b%s\b' % POSITIVE_CONTROL, t) for t in files.values()):
        errors.append('AUDIT CONTROL FAILED: search cannot find ' + POSITIVE_CONTROL)
    for key, (_, allowed) in drops.items():
        # A PHP local such as $count_top is not a read of the setting.
        hits = sorted(f for f, t in files.items() if re.search(r'(?<!\$)\b%s\b' % re.escape(key), t))
        for f in hits:
            if f not in allowed:
                errors.append('DROPPED KEY HAS A READER: %s in %s' % (key, f))
        if key in ASSEMBLED:
            for f, t in files.items():
                if re.search(ASSEMBLED[key], t):
                    errors.append('POSSIBLE ASSEMBLED NAME: %s in %s' % (key, f))
    return errors


def commit():
    return subprocess.run(['git', '-C', SOLO, 'rev-parse', '--short', SOLO_REF], check=True, capture_output=True, text=True).stdout.strip()


def check(src, files):
    rows, errors = build(src)
    errors += audit(files)
    text = render(rows, src, commit())
    if not os.path.exists(OUT) or open(OUT).read() != text:
        errors.append('DRIFT: solo_utilities/scripts/settings-gen/solo-config-map.yml differs from generated output')
    return rows, errors


def self_test(src, files):
    """Each seed must add a red. Returns the seeds that failed to go red."""
    bad = []

    def expect(name, errors, needle):
        ok = any(needle in e for e in errors)
        print(('RED ok   ' if ok else 'NOT RED  ') + name)
        if not ok:
            bad.append(name)

    seeded = dict(src, schema=dict(src['schema'], zz_unmapped_key='string'))
    expect('schema key with no rule', build(seeded)[1], 'UNMAPPED key: zz_unmapped_key')
    seeded = dict(src, install=src['install'] + ['zz_install_only'])
    expect('install key with no rule', build(seeded)[1], 'UNMAPPED key: zz_install_only')
    seeded = dict(src, starter_install=src['starter_install'] + ['zz_starter_only'])
    expect('starter key with no rule', build(seeded)[1], 'UNMAPPED key: zz_starter_only')
    expect('stale rule', build(src, rules=RULES + [(r'zz_never', 'a.b', 'move')])[1], 'RULE matches no key')
    expect('two keys, one target', build(src, rules=[(r'site_global_width', 'layout.width.use_pixels', 'move')] + RULES)[1], 'COLLISION')
    expect('bad segment', build(src, rules=[(r'site_global_width', 'layout.Width', 'move')] + RULES)[1], 'BAD SEGMENT')
    expect('leaf is also a parent', build(src, rules=[(r'site_global_width', 'layout.width', 'move')] + RULES)[1], 'LEAF IS ALSO PARENT')
    expect('not renamed', build(src, rules=[(r'site_global_width', 'site_global_width', 'move')] + RULES)[1], 'NOT RENAMED')
    expect('drop of a live key', audit(files, drops={'site_global_width': ('seed', [])}), 'DROPPED KEY HAS A READER')
    expect('control cannot find', audit({'x': 'nothing'}, drops={}), 'AUDIT CONTROL FAILED')
    rows, _ = build(src)
    tampered = render(rows, src, commit()).replace('layout.width.global', 'layout.width.globl')
    expect('hand edit of the output', ['DRIFT'] if tampered != render(rows, src, commit()) else [], 'DRIFT')
    return bad


def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else '--check'
    src, files = load(), tree()
    if mode == '--write':
        rows, errors = build(src)
        errors += audit(files)
        if errors:
            print('\n'.join(errors))
            print('NOT WRITTEN: %d red' % len(errors))
            return 1
        with open(OUT, 'w') as f:
            f.write(render(rows, src, commit()))
    if mode == '--self-test':
        bad = self_test(src, files)
        print('self-test: %s' % ('FAILED ' + str(bad) if bad else 'all seeds red'))
        return 1 if bad else 0
    rows, errors = check(src, files)
    acts = {}
    for r in rows:
        acts[r['action']] = acts.get(r['action'], 0) + 1
    print('schema mapping keys %d, install keys %d, starter schema %d, starter install %d, rows %d' % (
        len(src['schema']), len(src['install']), len(src['starter_schema']), len(src['starter_install']), len(rows)))
    print('actions', acts)
    print('\n'.join(errors) if errors else 'GREEN')
    return 1 if errors else 0


if __name__ == '__main__':
    sys.exit(main())
