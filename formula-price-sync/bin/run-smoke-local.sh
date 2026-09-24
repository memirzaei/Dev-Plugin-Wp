#!/usr/bin/env bash
# ==============================================================================
# Formula Price Sync – Local Smoke / Integration Test Automation
# ==============================================================================
# Runs operational checks against a real WordPress + WooCommerce install
# using WP-CLI. Designed for Local, Laragon, Docker, or any WP-CLI site.
#
# Usage (from ANY directory):
#   ./bin/run-smoke-local.sh
#   ./bin/run-smoke-local.sh --path=/var/www/fps.local
#   ./bin/run-smoke-local.sh --skip-phpunit --skip-sync
#
# Environment:
#   WP_PATH              Path to WordPress root (or use --path=)
#   FPS_SKIP_PHPUNIT=1   Skip plugin-internal PHP smoke runners
#   FPS_SKIP_SYNC=1      Skip Action Scheduler / sync probes
#   FPS_ALLOW_ROOT=1     Pass --allow-root to WP-CLI
#
# Exit codes:
#   0  All mandatory checks passed
#   1  One or more mandatory checks failed
#   2  Prerequisites missing (WP-CLI / site not found)
# ==============================================================================

set -euo pipefail

# ------------------------------------------------------------------------------
# Args & defaults
# ------------------------------------------------------------------------------
WP_PATH="${WP_PATH:-}"
SKIP_PHPUNIT="${FPS_SKIP_PHPUNIT:-0}"
SKIP_SYNC="${FPS_SKIP_SYNC:-0}"
ALLOW_ROOT="${FPS_ALLOW_ROOT:-0}"
PLUGIN_SLUG="formula-price-sync"

for arg in "$@"; do
	case "$arg" in
		--path=*)
			WP_PATH="${arg#--path=}"
			;;
		--skip-phpunit)
			SKIP_PHPUNIT=1
			;;
		--skip-sync)
			SKIP_SYNC=1
			;;
		--allow-root)
			ALLOW_ROOT=1
			;;
		-h|--help)
			sed -n '2,25p' "$0"
			exit 0
			;;
	esac
done

# ------------------------------------------------------------------------------
# Helpers
# ------------------------------------------------------------------------------
PASS=0
FAIL=0
WARN=0
REPORT=()

green() { printf '\033[0;32m%s\033[0m\n' "$*"; }
red()   { printf '\033[0;31m%s\033[0m\n' "$*"; }
yellow(){ printf '\033[0;33m%s\033[0m\n' "$*"; }
blue()  { printf '\033[0;34m%s\033[0m\n' "$*"; }

assert_pass() {
	local msg="$1"
	green "[PASS] ${msg}"
	REPORT+=("PASS|${msg}")
	PASS=$((PASS + 1))
}

assert_fail() {
	local msg="$1"
	red "[FAIL] ${msg}"
	REPORT+=("FAIL|${msg}")
	FAIL=$((FAIL + 1))
}

assert_warn() {
	local msg="$1"
	yellow "[WARN] ${msg}"
	REPORT+=("WARN|${msg}")
	WARN=$((WARN + 1))
}

# Resolve WP-CLI
WP_BIN=""
if command -v wp >/dev/null 2>&1; then
	WP_BIN="wp"
elif [[ -x "/home/workdir/bin/wp" ]]; then
	WP_BIN="php /home/workdir/bin/wp"
elif command -v php >/dev/null 2>&1 && [[ -f "./wp-cli.phar" ]]; then
	WP_BIN="php ./wp-cli.phar"
else
	red "WP-CLI not found. Install: https://wp-cli.org/"
	exit 2
fi

WP_ARGS=()
if [[ -n "${WP_PATH}" ]]; then
	WP_ARGS+=(--path="${WP_PATH}")
fi
if [[ "${ALLOW_ROOT}" == "1" ]] || [[ "$(id -u)" == "0" ]]; then
	WP_ARGS+=(--allow-root)
fi

wp_cmd() {
	# shellcheck disable=SC2086
	${WP_BIN} "${WP_ARGS[@]}" "$@"
}

echo "=============================================================="
echo " Formula Price Sync – Local Smoke Automation"
echo "=============================================================="
echo " WP-CLI  : ${WP_BIN}"
echo " WP_PATH : ${WP_PATH:-'(current / default)'}"
echo " Time    : $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "=============================================================="
echo ""

