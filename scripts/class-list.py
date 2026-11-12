#!/usr/bin/env python3
"""Builds the Solo rename list (Markdown and HTML) from the maps, and checks it for drift.

Usage (python only, no Drupal and no PyYAML needed):
  python3 -I scripts/class-list.py             write solo/docs/CLASS-MIGRATION.md and solo/docs/class-migration.html
  python3 -I scripts/class-list.py --check     exit 1 when a doc differs from the maps, or a map is inconsistent
  python3 -I scripts/class-list.py --self-test prove the check goes red when a row is removed
  --json PATH                                  also compare with a rename-map JSON (default: the lane's
                                               planning/rename-map-solo-2026-11-10.json when it exists)

The one source of truth is config/map/solo-class-map.yml (Solo 1 names),
config/map/solo-rename-map.yml (names changed while 2.0 was in development) and
config/map/solo-variable-map.yml (CSS variables, `removed`). Never edit the doc by hand.
The Solo theme folder is SOLO_THEME_DIR, or ../solo next to this module.
"""
import html
import json
import os
import pathlib
import re
import sys
import tempfile

MODULE = pathlib.Path(__file__).resolve().parents[1]
MAPS = MODULE / "config" / "map"
THEME = pathlib.Path(os.environ.get("SOLO_THEME_DIR") or MODULE.parent / "solo")
LANE_JSON = MODULE.parent / "planning" / "rename-map-solo-2026-11-10.json"
ACTIONS = ("rewrite", "alias", "report-only")
JS_KINDS = ("behavior", "once-id", "drupalsettings-key", "data-attribute", "js-event")
KIND_TITLES = {
    "behavior": "JavaScript behaviors",
    "once-id": "once() ids and data-once values",
    "drupalsettings-key": "drupalSettings keys",
    "data-attribute": "Data attributes",
    "js-event": "JavaScript events",
}


# ---------------------------------------------------------------- mini YAML --

def scalar(text):
    text = text.strip()
    if text.startswith("'"):
        end = 1
        out = ""
        while end < len(text):
            if text[end] == "'":
                if text[end + 1:end + 2] == "'":
                    out += "'"
                    end += 2
                    continue
                return out, text[end + 1:]
            out += text[end]
            end += 1
        raise ValueError("unterminated string: " + text)
    m = re.match(r"[^,}\]]*", text)
    return m.group(0).strip(), text[m.end():]


def value(text):
    """Parses a scalar, a {flow: map} or a [flow, list]; returns (value, rest)."""
    text = text.strip()
    if text.startswith("{"):
        out, rest = {}, text[1:].strip()
        while not rest.startswith("}"):
            m = re.match(r"([A-Za-z0-9_-]+):\s*", rest)
            if not m:
                raise ValueError("bad flow map: " + text)
            v, rest = value(rest[m.end():])
            out[m.group(1)] = v
            rest = rest.lstrip(", ").strip()
        return out, rest[1:]
    if text.startswith("["):
        out, rest = [], text[1:].strip()
        while not rest.startswith("]"):
            v, rest = value(rest)
            out.append(v)
            rest = rest.lstrip(", ").strip()
        return out, rest[1:]
    return scalar(text)


def parse(path):
    """Reads one map: top-level `key: scalar|flow` and `section:` blocks of `  'k': value` rows."""
    data, section = {}, None
    for number, raw in enumerate(path.read_text().splitlines(), 1):
        if not raw.strip() or raw.lstrip().startswith("#"):
            continue
        if not raw.startswith(" "):
            key, _, rest = raw.partition(":")
            rest = rest.strip()
            if rest:
                data[key], _ = value(rest)
                section = None
            else:
                data[key] = {}
                section = key
            continue
        if section is None:
            raise ValueError("%s:%d row outside a section" % (path.name, number))
        row = raw.strip()
        if row.startswith("- "):
            if not isinstance(data[section], list):
                data[section] = []
            data[section].append(value(row[2:])[0])
            continue
        if row.startswith("'"):
            key, rest = scalar(row)
            rest = rest.lstrip()[1:]
        else:
            key, _, rest = row.partition(":")
        if not isinstance(data[section], dict):
            raise ValueError("%s:%d mixed section" % (path.name, number))
        data[section][key.strip()] = value(rest)[0]
    return data


