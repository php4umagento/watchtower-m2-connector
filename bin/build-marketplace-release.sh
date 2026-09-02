#!/usr/bin/env bash
#
# Copyright © 2026 Watchtower. All rights reserved.
# Licensed under the Business Source License 1.1 (BUSL-1.1). See LICENSE.
#
# Builds an Adobe Commerce Marketplace submission package from an already-
# tagged release of this repo (see CLAUDE.md's "Versioning, changelog, and
# release process" for how a tag gets cut -- this script does not tag
# anything itself, it only packages a tag that already exists).
#
# Two phases:
#   1. Verification -- rsyncs the FULL tagged tree (including Test/, which
#      the shipped package deliberately excludes) into the `m2` project's
#      vendor path and runs PHPCS, PHPStan, and PHPUnit there, the same way
#      CLAUDE.md's normal dev loop does. A failure here aborts before any
#      package is produced.
#   2. Packaging -- exports the tag via `git archive`, which already
#      respects .gitattributes' export-ignore (/Test, /.gitattributes,
#      /.gitignore; CLAUDE.md is untracked so it is never in the tag at
#      all), stamps a "version" field into composer.json (deliberately
#      absent from source control -- see composer.json's own comment in
#      CLAUDE.md -- but required by Marketplace, so it is added to this
#      export only, never committed), runs the structural checks below,
#      and zips the result.
#
# Requirements below are sourced from Adobe's own current developer docs
# (developer.adobe.com/commerce/marketplace/guides/sellers/*,
# developer.adobe.com/commerce/php/development/package/component) as of
# this script's writing. What this script CANNOT do, because it needs an
# authenticated Marketplace seller account this environment does not have:
#   - The actual Extension Quality Program coding-standard scan (a
#     credentialed `composer create-project --repository=https://repo.
#     magento.com magento/marketplace-eqp` package) -- PHPCS here runs this
#     repo's own Magento2-standard ruleset instead, which is a close but
#     not identical proxy.
#   - Malware/virus scanning, plagiarism (copy-paste) detection, and the
#     MFTF functional-test run Marketplace performs server-side.
#   - Marketing review (listing copy, screenshots, documentation) -- that
#     is a human review of what you upload alongside this zip, not
#     something a package can pass or fail on its own.
# Budget for a first submission to still come back with review feedback;
# this script only catches what is mechanically checkable beforehand.
#
# Usage:
#   bin/build-marketplace-release.sh [tag] [--skip-tests]
#
#   tag           Git tag to package, e.g. v1.30.0. Defaults to the
#                 highest existing tag (by version sort, not by date).
#   --skip-tests  Skip phase 1 (verification). The package still gets the
#                 same structural checks in phase 2. Use this only when
#                 phase 1 was already run for this exact tag and nothing
#                 has changed since.

set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

VENDOR="php4u"
PACKAGE_NAME="module-watchtower-m2-connector"
COMPOSER_NAME="php4u/module-watchtower-m2-connector"
MAX_PACKAGE_BYTES=$((30 * 1024 * 1024)) # Marketplace's documented 30MB cap

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$REPO_ROOT/build"

# Separate Warden project (own containers, own network) -- CLAUDE.md warns
# `cd` state does not reliably persist between tool calls in some
# environments, so every command below addresses the container directly
# rather than relying on a prior `cd`. Override via env var if these
# container names ever drift (`docker ps` to check).
M2_PROJECT_ROOT="${M2_PROJECT_ROOT:-/Users/marcins/Projects/m2}"
M2_PHP_CONTAINER="${M2_PHP_CONTAINER:-m2-php-fpm-1}"
M2_VENDOR_TARGET="$M2_PROJECT_ROOT/vendor/php4u/module-watchtower-m2-connector"

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

SKIP_TESTS=false
TAG=""

for arg in "$@"; do
    case "$arg" in
        --skip-tests)
            SKIP_TESTS=true
            ;;
        -h|--help)
            sed -n '1,40p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        -*)
            echo "Unknown flag: $arg" >&2
            exit 1
            ;;
        *)
            if [ -n "$TAG" ]; then
                echo "Only one tag may be given (got '$TAG' and '$arg')" >&2
                exit 1
            fi
            TAG="$arg"
            ;;
    esac
done

cd "$REPO_ROOT"

if [ -z "$TAG" ]; then
    TAG="$(git tag -l 'v*' | sed 's/^v//' | sort -t. -k1,1n -k2,2n -k3,3n | tail -1)"
    TAG="v$TAG"
    echo "No tag given, using highest existing tag: $TAG"
fi

if ! git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
    echo "Tag '$TAG' does not exist in this repo. Cut it first (see CLAUDE.md's release process)." >&2
    exit 1
fi

VERSION="${TAG#v}"
if [ "$VERSION" = "$TAG" ]; then
    echo "Tag '$TAG' does not start with 'v' -- expected e.g. v1.30.0." >&2
    exit 1
fi

echo "Packaging $COMPOSER_NAME $VERSION (tag $TAG)"

# ---------------------------------------------------------------------------
# Phase 1: verification against the FULL tagged tree (tests included)
# ---------------------------------------------------------------------------