# ------------------------------------------------------------------------------
# 1. Prerequisites
# ------------------------------------------------------------------------------
blue "[1/8] Prerequisites"

if ! wp_cmd core is-installed >/dev/null 2>&1; then
	assert_fail "WordPress is not installed at the given path"
	echo ""
	red "STATUS: ABORT – cannot continue without WordPress"
	exit 2
fi
assert_pass "WordPress is installed"

WP_VER="$(wp_cmd core version 2>/dev/null || echo 'unknown')"
assert_pass "WordPress version: ${WP_VER}"

if wp_cmd plugin is-active woocommerce >/dev/null 2>&1; then
	WC_VER="$(wp_cmd plugin get woocommerce --field=version 2>/dev/null || echo 'unknown')"
	assert_pass "WooCommerce is active (v${WC_VER})"
else
	assert_fail "WooCommerce is not active"
fi

if wp_cmd plugin is-active "${PLUGIN_SLUG}" >/dev/null 2>&1; then
	assert_pass "formula-price-sync is active"
else
	# Try to detect installed but inactive
	if wp_cmd plugin is-installed "${PLUGIN_SLUG}" >/dev/null 2>&1; then
		assert_warn "formula-price-sync is installed but NOT active – attempting activate"
		if wp_cmd plugin activate "${PLUGIN_SLUG}" >/dev/null 2>&1; then
			assert_pass "formula-price-sync activated successfully"
		else
			assert_fail "Could not activate formula-price-sync"
		fi
	else
		assert_fail "formula-price-sync is not installed"
	fi
fi

# ------------------------------------------------------------------------------
# 2. HPOS
# ------------------------------------------------------------------------------
echo ""
blue "[2/8] HPOS (High-Performance Order Storage)"

HPOS_ENABLED="$(wp_cmd option get woocommerce_custom_orders_table_enabled 2>/dev/null || echo 'no')"
FEATURE_HPOS="$(wp_cmd option get woocommerce_feature_custom_order_tables_enabled 2>/dev/null || echo '')"

if [[ "${HPOS_ENABLED}" == "yes" ]] || [[ "${FEATURE_HPOS}" == "yes" ]]; then
	assert_pass "HPOS appears enabled (custom_orders_table=${HPOS_ENABLED})"
else
	assert_warn "HPOS not detected as enabled – enable in WooCommerce → Settings → Advanced → Features"
fi

# Plugin declares compatibility at runtime; probe via eval if class exists
HPOS_DECL="$(wp_cmd eval '
if ( class_exists( "Automattic\\WooCommerce\\Utilities\\FeaturesUtil" ) ) {
  echo "FeaturesUtil_OK";
} else {
  echo "FeaturesUtil_MISSING";
}
' 2>/dev/null || echo 'eval_failed')"

if [[ "${HPOS_DECL}" == "FeaturesUtil_OK" ]]; then
	assert_pass "WooCommerce FeaturesUtil class is available"
else
	assert_warn "FeaturesUtil not available (older WC?) – HPOS declaration cannot be verified at runtime"
fi

# ------------------------------------------------------------------------------
# 3. DB schema / options after activation
# ------------------------------------------------------------------------------
echo ""
blue "[3/8] Schema & options"

DB_VER="$(wp_cmd option get fps_db_version 2>/dev/null || echo '')"
if [[ "${DB_VER}" == "2.0.0" ]]; then
	assert_pass "fps_db_version = 2.0.0"
elif [[ -n "${DB_VER}" ]]; then
	assert_fail "fps_db_version is '${DB_VER}' (expected 2.0.0)"
else
	assert_fail "fps_db_version option missing – activation/maybe_upgrade may not have run"
fi

LOCK_FLAG="$(wp_cmd option get fps_lock_meta_migrated 2>/dev/null || echo '')"
if [[ -n "${LOCK_FLAG}" ]]; then
	assert_pass "fps_lock_meta_migrated is set (${LOCK_FLAG})"
else
	assert_warn "fps_lock_meta_migrated not set – may be fine on fresh install with no products"
fi

