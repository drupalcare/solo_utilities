#!/usr/bin/env python3
"""Fail when a Solo setting has no feature, has two, or the feature list names nothing real.

config/map/solo-features.yml says what each setting is for. The settings themselves are owned by
config/map/solo-config-map.yml (every key carried from 1.x) and config/map/solo-2.0-defaults.yml
(keys that exist only in 2.0). This check holds the feature list to those two files.

  check:     python3 -I scripts/check-features.py
  self-test: python3 -I scripts/check-features.py --self-test

Standard library only. Not a test: it reads the shipped files and runs no site code.
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MAP = ROOT / "config/map"
SECTIONS = ROOT / "src/Form/ThemeSettings/Section"


def carried_keys(text):
    """Returns the new keys of the 1.x map."""
    found = set()
    for key in re.findall(r"\{old: [^,]+, new: ([^,}]+)", text):
        key = key.strip().strip("'\"")
        if key != "~":
            found.add(key)
    return found


def default_keys(text):
    """Returns the dotted leaf keys of the 2.0 defaults file."""
    stack = []
    found = set()
    for line in text.split("\n"):
        stripped = line.strip()
        if not stripped or stripped.startswith(("#", "- ")) or ":" not in stripped:
            continue
        indent = len(line) - len(line.lstrip())
        key, value = stripped.split(":", 1)
        while stack and stack[-1][0] >= indent:
            stack.pop()
        stack.append((indent, key))
        if value.strip():
            found.add(".".join(part[1] for part in stack))
    return found


def parse_features(text):
    """Returns the feature entries of solo-features.yml."""
    features = []
    for line in text.split("\n"):
        match = re.match(r"\s*(- )?(id|label|form|description|settings):\s*(.*)$", line)
        if not match or line.lstrip().startswith("#"):
            continue
        if match.group(1):
            features.append({})
        if not features:
            continue
        key, value = match.group(2), match.group(3).strip()
        if key == "settings":
            features[-1][key] = [item.strip().strip("'\"") for item in value.strip("[]").split(",") if item.strip()]
        else:
            features[-1][key] = value.strip("'\"")
    return features


def matcher(pattern):
    """Returns the regular expression for one settings entry."""
    return re.compile("^" + ".*".join(re.escape(part) for part in pattern.split("*")) + "$")


def problems(features, keys, form_exists):
    """Returns every disagreement between the feature list and the settings."""
    found = []
    seen = set()
    owners = {key: [] for key in keys}
    for feature in features:
        name = feature.get("id", "(no id)")
        for field in ("id", "label", "description", "settings"):
            if not feature.get(field):
                found.append(f"{name}: no {field}")
        if name in seen:
            found.append(f"{name}: duplicate id")
        seen.add(name)
        if feature.get("form") and not form_exists(feature["form"]):
            found.append(f"{name}: form {feature['form']} does not exist")
        for pattern in feature.get("settings", []):
            regex = matcher(pattern)
            hits = [key for key in keys if regex.match(key)]
            if not hits:
                found.append(f"{name}: {pattern} matches no setting")
            for key in hits:
                owners[key].append(name)
    for key in sorted(keys):
        if not owners[key]:
            found.append(f"{key}: no feature")
        elif len(set(owners[key])) > 1:
            found.append(f"{key}: owned by {', '.join(sorted(set(owners[key])))}")
    return found


def self_test():
    """Returns 0 when every planted defect is reported."""
    good = [{"id": "a", "label": "A", "description": "D", "settings": ["x.*"]}]
    cases = {
        "clean": (good, {"x.y"}, 0),
        "no feature": (good, {"x.y", "z"}, 1),
        "matches nothing": ([dict(good[0], settings=["x.*", "q"])], {"x.y"}, 1),
        "two owners": (good + [dict(good[0], id="b")], {"x.y"}, 1),
        "no label": ([{"id": "a", "description": "D", "settings": ["x.*"]}], {"x.y"}, 1),
    }
    failed = 0
    for name, (features, keys, expected) in cases.items():
        count = len(problems(features, keys, lambda form: True))
        if count != expected:
            print(f"SELF-TEST FAIL {name}: {count} problems, expected {expected}")
            failed += 1
    if len(parse_features("features:\n  - id: a\n    settings: [b.c, 'd.*']\n")[0]["settings"]) != 2:
        print("SELF-TEST FAIL parser")
        failed += 1
    print("check-features self-test: " + ("FAIL" if failed else "PASS"))
    return 1 if failed else 0


def main():
    """Runs the check."""
    if "--self-test" in sys.argv:
        return self_test()
    keys = carried_keys((MAP / "solo-config-map.yml").read_text(encoding="utf-8"))
    keys |= default_keys((MAP / "solo-2.0-defaults.yml").read_text(encoding="utf-8"))
    features = parse_features((MAP / "solo-features.yml").read_text(encoding="utf-8"))
    found = problems(features, keys, lambda form: (SECTIONS / f"{form}.php").is_file())
    for line in found:
        print(line)
    print(f"check-features: {len(features)} features, {len(keys)} settings, {len(found)} problems")
    return 1 if found or not keys or not features else 0


if __name__ == "__main__":
    sys.exit(main())