if [ "$SKIP_TESTS" = true ]; then
    echo
    echo "== Phase 1: verification -- SKIPPED (--skip-tests) =="
else
    echo
    echo "== Phase 1: verification =="

    if [ ! -d "$M2_VENDOR_TARGET" ]; then
        echo "Expected rsync target not found: $M2_VENDOR_TARGET" >&2
        echo "Is the m2 project set up per its own CLAUDE.md?" >&2
        exit 1
    fi

    VERIFY_WORKTREE="$(mktemp -u)"
    cleanup_worktree() {
        git worktree remove --force "$VERIFY_WORKTREE" 2>/dev/null || rm -rf "$VERIFY_WORKTREE"
    }
    trap cleanup_worktree EXIT

    # A real worktree checkout, NOT `git archive` -- .gitattributes'
    # export-ignore (which is exactly what strips /Test from the shipped
    # package in phase 2) applies unconditionally to `git archive`, with no
    # flag to opt out of it. A worktree checkout is a normal tracked-files
    # checkout and is not filtered by export-ignore at all, which is
    # exactly what phase 1 needs: Test/ present so PHPUnit has something to
    # run.
    git worktree add --detach "$VERIFY_WORKTREE" "$TAG" >/dev/null

    echo "Syncing tagged tree into $M2_VENDOR_TARGET for testing..."
    rsync -a --delete --exclude='.git' "$VERIFY_WORKTREE/" "$M2_VENDOR_TARGET/"

    echo "Running PHPCS (Magento2 standard)..."
    docker exec "$M2_PHP_CONTAINER" bash -c "cd /var/www/html && composer watchtower-phpcs"

    echo "Running PHPStan (level 5)..."
    docker exec "$M2_PHP_CONTAINER" bash -c "cd /var/www/html && composer watchtower-phpstan"

    echo "Running PHPUnit..."
    set +e
    UNIT_OUTPUT="$(docker exec "$M2_PHP_CONTAINER" bash -c "cd /var/www/html && vendor/bin/phpunit -c dev/tests/unit --testsuite 'Magento_Unit_Tests_Other' --filter Watchtower" 2>&1)"
    set -e
    echo "$UNIT_OUTPUT"
    # NOT a bare exit-code check: this environment's PHPUnit run always
    # exits non-zero even when every test passes, because of environment
    # noise unrelated to this module's own code (an unconfigured Allure
    # extension, and old-style version-requirement annotations inside
    # Magento core's own TokenizerTest) -- PHPUnit's "OK, but there were
    # issues!" summary for that is a genuinely different, lower-severity
    # status than "FAILURES!"/"ERRORS!", so those two strings are what
    # actually gate this, not $?.
    if grep -qE 'FAILURES!|ERRORS!' <<<"$UNIT_OUTPUT"; then
        echo "PHPUnit reported real test failures. Aborting." >&2
        exit 1
    fi
    # Guard against the exact silent-zero-tests failure mode CLAUDE.md warns
    # about: wrong testsuite matches nothing, exits 0, and looks like a pass.
    if ! grep -qE 'OK|Tests: [1-9]' <<<"$UNIT_OUTPUT"; then
        echo "PHPUnit reported no matching tests -- that is a filter/testsuite bug, not a pass. Aborting." >&2
        exit 1
    fi

    cleanup_worktree
    trap - EXIT
    echo "Phase 1 passed."
fi

# ---------------------------------------------------------------------------
# Phase 2: build the Marketplace package
# ---------------------------------------------------------------------------

echo
echo "== Phase 2: packaging =="

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"

EXPORT_FOLDER="$PACKAGE_NAME-$VERSION"
EXPORT_DIR="$BUILD_DIR/$EXPORT_FOLDER"

# Respects .gitattributes export-ignore: drops /Test, /.gitattributes,
# /.gitignore automatically. CLAUDE.md is untracked (in .gitignore) so it
# was never part of the tag in the first place.
mkdir -p "$EXPORT_DIR"
git archive "$TAG" | tar -x -C "$EXPORT_DIR"

echo "Exported $(find "$EXPORT_DIR" -type f | wc -l | tr -d ' ') files to $EXPORT_DIR"

# --- Stamp the Marketplace-required "version" field --------------------
#
# composer.json deliberately ships with no hardcoded version (Composer's
# VCS-repository support resolves it from the git tag instead -- see
# composer.json's own note in CLAUDE.md). Marketplace's own composer.json
# validation wants "version" present regardless, so it is added here, to
# this export only. Never commit this back to the repo.
if ! command -v jq >/dev/null 2>&1; then
    echo "jq is required (brew install jq)" >&2
    exit 1
fi
jq --arg v "$VERSION" '. + {version: $v}' "$EXPORT_DIR/composer.json" > "$EXPORT_DIR/composer.json.tmp"
mv "$EXPORT_DIR/composer.json.tmp" "$EXPORT_DIR/composer.json"