PREFIX="$(wp_cmd db prefix 2>/dev/null || echo 'wp_')"
TABLE_OK="$(wp_cmd db query "SHOW TABLES LIKE '${PREFIX}fps_price_logs'" 2>/dev/null | tr -d '\r' || true)"
if echo "${TABLE_OK}" | grep -q "fps_price_logs"; then
	assert_pass "Table ${PREFIX}fps_price_logs exists"
else
	assert_fail "Table ${PREFIX}fps_price_logs not found"
fi

# ------------------------------------------------------------------------------
# 4. License fail-closed & token status
# ------------------------------------------------------------------------------
echo ""
blue "[4/8] Licensing"

LICENSE_STATUS="$(wp_cmd option get fps_license_status 2>/dev/null || echo 'invalid')"
BLOCKED="$(wp_cmd eval 'echo \FormulaPriceSync\Licensing\Zhaket_Guard::should_block() ? "1" : "0";' 2>/dev/null || echo 'err')"

if [[ "${BLOCKED}" == "1" ]]; then
	assert_pass "License fail-closed active (should_block=true) – status=${LICENSE_STATUS}"
elif [[ "${BLOCKED}" == "0" ]]; then
	assert_pass "License currently valid (should_block=false) – status=${LICENSE_STATUS}"
else
	assert_fail "Could not evaluate Zhaket_Guard::should_block()"
fi

TOKEN_READY="$(wp_cmd eval 'echo \FormulaPriceSync\Licensing\Zhaket_Adapter::is_product_token_configured() ? "1" : "0";' 2>/dev/null || echo 'err')"
if [[ "${TOKEN_READY}" == "1" ]]; then
	assert_pass "Product token is configured (value never printed)"
elif [[ "${TOKEN_READY}" == "0" ]]; then
	assert_warn "Product token NOT configured – set FPS_ZHAKET_PRODUCT_TOKEN in wp-config.php"
else
	assert_fail "Could not evaluate product token status"
fi

# ------------------------------------------------------------------------------
# 5. Calculator engine
# ------------------------------------------------------------------------------
echo ""
blue "[5/8] Calculator engine"

CALC_TAX="$(wp_cmd eval '
\$r = \FormulaPriceSync\Engine\Calculator::calculate_price(array(
  "source_type" => "gold_18k",
  "weight" => 10.0,
  "wage_percent" => 7.0,
  "profit_percent" => 10.0,
  "tax_percent" => 9.0,
  "fixed_fee" => 0.0,
  "rounding_rule" => "none",
), 5000000.0);
echo isset(\$r["breakdown"]["tax_amount"]) ? (string) round(\$r["breakdown"]["tax_amount"]) : "missing";
' 2>/dev/null || echo 'err')"

if [[ "${CALC_TAX}" == "796500" ]]; then
	assert_pass "Gold 18k tax rule correct (tax_amount=796500 on wage+profit only)"
else
	assert_fail "Gold 18k tax unexpected: '${CALC_TAX}' (expected 796500)"
fi

CALC_ZERO="$(wp_cmd eval '
\$r = \FormulaPriceSync\Engine\Calculator::calculate_price(array("source_type"=>"gold_18k","weight"=>1), 0.0);
echo (string) \$r["final_price"];
' 2>/dev/null || echo 'err')"

if [[ "${CALC_ZERO}" == "0" ]]; then
	assert_pass "Invalid rate (0) returns final_price=0"
else
	assert_fail "Invalid rate handling unexpected: '${CALC_ZERO}'"
fi

# ------------------------------------------------------------------------------
# 6. Atomic lock concurrency probe
# ------------------------------------------------------------------------------
echo ""
blue "[6/8] Atomic lock concurrency"

LOCK_RESULT="$(wp_cmd eval '
\$key = "fps_smoke_cli_lock_" . wp_generate_password(6, false);
\$a = \FormulaPriceSync\Core\Atomic_Option_Lock::acquire(\$key, "cli_a", 30);
\$b = \FormulaPriceSync\Core\Atomic_Option_Lock::acquire(\$key, "cli_b", 30);
\$wins = (int)\$a + (int)\$b;
\FormulaPriceSync\Core\Atomic_Option_Lock::release(\$key, \$a ? "cli_a" : "cli_b");
echo \$wins === 1 ? "OK" : "BAD:\$wins";
' 2>/dev/null || echo 'err')"

