#!/usr/bin/env bash
set -euo pipefail

PLUGIN_FILE="wp-mcp.php"

# --- Read current version ---
CURRENT=$(sed -n "s/.*define('WP_MCP_VERSION', '\([0-9]*\.[0-9]*\.[0-9]*\)').*/\1/p" "$PLUGIN_FILE")
IFS='.' read -r MAJOR MINOR PATCH <<< "$CURRENT"

echo ""
echo "Current version: $CURRENT"
echo ""
echo "What kind of release?"
echo "  1) patch  → $MAJOR.$MINOR.$((PATCH + 1))"
echo "  2) minor  → $MAJOR.$((MINOR + 1)).0"
echo "  3) major  → $((MAJOR + 1)).0.0"
echo ""
read -rp "Choose [1/2/3]: " CHOICE

case "$CHOICE" in
    1) NEW_VERSION="$MAJOR.$MINOR.$((PATCH + 1))" ;;
    2) NEW_VERSION="$MAJOR.$((MINOR + 1)).0" ;;
    3) NEW_VERSION="$((MAJOR + 1)).0.0" ;;
    *) echo "Invalid choice."; exit 1 ;;
esac

echo ""
echo "New version: $NEW_VERSION"
echo ""

# --- Release notes ---
read -rp "Release notes (leave empty for default): " NOTES
if [ -z "$NOTES" ]; then
    NOTES="Release v$NEW_VERSION"
fi

# --- Confirm ---
echo ""
echo "Summary:"
echo "  Version: $CURRENT → $NEW_VERSION"
echo "  Tag:     v$NEW_VERSION"
echo "  Notes:   $NOTES"
echo ""
read -rp "Proceed? [y/N]: " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo "Aborted."
    exit 0
fi

# --- Bump version in plugin file ---
sed -i '' "s/Version: $CURRENT/Version: $NEW_VERSION/" "$PLUGIN_FILE"
sed -i '' "s/define('WP_MCP_VERSION', '$CURRENT')/define('WP_MCP_VERSION', '$NEW_VERSION')/" "$PLUGIN_FILE"

echo "Updated $PLUGIN_FILE"

# --- Commit, tag, push ---
git add "$PLUGIN_FILE"
git commit -m "Bump version to $NEW_VERSION"
git push

echo "Pushed to remote"

# --- Create GitHub release (triggers the Action to build the zip) ---
gh release create "v$NEW_VERSION" \
    --title "v$NEW_VERSION" \
    --notes "$NOTES"

echo ""
echo "Release v$NEW_VERSION created!"
echo "The GitHub Action will build and attach the zip automatically."
