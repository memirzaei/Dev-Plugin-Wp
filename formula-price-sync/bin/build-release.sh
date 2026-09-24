#!/usr/bin/env bash
# ==============================================================================
# Formula Price Sync – Marketplace Release Builder
# ==============================================================================
# Produces a clean, deterministic zip ready for upload to Zhaket / Rastchin.
#
# Usage (from plugin root):
#   ./bin/build-release.sh
#
# Optional environment variables:
#   FPS_RELEASE_REPRO_CHECK=1       Mark build as a reproducibility check
#   FPS_ZHAKET_PRODUCT_TOKEN      Product token for Zhaket (NEVER written into the zip).
#                                 Define it on the target server via wp-config.php or
#                                 the server environment. This script only reports
#                                 whether the variable is present in the build shell.
#
# Exit codes:
#   0  Success
#   1  General failure (set -e)
#   2  PHPUnit failures
# ==============================================================================

set -euo pipefail

# ------------------------------------------------------------------------------
# Constants
# ------------------------------------------------------------------------------
PLUGIN_SLUG="formula-price-sync"
VERSION="2.0.0"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="${ROOT_DIR}/build"
RELEASE_DIR="${BUILD_DIR}/${PLUGIN_SLUG}"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="${BUILD_DIR}/${ZIP_NAME}"

cd "${ROOT_DIR}"

echo "--------------------------------------------------"
echo " Formula Price Sync – Release Builder"
echo " Version : ${VERSION}"
echo " Root    : ${ROOT_DIR}"
if [[ -n "${FPS_ZHAKET_PRODUCT_TOKEN:-}" ]]; then
	echo " Token   : present in build environment (will NOT be written into zip)"
else
	echo " Token   : not set in build environment"
	echo "           Define FPS_ZHAKET_PRODUCT_TOKEN on the target server"
	echo "           (wp-config.php or server env) – never inside the zip."
fi
echo "--------------------------------------------------"

# ------------------------------------------------------------------------------
# 1. Mandatory PHPUnit
# ------------------------------------------------------------------------------
echo ""
echo "[1/4] Running tests..."

if [[ "${FPS_TESTS_ALREADY_PASSED:-0}" == "1" && "${CI:-false}" == "true" ]]; then
	echo "  → CI test gate already passed upstream; packaging-only build continues."
elif [[ -x "./vendor/bin/phpunit" ]]; then
	if ./vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage; then
		echo "  → All PHPUnit tests passed."
	else
		echo "  → PHPUnit reported failures. Aborting release." >&2
		exit 2
	fi
elif command -v phpunit >/dev/null 2>&1; then
	if phpunit --configuration phpunit.xml.dist --no-coverage; then
		echo "  → All PHPUnit tests passed."
	else
		echo "  → PHPUnit reported failures. Aborting release." >&2
		exit 2
	fi
else
	echo "ERROR: PHPUnit is required for a normal release build. Run composer install first." >&2
	exit 2
fi

# ------------------------------------------------------------------------------
# 2. Clean previous build
# ------------------------------------------------------------------------------
echo ""
echo "[2/4] Cleaning previous build..."
rm -rf "${BUILD_DIR}"
mkdir -p "${RELEASE_DIR}"
echo "  → ${BUILD_DIR} recreated."

# ------------------------------------------------------------------------------
# 3. Copy production files (deterministic excludes)
# ------------------------------------------------------------------------------
echo ""
echo "[3/4] Copying production files..."

# Explicit exclude list – keep in sync with marketplace packaging rules.
# Order does not affect the final zip content; rsync is deterministic
# when source tree is unchanged.
if command -v rsync >/dev/null 2>&1; then
	rsync -a \
		--exclude='.git' \
		--exclude='.gitignore' \
		--exclude='.gitattributes' \
		--exclude='.github' \
		--exclude='.idea' \
		--exclude='.vscode' \
		--exclude='.DS_Store' \
		--exclude='Thumbs.db' \
		--exclude='node_modules' \
		--exclude='README.md' \
		--exclude='tests' \
		--exclude='bin' \
		--exclude='build' \
		--exclude='phpunit.xml' \
		--exclude='phpunit.xml.dist' \
		--exclude='phpunit.xml.dist.bak' \
		--exclude='composer.json' \
		--exclude='composer.lock' \
		--exclude='composer.phar' \
		--exclude='vendor/bin' \
		--exclude='*.log' \
		--exclude='*.swp' \
		--exclude='*.swo' \
		--exclude='*~' \
		--exclude='.phpunit.result.cache' \
		--exclude='.phpcs.xml' \
		--exclude='.phpcs.xml.dist' \
		--exclude='phpcs.xml' \
		--exclude='phpcs.xml.dist' \
		--exclude='.editorconfig' \
		--exclude='.env' \
		--exclude='.env.*' \
		--exclude='package.json' \
		--exclude='package-lock.json' \
		--exclude='yarn.lock' \
		--exclude='webpack.config.js' \
		--exclude='vite.config.js' \
		--exclude='tsconfig.json' \
		./ "${RELEASE_DIR}/"