def load_maps(root=MAPS):
    return parse(root / "solo-class-map.yml"), parse(root / "solo-rename-map.yml"), parse(root / "solo-variable-map.yml")


# ------------------------------------------------------------------ the data --

def effective(classes, renames):
    """The Solo 1 rows chained to the final names, as SoloRenameMap::chain() does."""
    final = {old: row["new"] for old, row in (renames.get("classes") or {}).items()}
    out = {}
    for section in ("structural", "ids", "state", "generic"):
        out[section] = {k: final.get(v, v) for k, v in (classes.get(section) or {}).items()}
    return out


def sections(classes, renames, variables):
    """Everything the list shows: [(id, title, columns, rows, note)]. Rows are lists of plain strings."""
    eff = effective(classes, renames)
    ren = renames
    out = []

    def pairs(d):
        return [[k, v] for k, v in d.items()]

    out.append(("classes-1", "Classes: Solo 1 to the current name", ("Solo 1", "Now"), pairs(eff["structural"]),
                "A trailing `*` is a dynamic tail: the old prefix followed by any value becomes the new prefix with the same value."))
    out.append(("ids-1", "Ids: Solo 1 to the current name", ("Solo 1", "Now"), pairs(eff["ids"]), ""))
    out.append(("state-1", "State classes set by Solo JavaScript (chained to a Solo selector only)", ("Solo 1", "Now"), pairs(eff["state"]), ""))
    out.append(("generic-1", "Generic words (chained to a Solo selector only)", ("Solo 1", "Now"), pairs(eff["generic"]), ""))
    ctx = [[k, " or ".join(v.get("new") or []) + ((" - " + v["note"]) if v.get("note") else "")]
           for k, v in (classes.get("context") or {}).items()]
    out.append(("context-1", "Context-dependent classes (pick by hand)", ("Solo 1", "Use"), ctx, ""))
    out.append(("removed-1", "Removed classes (no new element)", ("Solo 1", "Why"), pairs(classes.get("removed") or {}), ""))
    out.append(("classes-2", "Classes renamed again while 2.0 was in development", ("Earlier 2.0 name", "Now"),
                [[k, v["new"]] for k, v in (ren.get("classes") or {}).items()], "A Solo 1 name above already points at the final name."))
    out.append(("libraries", "Library ids", ("Old id", "New id", "Action"),
                [[k, v["new"], v["action"]] for k, v in (ren.get("libraries") or {}).items()],
                "`alias`: the old id keeps working until the next major release and Drupal logs a deprecation. "
                "`report-only`: the old library was split, so pick the new ids that you need."))
    out.append(("files", "Files moved, renamed, split or merged", ("Old path", "New path", "What"),
                [[k, v["new"], v.get("op", "")] for k, v in (ren.get("files") or {}).items()],
                "Report only: `libraries-override` and `libraries-extend` entries that name an old path need the new one."))
    js = ren.get("js") or {}
    for kind in JS_KINDS:
        rows = [[k, v["new"], v["action"]] for k, v in js.items() if v.get("kind") == kind]
        out.append(("js-" + kind, KIND_TITLES[kind], ("Old", "New", "Action"), rows,
                    "`alias`: the old name keeps working for one major release. `report-only`: pick the new name by hand." if kind == "behavior" else ""))
    out.append(("variables", "CSS variables (region colors)", ("Old", "New"), pairs(variables.get("removed") or {}),
                "An old name still reads the new one (a deprecated archive file in Solo). A rule that sets an old name no longer changes anything: rewrite it."))
    frozen = classes.get("frozen") or []
    out.append(("frozen", "Unchanged on purpose", ("Name",), [[x] for x in frozen],
                "Font and animation value classes are public names and keep their Solo 1 spelling."))
    return [s for s in out if s[3]]


