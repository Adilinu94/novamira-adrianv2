#!/usr/bin/env bash
# deploy-plugin.sh — Sprint 10
#
# Copies changed plugin files from the working directory to the Local WP
# installation at solar.local. Uses rsync-style logic: only copies files
# that have been modified since the last deployment marker.
#
# Usage:
#   ./scripts/deploy-plugin.sh              # Deploy all plugin files
#   ./scripts/deploy-plugin.sh --dry-run    # Show what would be copied
#   ./scripts/deploy-plugin.sh --force       # Copy all files regardless
#   ./scripts/deploy-plugin.sh --help        # This help
#
# Paths:
#   Source:      novamira-adrianv2/
#   Destination: C:/Users/adini/Local Sites/solar/app/public/wp-content/plugins/novamira-adrianv2/
#
# Exit codes:
#   0 = deployment successful
#   1 = deployment failed (target not found, copy error)

set -euo pipefail

# ── Paths ──────────────────────────────────────────────────────────────────────
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# PROJECT_ROOT is the plugin directory (novamira-adrianv2/)
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
SOURCE_DIR="$PROJECT_ROOT"
TARGET_DIR="/c/Users/adini/Local Sites/solar/app/public/wp-content/plugins/novamira-adrianv2"
MARKER_FILE="$SOURCE_DIR/.deploy-marker"

# ── CLI Flags ──────────────────────────────────────────────────────────────────
DRY_RUN=false
FORCE=false

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --force)   FORCE=true ;;
        --help|-h)
            echo "deploy-plugin.sh — Deploy changed plugin files to solar.local"
            echo ""
            echo "Usage:"
            echo "  ./scripts/deploy-plugin.sh              # Incremental deploy (files newer than marker)"
            echo "  ./scripts/deploy-plugin.sh --dry-run    # Preview what would be copied"
            echo "  ./scripts/deploy-plugin.sh --force       # Copy all files regardless of timestamp"
            echo "  ./scripts/deploy-plugin.sh --help        # This help"
            echo ""
            echo "Paths:"
            echo "  Source:      novamira-adrianv2/"
            echo "  Destination: Local Sites/solar/app/public/wp-content/plugins/novamira-adrianv2/"
            echo ""
            echo "Exit codes:"
            echo "  0 = deployment successful (or dry-run complete)"
            echo "  1 = deployment failed (target not found, copy error)"
            echo "  2 = invalid flag"
            exit 0
            ;;
        *)
            echo "Unknown flag: $arg (use --help)"
            exit 2
            ;;
    esac
done

# ── Validation ─────────────────────────────────────────────────────────────────
if [[ ! -d "$SOURCE_DIR/includes" ]]; then
    echo "ERROR: Source plugin directory not found: $SOURCE_DIR"
    echo "  Run from the project root or scripts/ directory."
    exit 1
fi

if [[ ! -d "$TARGET_DIR" ]]; then
    echo "ERROR: Target plugin directory not found: $TARGET_DIR"
    echo "  Is Local WP running? Check the solar.local site."
    exit 1
fi

