#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
EXPECTED_VERSION="2.0.0"
errors=0
check(){
  local name="$1"; shift
  if "$@"; then echo "[PASS] $name"; else echo "[FAIL] $name"; errors=$((errors+1)); fi
}
check_not_contains(){
  local name="$1"; shift
  if grep -nHiE "$1" "${@:2}" >/dev/null 2>&1; then
    echo "[FAIL] $name"; errors=$((errors+1));
  else
    echo "[PASS] $name";
  fi
}
check 'plugin header version' grep -qE "^ \* Version:[[:space:]]+${EXPECTED_VERSION}[[:space:]]*$" formula-price-sync.php
check 'FPS_VERSION constant' grep -q "define( 'FPS_VERSION', '${EXPECTED_VERSION}'" formula-price-sync.php
check 'WordPress stable tag' grep -q "^Stable tag: ${EXPECTED_VERSION}$" readme.txt
check 'README version' grep -q "v${EXPECTED_VERSION}" README.md
check 'changelog version heading' grep -q "^# Formula Price Sync ${EXPECTED_VERSION}" CHANGELOG.md
check 'build version' grep -q "^VERSION=\"${EXPECTED_VERSION}\"$" bin/build-release.sh
check 'PHP minimum consistency' grep -q "^Requires PHP: 7.4$" readme.txt && grep -q '"php": ">=7.4"' composer.json
DOC_FILES=(README.md readme.txt CHANGELOG.md .github/workflows/ci.yml bin/build-release.sh bin/run-smoke-local.sh)
check_not_contains 'no release skip instruction' 'FPS_SKIP_TESTS=1|Skip PHPUnit|Optional PHPUnit' "${DOC_FILES[@]}"
check_not_contains 'no stale stable tag' 'Stable tag: 1\.0\.0|Stable tag 1\.0\.0|Stable tag 1\.1\.1' "${DOC_FILES[@]}"
check_not_contains 'no engineering candidate bypass' 'FPS_ENGINEERING_CANDIDATE' bin/build-release.sh bin/check-release-reproducibility.sh .github/workflows/ci.yml
if (( errors )); then echo "Metadata consistency: ${errors} failure(s)" >&2; exit 1; fi
echo 'Metadata consistency: PASS'