INTRO = """# CSS class migration: Solo 1 and earlier 2.0 names to the current names

Generated from `config/map/solo-class-map.yml`, `config/map/solo-rename-map.yml` and `config/map/solo-variable-map.yml` in Solo Utilities by `scripts/class-list.py`. Do not edit by hand.

Solo renames its classes, ids, library ids, files, JavaScript names and CSS variables. A sub-theme or custom module that uses an old name stops matching until it is migrated. Solo Utilities ships a Drush command that does the renaming for you, and a Solo 1 name maps straight to its current name.

## Before you start: let Drush do the renaming

1. Save a copy of your theme (or commit it to Git).
2. Dry run, with a report written outside the web root (run from the project folder):

   ```
   drush solo:migrate-classes mytheme --stored --content --report=solo-report.md
   ```

   Nothing is written. The target is a theme machine name or a folder inside the Drupal root (`web/themes/custom/mytheme`, `web/modules/custom/my_module`). Drupal core, Solo itself and the public files folder are refused, and symbolic links are never followed.
3. Read the report. Configuration and content come first, then each file and its changed lines, then the items to check by hand.
4. Apply: `drush solo:migrate-classes mytheme --apply`. Add `--yml` to rewrite library ids in the theme `.info.yml` and `.libraries.yml` files, `--stored` to save the configuration changes, and `--content` to save id changes in content (each changed item becomes a new revision).
5. Clear the caches: `drush cr`, then check your pages.

What the command changes, by kind:

| Kind | Rewritten in | Only reported in |
|---|---|---|
| Classes and ids | `.css .scss .sass .less .twig .js` | `.theme .php .module .inc` |
| CSS variables | `.css .scss .sass .less .twig .js` | `.theme .php .module .inc` |
| Library ids | `.twig`, and `.info.yml` and `.libraries.yml` with `--yml` | `.theme .php .module .inc`, and the YAML files without `--yml` |
| JavaScript names | `.js` (data attributes and once ids also `.css .twig`) | `.theme .php .module .inc` |
| Files moved or renamed | never | every file type it reads |

`--ext=css,js` narrows the file types. Other `.yml` files, `*.min.*`, `node_modules`, `vendor`, `dist` and `.git` are never read. Old library ids keep working as deprecated aliases for one major release, so a missed id is a log line, not a broken page.

What `--stored` covers: the Solo inline CSS setting (`custom_code.inline_css`, also renamed automatically by `drush updb`), Block Class classes, Views CSS, row and field classes, and text format allowed tags with the matching CKEditor 5 settings. `--content` renames only ids, in formatted text fields and menu link attributes.

Report-only rules: a name that is one plain word, or is both a class and an id, is renamed only where the line plainly sets classes (or ids). State classes (`toggled`, `small-screen` ...) and generic words (`facebook`, `header` ...) are renamed only when chained to a Solo selector in a stylesheet; elsewhere they are reported. A renamed name with two possible new names (a split or a merge) is never rewritten, only reported.
"""


def markdown(secs):
    out = [INTRO, "## What is on this list", "", "| Kind | Names |", "|---|---|"]
    for sid, title, cols, rows, note in secs:
        out.append("| %s | %d |" % (title, len(rows)))
    out.append("")
    for sid, title, cols, rows, note in secs:
        out.extend(["## %s (%d)" % (title, len(rows)), ""])
        if note:
            out.extend([note, ""])
        out.append("| " + " | ".join(cols) + " |")
        out.append("|" + "---|" * len(cols))
        for row in rows:
            out.append("| " + " | ".join("`%s`" % c.replace("|", "\\|") if i < 2 or len(cols) == 1 else c.replace("|", "\\|")
                                         for i, c in enumerate(row)) + " |")
        out.append("")
    return "\n".join(out).rstrip("\n") + "\n"