# ── File List ──────────────────────────────────────────────────────────────────
# Files to deploy (relative to SOURCE_DIR). Add patterns as the plugin grows.
DEPLOY_FILES=(
    "novamira-adrianv2.php"
    "composer.json"
    "README.md"
    "CHANGELOG.md"
    "includes/bootstrap.php"
    "includes/categories.php"
    "includes/helpers/bootstrap.php"
    "includes/helpers/trait-ability-registry.php"
    "includes/helpers/trait-elementor-data-helpers.php"
    "includes/helpers/class-diagnostics.php"
    "includes/helpers/class-helpers.php"
    "includes/helpers/class-v4-props.php"
    "includes/helpers/class-v4-styles.php"
    "includes/helpers/class-v4-content-extractor.php"
    "includes/helpers/class-v4-color-contrast.php"
    "includes/helpers/class-v4-color-contrast-22.php"
    "includes/helpers/class-v4-seo-meta.php"
    "includes/helpers/class-php-sandbox-validator.php"
    "includes/helpers/class-php-sandbox-store.php"
    "includes/helpers/class-audit-helpers.php"
    "includes/abilities/a11y/bootstrap.php"
    "includes/abilities/a11y/class-a11y.php"
    "includes/abilities/atomic/bootstrap.php"
    "includes/abilities/atomic/class-atomic-layouts.php"
    "includes/abilities/atomic/class-atomic-widgets.php"
    "includes/abilities/audit/bootstrap.php"
    "includes/abilities/audit/class-class-audit.php"
    "includes/abilities/audit/class-layout-audit.php"
    "includes/abilities/audit/class-page-audit.php"
    "includes/abilities/audit/class-responsive-audit.php"
    "includes/abilities/audit/class-variable-audit.php"
    "includes/abilities/audit/class-visual-qa.php"
    "includes/abilities/custom-code/bootstrap.php"
    "includes/abilities/custom-code/class-custom-code.php"
    "includes/abilities/elementor/bootstrap.php"
    "includes/abilities/elementor/class-add-global-class-variant.php"
    "includes/abilities/elementor/class-apply-variable-to-class.php"
    "includes/abilities/elementor/class-batch-build-page.php"
    "includes/abilities/elementor/class-batch-class.php"
    "includes/abilities/elementor/class-batch-get-content.php"
    "includes/abilities/elementor/class-clone-element.php"
    "includes/abilities/elementor/class-create-component.php"
    "includes/abilities/elementor/class-detach-component.php"
    "includes/abilities/elementor/class-duplicate-page.php"
    "includes/abilities/elementor/class-edit-global-class-variant.php"
    "includes/abilities/elementor/class-edit-interaction.php"
    "includes/abilities/elementor/class-execute-build-plan.php"
    "includes/abilities/elementor/class-export-design-system.php"
    "includes/abilities/elementor/class-get-page-markdown.php"
    "includes/abilities/elementor/class-global-widgets.php"
    "includes/abilities/elementor/class-html-to-elementor-widget-plan.php"
    "includes/abilities/elementor/class-import-design-system.php"
    "includes/abilities/elementor/class-insert-component.php"
    "includes/abilities/elementor/class-kit-convert-v3-to-v4.php"
    "includes/abilities/elementor/class-list-class-variants.php"
    "includes/abilities/elementor/class-list-elementor-pages.php"
    "includes/abilities/elementor/class-list-templates.php"
    "includes/abilities/elementor/class-page-settings.php"
    "includes/abilities/elementor/class-patch-element-styles.php"
    "includes/abilities/elementor/class-remove-global-class.php"
    "includes/abilities/elementor/class-reorder-element.php"
    "includes/abilities/elementor/class-setup-v4-foundation.php"
    "includes/abilities/global-classes/bootstrap.php"
    "includes/abilities/global-classes/class-global-classes.php"
    "includes/abilities/media/bootstrap.php"
    "includes/abilities/media/class-batch-media-upload.php"
    "includes/abilities/media/class-delete-media.php"
    "includes/abilities/media/class-edit-media.php"
    "includes/abilities/media/class-featured-image.php"
    "includes/abilities/media/class-list-media.php"
    "includes/abilities/media/class-media-upload.php"
    "includes/abilities/media/class-media-usage.php"
    "includes/abilities/php-sandbox/bootstrap.php"
    "includes/abilities/php-sandbox/class-php-snippets.php"
    "includes/abilities/seo/bootstrap.php"
    "includes/abilities/seo/class-seo.php"
    "includes/abilities/utilities/bootstrap.php"
    "includes/abilities/utilities/class-hello-world.php"
    "includes/abilities/variables/bootstrap.php"
    "includes/abilities/variables/class-batch-create-variables.php"
)

# ── Copy Logic ─────────────────────────────────────────────────────────────────
COPIED=0
SKIPPED=0
ERRORS=0

echo "=== Deploy Plugin to solar.local ==="
echo "Source:      $SOURCE_DIR"
echo "Destination: $TARGET_DIR"
echo ""

if $FORCE; then
    echo "Mode: FORCE — copying all files"
elif $DRY_RUN; then
    echo "Mode: DRY-RUN — no files will be copied"
else
    echo "Mode: INCREMENTAL — only files changed since last deploy"
fi
echo ""

for rel_path in "${DEPLOY_FILES[@]}"; do
    src="$SOURCE_DIR/$rel_path"
    dst="$TARGET_DIR/$rel_path"

    # Skip if source doesn't exist (e.g., future files not yet created)
    if [[ ! -f "$src" ]]; then
        continue
    fi

    # Check if copy is needed
    needs_copy=false
    if $FORCE; then
        needs_copy=true
    elif [[ ! -f "$dst" ]]; then
        needs_copy=true
        reason="new file"
    elif [[ "$src" -nt "$dst" ]]; then
        needs_copy=true
        reason="newer source"
    elif [[ -f "$MARKER_FILE" && "$src" -nt "$MARKER_FILE" ]]; then
        needs_copy=true
        reason="changed since last deploy"
    fi

    if $needs_copy; then
        if $DRY_RUN; then
            echo "  [DRY-RUN] $rel_path ($reason)"
            ((COPIED++)) || true
        else
            # Ensure target directory exists
            mkdir -p "$(dirname "$dst")"
            if cp "$src" "$dst"; then
                echo "  ✓ $rel_path ($reason)"
                ((COPIED++)) || true
            else
                echo "  ✗ $rel_path — copy failed"
                ((ERRORS++)) || true
            fi
        fi
    else
        ((SKIPPED++)) || true
    fi
done

# ── Update marker ──────────────────────────────────────────────────────────────
if ! $DRY_RUN && [[ $ERRORS -eq 0 ]]; then
    touch "$MARKER_FILE"
fi

# ── Summary ────────────────────────────────────────────────────────────────────
echo ""
echo "=== Deployment Summary ==="
echo "  Copied:  $COPIED"
echo "  Skipped: $SKIPPED"
echo "  Errors:  $ERRORS"
echo ""

if $DRY_RUN; then
    echo "DRY-RUN complete — no files were actually copied."
elif [[ $ERRORS -gt 0 ]]; then
    echo "Deployment completed with $ERRORS error(s)."
    exit 1
else
    echo "Deployment successful."
fi
