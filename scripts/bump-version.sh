#!/usr/bin/env bash
#
# bump-version.sh — update the app version everywhere it is duplicated.
#
# Usage:
#   scripts/bump-version.sh 1.9.0 [ASSET_STAMP]
#
#   ASSET_STAMP (optional) overrides the cache-busting token used for the
#   ?v= query strings and the i18n loader. Defaults to "<YYYYMMDD>a".
#
# Touches:
#   index.html   – header badge, preloader badge, every ?v= query
#   manifest.json – "version"
#   sw.js        – CACHE name
#   assets/js/app.js – _I18N_VERSION (translation cache buster)
#   README.md    – version badge (the "What's new" line below it stays manual)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

VERSION="${1:-}"
STAMP="${2:-}"

if [[ -z "$VERSION" ]]; then
    echo "Usage: $0 <major.minor.patch> [asset-stamp]" >&2
    exit 1
fi
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Error: version must look like 1.2.3 (got '$VERSION')" >&2
    exit 1
fi

INDEX="$ROOT/index.html"
MANIFEST="$ROOT/manifest.json"
SW="$ROOT/sw.js"
APPJS="$ROOT/assets/js/app.js"
README="$ROOT/README.md"

for f in "$INDEX" "$MANIFEST" "$SW" "$APPJS" "$README"; do
    [[ -f "$f" ]] || { echo "Error: missing $f" >&2; exit 1; }
done

# ── Asset stamp ─────────────────────────────────────────────────────────────
# Two bumps in the same day used to reuse "<today>a" verbatim, so a CSS-only
# change kept the same ?v= and the browser served the stale file. Default is now
# "next letter after the stamp already in index.html" (20261014a → 20261014b).
if [[ -z "$STAMP" ]]; then
    TODAY="$(date +%Y%m%d)"
    CURRENT_STAMP="$(grep -oE '\?v=[0-9]{8}[a-z]' "$INDEX" | head -1 | sed 's/?v=//' || true)"
    if [[ "$CURRENT_STAMP" == "$TODAY"* && "$CURRENT_STAMP" =~ ^[0-9]{8}[a-z]$ ]]; then
        LAST_LETTER="${CURRENT_STAMP: -1}"
        if [[ "$LAST_LETTER" == "z" ]]; then
            echo "Error: asset stamp for $TODAY already reached …z — pass one explicitly" >&2
            exit 1
        fi
        NEXT_LETTER="$(printf "\\$(printf '%03o' $(( $(printf '%d' "'${LAST_LETTER}") + 1 )))")"
        STAMP="${TODAY}${NEXT_LETTER}"
    else
        STAMP="${TODAY}a"
    fi
fi

if ! [[ "$STAMP" =~ ^[0-9]{8}[a-z]$ ]]; then
    echo "Error: asset stamp must look like 20261004a (got '$STAMP')" >&2
    exit 1
fi

# The cache name carries the stamp letter too: a style-only release still has to
# invalidate the service-worker cache.
STAMP_LETTER="${STAMP: -1}"

# ── index.html: badges + cache-busting query strings ────────────────────────
sed -i -E "s/(header-version\">v)[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/" "$INDEX"
sed -i -E "s/(preloader-version\">v)[0-9]+\.[0-9]+\.[0-9]+/\1${VERSION}/" "$INDEX"
sed -i -E "s/(\?v=)[0-9]{8}[a-z]/\1${STAMP}/g" "$INDEX"

# ── manifest.json: version field ────────────────────────────────────────────
sed -i -E "s/(\"version\"[[:space:]]*:[[:space:]]*\")[0-9]+\.[0-9]+\.[0-9]+(\")/\1${VERSION}\2/" "$MANIFEST"

# ── sw.js: preserve staging isolation AND update cache to new version ───────
# Legacy cache: evershelf-v1.16.16a; isolated staging cache:
# evershelf-v1.16.16-stability-20261101g. Both MUST be bumped.
grep -qE "const CACHE = 'evershelf-v[0-9]+\.[0-9]+\.[0-9]+" "$SW" || {
    echo "Error: service-worker cache name not recognized" >&2
    exit 1
}
if grep -qE "const CACHE = 'evershelf-v[0-9]+\.[0-9]+\.[0-9]+-stability-" "$SW"; then
    NEW_CACHE="evershelf-v${VERSION}-stability-${STAMP}"
else
    NEW_CACHE="evershelf-v${VERSION}${STAMP_LETTER}"
fi
sed -i -E "s/(const CACHE = ')[^']+(')/\1${NEW_CACHE}\2/" "$SW"
grep -qF "const CACHE = '${NEW_CACHE}';" "$SW" || {
    echo "Error: failed to bump service-worker cache" >&2
    exit 1
}

# ── app.js: i18n cache buster + asset query strings ─────────────────────────
sed -i -E "s/(_I18N_VERSION = ')[0-9]{8}[a-z](')/\1${STAMP}\2/" "$APPJS"
sed -i -E "s/(\?v=)[0-9]{8}[a-z]/\1${STAMP}/g" "$APPJS"

# ── README.md: shields.io version badge (drifted to 1.11.6 once already) ────
sed -i -E "s|(badge/version-)[0-9]+\.[0-9]+\.[0-9]+|\1${VERSION}|" "$README"

echo "Bumped version to v${VERSION} (asset stamp ${STAMP}) in:"
echo "  - index.html (2 badges + ?v= queries)"
echo "  - manifest.json"
echo "  - sw.js (CACHE)"
echo "  - assets/js/app.js (_I18N_VERSION)"
echo "  - README.md (version badge)"
echo
echo "Remember to add a CHANGELOG.md entry for v${VERSION} and to prepend the"
echo "new release to the README \"What's new\" line (prose stays manual)."