def page(secs):
    css = ("body{font:16px/1.5 system-ui,sans-serif;max-width:72rem;margin:0 auto;padding:1rem;color:#1c2430}"
           "h1{font-size:1.6rem}summary{cursor:pointer;font-weight:600;padding:.5rem 0}table{border-collapse:collapse;width:100%;margin:.5rem 0 1.5rem}"
           "th,td{border-bottom:1px solid #d0d7de;padding:.3rem .5rem;text-align:left;vertical-align:top;word-break:break-word}"
           "th{background:#f3f5f7}code{font-size:.9em}input{font:inherit;padding:.4rem;width:100%;max-width:28rem}"
           ".count{color:#57606a;font-weight:400}.note{color:#57606a}")
    js = ("document.getElementById('q').addEventListener('input',function(e){var t=e.target.value.toLowerCase();"
          "document.querySelectorAll('tbody tr').forEach(function(r){r.hidden=t&&r.textContent.toLowerCase().indexOf(t)<0})});")
    out = ["<!DOCTYPE html>", '<html lang="en"><head><meta charset="utf-8">', "<title>Solo rename list</title>",
           '<meta name="viewport" content="width=device-width,initial-scale=1">', "<style>%s</style></head><body>" % css,
           "<h1>Solo rename list</h1>",
           "<p class=\"note\">Generated by <code>scripts/class-list.py</code> from the Solo Utilities maps. Do not edit by hand. "
           "Run <code>drush solo:migrate-classes mytheme</code> for a dry run; see CLASS-MIGRATION.md for the steps.</p>",
           '<p><label>Filter all tables <input id="q" type="search" placeholder="type part of a name"></label></p>', "<ul>"]
    for sid, title, cols, rows, note in secs:
        out.append('<li><a href="#%s">%s</a> <span class="count">%d</span></li>' % (sid, html.escape(title), len(rows)))
    out.append("</ul>")
    for sid, title, cols, rows, note in secs:
        out.append('<details id="%s"><summary>%s <span class="count">(%d)</span></summary>' % (sid, html.escape(title), len(rows)))
        if note:
            out.append('<p class="note">%s</p>' % html.escape(note.replace("`", "")))
        out.append("<table><thead><tr>%s</tr></thead><tbody>" % "".join("<th>%s</th>" % html.escape(c) for c in cols))
        for row in rows:
            cells = "".join("<td>%s</td>" % ("<code>%s</code>" % html.escape(c) if i < 2 or len(cols) == 1 else html.escape(c)) for i, c in enumerate(row))
            out.append("<tr>%s</tr>" % cells)
        out.append("</tbody></table></details>")
    out.append("<script>%s</script></body></html>" % js)
    return "\n".join(out) + "\n"


# ------------------------------------------------------------------- checks --

def counts_of(renames):
    js = renames.get("js") or {}
    return {
        "classes": len(renames.get("classes") or {}),
        "libraries": len(renames.get("libraries") or {}),
        "files": len(renames.get("files") or {}),
        "js": len(js),
    }


def consistency(classes, renames, variables):
    problems = []
    declared = renames.get("counts") or {}
    for kind, n in counts_of(renames).items():
        if str(declared.get(kind)) != str(n):
            problems.append("counts line says %s: %s, the rows say %d" % (kind, declared.get(kind), n))
    for section in ("classes", "libraries", "files", "js"):
        for old, row in (renames.get(section) or {}).items():
            if row.get("action") not in ACTIONS and section != "files":
                problems.append("%s %s: action %r is not one of %s" % (section, old, row.get("action"), ACTIONS))
            if not row.get("new"):
                problems.append("%s %s: no new name" % (section, old))
            if section == "js" and row.get("kind") not in JS_KINDS:
                problems.append("js %s: unknown kind %r" % (old, row.get("kind")))
    olds = set(renames.get("classes") or {})
    for section in ("structural", "ids"):
        olds |= set(classes.get(section) or {})
    news = {v["new"] for v in (renames.get("classes") or {}).values()}
    for section in ("structural", "ids"):
        news |= set((classes.get(section) or {}).values())
    for name in sorted((olds & news) - {n for n in olds if n.endswith("*")}):
        problems.append("class %s is both an old and a new name (a second run would rename it again)" % name)
    for section in ("libraries", "files"):
        old = set((renames.get(section) or {}))
        new = {r["new"] for r in (renames.get(section) or {}).values() if r.get("action") != "report-only"}
        for name in sorted(old & new):
            problems.append("%s %s is both an old and a new name" % (section, name))
    both = set(variables.get("removed") or {}) & {"--r-" + r for r in (variables.get("roles") or {})}
    for name in sorted(both):
        problems.append("variable %s is both an old and a current name" % name)
    return problems


