#!/usr/bin/env python3
"""One-off helper to close out B1 in CHANGELOG + ROADMAP.

Will be deleted after the run. Uses simple string replace, no regex,
so no Unicode/whitespace anchor matching issues with bash quoting or
str_replace.
"""
import os
import sys

CHG_PATH = (
    r"C:/Users/adini/Local Sites/treets/app/public/"
    r"wp-content/plugins/novamira-adrianv2/CHANGELOG.md"
)
ROAD_PATH = (
    r"C:/Users/adini/Local Sites/treets/app/public/"
    r"wp-content/plugins/novamira-adrianv2/.planning/ROADMAP.md"
)

B1_BULLET = (
    "- **B1** - Real `_elementor_data` calibration sample committed at "
    "`.claude/skills/elementor/samples/treets-homepage.json` (749 bytes, "
    "verbatim dump of local-WP post 5373; round-trips 1:1 through the new "
    "`novamira-adrianv2/elementor-inject-calibrated-page` MCP ability with "
    "`sections_count=1` + the original 5 element IDs preserved). Sibling "
    "`samples/README.md` carries the sanitisation log + MIT licence + SPDX "
    "short-form header + a 'Known gap' section documenting the contradiction "
    "with turn-2 CHANGELOG's upstream-skill-install claim (the upstream "
    "`.claude/skills/elementor/` folder is currently empty except for the new "
    "sample + README; re-install recipe ships in that section for the next "
    "maintainer). Because the live payload at dump-time was already "
    "privacy-clean (0 treets.local URLs, 0 image sub-objects, 0 "
    "adrianv2/adranv2/Inject/Calibrated tokens), sanitisation was effectively "
    "a no-op plus audit trail. Dump-helper scripts are NOT shipped.\n"
)

SNAPSHOT_ROW_7 = (
    "\n| 7 | **B1 abgeschlossen**: calibration sample at "
    "`.claude/skills/elementor/samples/treets-homepage.json` (749 bytes) + "
    "sibling `samples/README.md` (~9.6 KB) committed. Live payload was "
    "already privacy-clean (0 URLs, 0 images, 0 sensitive tokens) - "
    "sanitisation was a 1:1 copy + audit trail. Honest gap-flagging for the "
    "upstream `.claude/skills/elementor/` folder (currently empty of "
    "upstream 10 files contrary to turn-2 CHANGELOG claim): README 'Known "
    "gap' section ships a 3-step re-install recipe (git clone + cp -r + "
    ".gitattributes verify). Dump-helper scripts deleted post-audit. | "
    "\u2705 | laufende Session |"
)

B1_STATUS = (
    "\n\n**Status:** \u2705 gefixt + verifiziert (2026-06-18). Siehe "
    "`CHANGELOG.md [Unreleased] / ### Added` (B1-Absatz) + die in "
    "`.claude/skills/elementor/samples/` committed Files "
    "(`treets-homepage.json` 749 Bytes + `README.md` ~9.6 KB mit "
    "SPDX-Header + Sanitisierungs-Log + 'Known gap' Section + "
    "Re-install-Recipe). Live payload aus Post 5373 war zur Dump-Zeit "
    "vollstaendig privacy-clean: Sanitisierung war effektiv ein no-op plus "
    "Audit-Trail. Sample-Groesse 749 Bytes ist ~270x unter dem "
    "200 KB-Limit fuer Commit-faehige Calibration-Files. PHPUnit-Pflichtsuite "
    "fuer B1 nicht definiert (B1 ist Daten-Output, kein PHP-Code); "
    "Acceptance-Test ist der Round-Trip ueber "
    "`novamira-adrianv2/elementor-inject-calibrated-page` (von C1 "
    "PHPUnit-Layer garantiert) - `sections_count=1` + 5 Original-Element-IDs "
    "conserviert.\n"
)


def edit_changelog() -> bool:
    txt = open(CHG_PATH, encoding="utf-8").read()
    if B1_BULLET.strip() in txt:
        print("CHANGELOG already has B1 bullet; skipping")
        return True
    anchor = "\n### Third-party\n"
    if anchor not in txt:
        print("CHANGELOG anchor '### Third-party' not found")
        return False
    new_txt = txt.replace(anchor, "\n" + B1_BULLET + anchor, 1)
    open(CHG_PATH, "w", encoding="utf-8").write(new_txt)
    print(f"CHANGELOG appended B1 bullet (vor={len(txt)}B, nach={len(new_txt)}B)")
    return True


def edit_roadmap_snapshot() -> bool:
    txt = open(ROAD_PATH, encoding="utf-8").read()
    if "**B1 abgeschlossen**" in txt:
        print("ROADMAP snapshot row 7 already present; skipping")
        return True
    anchor = "| \u2705 | laufende Session |\n\n---"
    if anchor not in txt:
        print("ROADMAP snapshot-row anchor not found; trying alternate")
        # Try alternate form (possibly without the dashes heading boundary)
        anchor2 = "| \u2705 | laufende Session |\n\n---\n\n## B."
        if anchor2 not in txt:
            print("ROADMAP snapshot-row anchor not found at all")
            return False
        new_txt = txt.replace(anchor2, anchor2.replace("\n\n---\n\n## B.", "\n" + SNAPSHOT_ROW_7 + "\n\n---\n\n## B."), 1)
    else:
        new_txt = txt.replace(anchor, anchor.replace("\n\n---", "\n" + SNAPSHOT_ROW_7 + "\n\n---"), 1)
    open(ROAD_PATH, "w", encoding="utf-8").write(new_txt)
    print(f"ROADMAP appended snapshot row 7 (vor={len(txt)}B, nach={len(new_txt)}B)")
    return True


def edit_roadmap_b1_status() -> bool:
    txt = open(ROAD_PATH, encoding="utf-8").read()
    if "B1-Absatz" in txt and "Status-Header" not in txt:
        # Check if 'Status: gefixt' line is already in for B1
        if "Status:** \u2705 gefixt + verifiziert (2026-06-18). Siehe `CHANGELOG.md [Unreleased] / ### Added` (B1-Absatz)" in txt:
            print("ROADMAP B1 status block already present; skipping")
            return True
    anchor = (
        "### B1. Echte `_elementor_data` aus Local-WP exportieren  "
        "\u00b7  **MED \u00b7 S \u00b7 MED**\n\n**Problem (war):**"
    )
    if anchor not in txt:
        print("ROADMAP B1 status anchor not found; trying simplified anchor")
        anchor_simple = "**MED \u00b7 S \u00b7 MED**\n\n**Problem (war):**"
        if anchor_simple not in txt:
            print("ROADMAP B1 status simplified anchor not found either")
            return False
        new_txt = txt.replace(anchor_simple, anchor_simple.replace("\n\n**Problem (war):**", B1_STATUS + "\n**Problem (war):**"), 1)
    else:
        new_txt = txt.replace(anchor, anchor.replace("\n\n**Problem (war):**", B1_STATUS + "\n**Problem (war):**"), 1)
    open(ROAD_PATH, "w", encoding="utf-8").write(new_txt)
    print(f"ROADMAP inserted B1 status block (vor={len(txt)}B, nach={len(new_txt)}B)")
    return True


def main():
    ok_chg = edit_changelog()
    ok_road_snap = edit_roadmap_snapshot()
    ok_road_b1 = edit_roadmap_b1_status()
    print(f"DONE chg={ok_chg} road_snap={ok_road_snap} road_b1={ok_road_b1}")
    sys.exit(0 if (ok_chg and ok_road_snap and ok_road_b1) else 1)


if __name__ == "__main__":
    main()