if [[ "${LOCK_RESULT}" == "OK" ]]; then
	assert_pass "Atomic lock: exactly one winner under contention"
else
	assert_fail "Atomic lock contention result: '${LOCK_RESULT}'"
fi

# ------------------------------------------------------------------------------
# 7. Optional internal PHP smoke runners
# ------------------------------------------------------------------------------
echo ""
blue "[7/8] Internal PHP smoke runners"

PLUGIN_DIR="$(wp_cmd plugin path "${PLUGIN_SLUG}" --dir 2>/dev/null || true)"
if [[ -z "${PLUGIN_DIR}" ]]; then
	# Fallback common path
	PLUGIN_DIR="$(wp_cmd eval 'echo WP_PLUGIN_DIR . "/formula-price-sync";' 2>/dev/null || echo '')"
fi

if [[ "${SKIP_PHPUNIT}" == "1" ]]; then
	assert_warn "Skipped internal PHP runners (--skip-phpunit)"
elif [[ -n "${PLUGIN_DIR}" && -f "${PLUGIN_DIR}/tests/run-smoke.php" ]]; then
	if php "${PLUGIN_DIR}/tests/run-smoke.php" >/tmp/fps-smoke-out.txt 2>&1; then
		assert_pass "tests/run-smoke.php exited 0"
	else
		assert_fail "tests/run-smoke.php failed – see /tmp/fps-smoke-out.txt"
		tail -20 /tmp/fps-smoke-out.txt || true
	fi
	if [[ -f "${PLUGIN_DIR}/tests/smoke-install-hpos.php" ]]; then
		if php "${PLUGIN_DIR}/tests/smoke-install-hpos.php" >/tmp/fps-hpos-out.txt 2>&1; then
			assert_pass "tests/smoke-install-hpos.php exited 0"
		else
			assert_fail "tests/smoke-install-hpos.php failed – see /tmp/fps-hpos-out.txt"
			tail -20 /tmp/fps-hpos-out.txt || true
		fi
	fi
else
	assert_warn "Internal test files not found in plugin dir (release zip excludes tests/) – OK for marketplace package"
fi

# ------------------------------------------------------------------------------
# 8. Optional sync / Action Scheduler probe
# ------------------------------------------------------------------------------
echo ""
blue "[8/8] Action Scheduler / sync probe"

if [[ "${SKIP_SYNC}" == "1" ]]; then
	assert_warn "Skipped sync probe (--skip-sync)"
else
	if wp_cmd eval 'echo function_exists("as_has_scheduled_action") ? "AS_OK" : "AS_MISSING";' 2>/dev/null | grep -q AS_OK; then
		assert_pass "Action Scheduler API available"
		PENDING="$(wp_cmd action-scheduler list --status=pending --hooks=fps_process_product_chunk --format=count 2>/dev/null || echo '0')"
		assert_pass "Pending fps_process_product_chunk actions: ${PENDING}"
	else
		assert_warn "Action Scheduler not detected – WooCommerce may not have loaded AS yet"
	fi
fi

# ------------------------------------------------------------------------------
# Summary
# ------------------------------------------------------------------------------
echo ""
echo "=============================================================="
echo " Summary"
echo "=============================================================="
green "  PASS : ${PASS}"
red   "  FAIL : ${FAIL}"
yellow "  WARN : ${WARN}"
echo "=============================================================="

REPORT_FILE="/tmp/fps-smoke-report-$(date +%Y%m%d-%H%M%S).txt"
{
	echo "Formula Price Sync – Local Smoke Report"
	echo "Date: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
	echo "WP: ${WP_VER:-unknown}"
	echo ""
	for line in "${REPORT[@]}"; do
		echo "${line}"
	done
	echo ""
	echo "PASS=${PASS} FAIL=${FAIL} WARN=${WARN}"
} > "${REPORT_FILE}"
echo "Report saved: ${REPORT_FILE}"
echo ""

if [[ "${FAIL}" -gt 0 ]]; then
	red "STATUS: NOT READY – ${FAIL} mandatory check(s) failed"
	exit 1
fi

green "STATUS: READY – all mandatory checks passed"
if [[ "${WARN}" -gt 0 ]]; then
	yellow "Note: ${WARN} warning(s) – review token/HPOS/tests as applicable"
fi
exit 0
