# tools/

Utility scripts for the Novamira AdrianV2 plugin. Run from the WordPress root directory.

## Scripts

### `batch-convert.php`

Batch V3-to-V4 page converter. Discovers all Elementor V3 pages and converts them with auto-fix enabled.

```bash
# Dry-run (preview only, no database writes)
php wp-content/plugins/novamira-adrianv2/tools/batch-convert.php --dry-run

# Execute (persists changes, creates _novamira_v3_backup)
php wp-content/plugins/novamira-adrianv2/tools/batch-convert.php --execute
```

**What it does:**
1. Discovers all published V3 pages (any post type) via `List_Elementor_Pages`
2. Converts each page tree with `auto_fix=true` (empty containers, responsive variants, etc.)
3. Creates a V3 backup in `_novamira_v3_backup` post meta before overwriting

**Flags:**
| Flag | Description |
|---|---|
| `--dry-run` | Preview only — shows what would happen without writing |
| `--execute` | Actually persist the converted V4 data |

**Note:** Run `kit-convert-v3-to-v4` with `dry_run=false` once before batch conversion to set up design-system mappings.

### `quick_check.php`

Quick dry-run validation for specific pages. Hardcoded for pages 3598 and 5368 — edit the page IDs array to check other pages.

```bash
php wp-content/plugins/novamira-adrianv2/tools/quick_check.php
```

**Output:** Fixes count and audit issue count per page.
