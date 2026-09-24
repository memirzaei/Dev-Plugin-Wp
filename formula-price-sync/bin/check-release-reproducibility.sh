#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
command -v zip >/dev/null || { echo 'zip is required' >&2; exit 1; }
command -v unzip >/dev/null || { echo 'unzip is required' >&2; exit 1; }

if [[ "${CI:-false}" == "true" ]]; then
  FPS_RELEASE_REPRO_CHECK=1 FPS_TESTS_ALREADY_PASSED=1 bash bin/build-release.sh
else
fi
cp build/formula-price-sync-2.0.0.zip /tmp/fps-release-a.zip
sha_a=$(sha256sum /tmp/fps-release-a.zip | awk '{print $1}')

if [[ "${CI:-false}" == "true" ]]; then
  FPS_RELEASE_REPRO_CHECK=1 FPS_TESTS_ALREADY_PASSED=1 bash bin/build-release.sh
else
fi
cp build/formula-price-sync-2.0.0.zip /tmp/fps-release-b.zip
sha_b=$(sha256sum /tmp/fps-release-b.zip | awk '{print $1}')

printf 'build-a: %s\nbuild-b: %s\n' "$sha_a" "$sha_b"
if [[ "$sha_a" != "$sha_b" ]]; then
  echo 'Reproducibility: FAIL' >&2
  cmp -l /tmp/fps-release-a.zip /tmp/fps-release-b.zip | head -20 || true
  exit 1
fi
echo 'Reproducibility: PASS'