else
	echo "  → rsync not found; using cp/find fallback."
	# Copy top-level production entries only.
	for item in CHANGELOG.md formula-price-sync.php index.php readme.txt uninstall.php assets includes languages vendor; do
		if [[ -e "./${item}" ]]; then
			cp -a "./${item}" "${RELEASE_DIR}/"
		fi
	done
	rm -rf "${RELEASE_DIR}/vendor/bin" 2>/dev/null || true
	rm -f "${RELEASE_DIR}/composer.json" "${RELEASE_DIR}/composer.lock" 2>/dev/null || true
fi

echo "  → Production tree copied to ${RELEASE_DIR}"

# Safety: ensure no accidental development leftovers inside the release tree.
if [[ -d "${RELEASE_DIR}/tests" ]] || [[ -d "${RELEASE_DIR}/bin" ]] || [[ -f "${RELEASE_DIR}/phpunit.xml.dist" ]]; then
	echo "ERROR: Development artefacts leaked into release directory." >&2
	exit 1
fi

# ------------------------------------------------------------------------------
# 4. Create deterministic zip
# ------------------------------------------------------------------------------
echo ""
echo "[4/4] Creating release zip..."

# Normalize metadata that would otherwise make two builds differ because source
# filesystem mtimes differ. File order is sorted before zip reads the manifest.
# Directory entries are omitted; extraction recreates directories from files.
find "${RELEASE_DIR}" -type f -exec touch -h -d '2000-01-01 00:00:00 UTC' {} +
find "${RELEASE_DIR}" -type d -exec touch -h -d '2000-01-01 00:00:00 UTC' {} +

cd "${BUILD_DIR}"
rm -f "${ZIP_PATH}"
find "${PLUGIN_SLUG}" -type f -print | LC_ALL=C sort | zip -X -D -q "${ZIP_NAME}" -@

# Basic integrity check
if [[ ! -f "${ZIP_PATH}" ]]; then
	echo "ERROR: Zip file was not created." >&2
	exit 1
fi

unzip -t -q "${ZIP_PATH}"
ZIP_LISTING=$(mktemp)
unzip -l "${ZIP_PATH}" > "${ZIP_LISTING}"
grep -q "${PLUGIN_SLUG}/vendor/composer/platform_check.php" "${ZIP_LISTING}"
rm -f "${ZIP_LISTING}"
TMP_CHECK=$(mktemp -d)
unzip -q "${ZIP_PATH}" -d "${TMP_CHECK}"
php -r 'require $argv[1]; echo "AUTOLOAD_OK\n";' "${TMP_CHECK}/${PLUGIN_SLUG}/vendor/autoload.php"
rm -rf "${TMP_CHECK}"

ZIP_SIZE=$(du -h "${ZIP_PATH}" | cut -f1)

echo ""
echo "--------------------------------------------------"
echo " Release package ready"
echo "--------------------------------------------------"
echo " File     : ${ZIP_PATH}"
echo " Size     : ${ZIP_SIZE}"
echo " Version  : ${VERSION}"
echo " Contents : ${PLUGIN_SLUG}/ (production files only)"
echo "--------------------------------------------------"
echo ""
echo "Upload this file to Zhaket / Rastchin marketplace."
echo ""

# Optional: print a short tree of the release for verification
echo "Top-level contents of the package:"
unzip -l "${ZIP_PATH}" | sed -n '1,40p'
echo "..."
echo "(full listing available via: unzip -l ${ZIP_PATH})"
echo ""

exit 0