# --- Structural checks ---------------------------------------------------
#
# Everything below is a LOCAL proxy for Marketplace's own "Package
# Verification" automated check, built from the composer.json rules
# documented at developer.adobe.com/commerce/marketplace/guides/sellers/
# technical-review-guidelines and .../commerce/php/development/package/
# component. It is not the actual EQP tool (see the header comment).

FAILURES=0
fail() {
    echo "FAIL: $1" >&2
    FAILURES=$((FAILURES + 1))
}

COMPOSER_JSON="$EXPORT_DIR/composer.json"

[ "$(jq -r '.name // empty' "$COMPOSER_JSON")" = "$COMPOSER_NAME" ] \
    || fail "composer.json 'name' must be $COMPOSER_NAME"

[ "$(jq -r '.type // empty' "$COMPOSER_JSON")" = "magento2-module" ] \
    || fail "composer.json 'type' must be magento2-module"

[ "$(jq -r '.version // empty' "$COMPOSER_JSON")" = "$VERSION" ] \
    || fail "composer.json 'version' was not stamped correctly"

jq -e '.extra.map == null and .extra["magento-root-dir"] == null' "$COMPOSER_JSON" >/dev/null \
    || fail "composer.json must not declare extra.map or extra.magento-root-dir"

for forbidden in magento/magento-composer-installer magento/magento2-base \
                 magento/product-community-edition magento/magento2-ee-base \
                 magento/product-enterprise-edition; do
    jq -e --arg pkg "$forbidden" '.require[$pkg] == null' "$COMPOSER_JSON" >/dev/null \
        || fail "composer.json must not require $forbidden"
done

# A bare "*" on a magento/* package is disallowed; a scoped wildcard like
# "103.0.*" is the normal, expected pattern (matches Magento core's own
# modules) and is NOT what this checks for.
while IFS=$'\t' read -r pkg constraint; do
    [ "$constraint" = "*" ] && fail "composer.json requires $pkg with an unrestricted '*' version"
done < <(jq -r '.require | to_entries[] | select(.key | startswith("magento/")) | [.key, .value] | @tsv' "$COMPOSER_JSON")

jq -e '.autoload.files // [] | index("registration.php") != null' "$COMPOSER_JSON" >/dev/null \
    || fail "composer.json autoload.files must include registration.php"

jq -e '(.autoload["psr-4"] // {}) | length > 0' "$COMPOSER_JSON" >/dev/null \
    || fail "composer.json autoload.psr-4 must declare at least one namespace"

[ -f "$EXPORT_DIR/registration.php" ] || fail "registration.php is missing from the export"
[ -f "$EXPORT_DIR/etc/module.xml" ] || fail "etc/module.xml is missing from the export"

if [ -f "$EXPORT_DIR/etc/module.xml" ]; then
    python3 -c "import xml.dom.minidom as m; m.parse('$EXPORT_DIR/etc/module.xml')" \
        || fail "etc/module.xml is not well-formed XML"
fi

# "FIXME/TODO-style comments indicate incomplete development" per the
# technical review guidelines.
TODO_HITS="$(grep -rlnE '(FIXME|TODO)\b' "$EXPORT_DIR" --include='*.php' --include='*.phtml' --include='*.xml' 2>/dev/null || true)"
if [ -n "$TODO_HITS" ]; then
    fail "FIXME/TODO markers found in shipped files (Marketplace rejects these):"
    echo "$TODO_HITS" | sed 's/^/  /' >&2
fi

if [ "$FAILURES" -gt 0 ]; then
    echo
    echo "$FAILURES structural check(s) failed. No zip was produced." >&2
    exit 1
fi

echo "All structural checks passed."

# --- Zip -------------------------------------------------------------------
#
# Naming and nesting per developer.adobe.com/commerce/php/development/
# package/component's own example: `zip -r vendor-name_package-name-
# version.zip package-path/` -- contents live inside a named folder, not
# at the zip root.
ZIP_NAME="${VENDOR}_${PACKAGE_NAME}-${VERSION}.zip"
ZIP_PATH="$BUILD_DIR/$ZIP_NAME"

(
    cd "$BUILD_DIR"
    zip -rq "$ZIP_NAME" "$EXPORT_FOLDER" -x '*.DS_Store'
)

ZIP_BYTES="$(stat -f%z "$ZIP_PATH" 2>/dev/null || stat -c%s "$ZIP_PATH")"
ZIP_MB="$(echo "scale=1; $ZIP_BYTES / 1048576" | bc)"

echo
echo "== Done =="
echo "Package: $ZIP_PATH (${ZIP_MB} MB)"

if [ "$ZIP_BYTES" -gt "$MAX_PACKAGE_BYTES" ]; then
    echo "OVER Marketplace's 30MB limit -- this will be rejected on upload." >&2
    exit 1
fi

cat <<EOF

Still manual before you upload this zip:
  - Marketplace account + listing created at commercemarketplace.adobe.com
  - Product title (max 5 words, no version/vendor/"Extension"/"Module")
  - Long description, feature list, at least 2 screenshots (PNG/JPG, max 5MB each)
  - An installation/user guide document (PDF)
  - Release notes (plain text)
See CLAUDE.md's own release-process notes for what this repo tracks locally
for each of those.
EOF