def against_json(renames, data):
    """Compares the map with the rename-map JSON; the names are the rows the JSON says change."""
    problems = []
    json_classes = {r["old"] for r in data["classes"] if r.get("action") == "rename"}
    json_libs = {r["old"] for r in data["libraries"] if r["old"] != r["new"]}
    json_files = {r["old"] for r in data["files"] if r["old"] != r["new"]}
    json_js = {r["old"] for r in data["js"] if r.get("action") == "rename" and r["old"] != r["new"]}
    for kind, want in (("classes", json_classes), ("libraries", json_libs), ("files", json_files), ("js", json_js)):
        have = set(renames.get(kind) or {})
        for name in sorted(want - have):
            problems.append("JSON row missing from the map (%s): %s" % (kind, name))
        for name in sorted(have - want):
            problems.append("map row not in the JSON (%s): %s" % (kind, name))
    return problems


def run_check(root, theme, json_path):
    problems = []
    classes, renames, variables = load_maps(root)
    problems += consistency(classes, renames, variables)
    secs = sections(classes, renames, variables)
    for name, text in (("CLASS-MIGRATION.md", markdown(secs)), ("class-migration.html", page(secs))):
        target = theme / "docs" / name
        if not target.exists() or target.read_text() != text:
            problems.append("doc drift: %s differs from the maps (run class-list.py)" % target)
    if json_path and json_path.exists():
        problems += against_json(renames, json.loads(json_path.read_text()))
        print("JSON compared: %s" % json_path)
    else:
        print("JSON skipped: no rename-map JSON found (pass --json PATH)")
    print("rows by kind: %s; variables: %d" % (counts_of(renames), len(variables.get("removed") or {})))
    return problems


def self_test():
    """Plants a removed row in a temporary copy of the maps and expects the check to go red."""
    json_path = LANE_JSON if LANE_JSON.exists() else None
    with tempfile.TemporaryDirectory() as tmp:
        tmp = pathlib.Path(tmp)
        root = tmp / "map"
        root.mkdir()
        for f in MAPS.glob("*.yml"):
            (root / f.name).write_text(f.read_text())
        theme = tmp / "solo"
        (theme / "docs").mkdir(parents=True)
        secs = sections(*load_maps(root))
        (theme / "docs" / "CLASS-MIGRATION.md").write_text(markdown(secs))
        (theme / "docs" / "class-migration.html").write_text(page(secs))
        control = run_check(root, theme, json_path)
        assert control == [], "control run is not clean: %s" % control[:3]
        path = root / "solo-rename-map.yml"
        lines = path.read_text().splitlines()
        index = next(i for i, l in enumerate(lines) if l.startswith("  'solo-file-button'"))
        del lines[index]
        path.write_text("\n".join(lines) + "\n")
        planted = run_check(root, theme, json_path)
        kinds = {"counts": any("counts line" in p for p in planted), "doc": any("doc drift" in p for p in planted),
                 "json": any("JSON row missing" in p for p in planted) if json_path else True}
        assert all(kinds.values()), "planted row not caught: %s %s" % (kinds, planted[:3])
        print("SELF-TEST PASS: control clean; removing one row is caught by %s" % kinds)


def main(argv):
    if "--self-test" in argv:
        self_test()
        return 0
    json_path = LANE_JSON if LANE_JSON.exists() else None
    if "--json" in argv:
        json_path = pathlib.Path(argv[argv.index("--json") + 1])
    if "--check" in argv:
        problems = run_check(MAPS, THEME, json_path)
        for p in problems:
            print("DRIFT: " + p)
        print("CLASS LIST: %s" % ("PASS" if not problems else "FAIL (%d)" % len(problems)))
        return 1 if problems else 0
    classes, renames, variables = load_maps()
    problems = consistency(classes, renames, variables)
    if problems:
        for p in problems:
            print("MAP PROBLEM: " + p)
        return 2
    secs = sections(classes, renames, variables)
    (THEME / "docs").mkdir(exist_ok=True)
    (THEME / "docs" / "CLASS-MIGRATION.md").write_text(markdown(secs))
    (THEME / "docs" / "class-migration.html").write_text(page(secs))
    print("wrote %s/docs/CLASS-MIGRATION.md and class-migration.html (%d sections)" % (THEME, len(secs)))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
