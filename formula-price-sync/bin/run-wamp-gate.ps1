[CmdletBinding()]
param(
    [string]$PluginPath = "",
    [string]$WpPath = "",
    [switch]$RunRealIntegration,
    [switch]$AutoCommit,
    [switch]$AllowMain
)

$ErrorActionPreference = "Stop"

function Step($name) {
    Write-Host ""
    Write-Host "=== $name ===" -ForegroundColor Cyan
}

function Invoke-Gate($label, [scriptblock]$action) {
    Step $label
    & $action
    if ($LASTEXITCODE -ne 0) {
        throw "$label failed with exit code $LASTEXITCODE"
    }
}

if ([string]::IsNullOrWhiteSpace($PluginPath)) {
    $PluginPath = (Get-Location).Path
}
$PluginPath = (Resolve-Path $PluginPath).Path

if ([string]::IsNullOrWhiteSpace($WpPath)) {
    $WpPath = (Resolve-Path (Join-Path $PluginPath "..\..\..")).Path
} else {
    $WpPath = (Resolve-Path $WpPath).Path
}

if (-not (Test-Path (Join-Path $PluginPath "formula-price-sync.php"))) {
    throw "Plugin path is not Formula Price Sync: $PluginPath"
}
if (-not (Test-Path (Join-Path $WpPath "wp-config.php"))) {
    throw "WordPress path does not contain wp-config.php: $WpPath"
}

Step "Environment"
Write-Host "Plugin: $PluginPath"
Write-Host "WordPress: $WpPath"
php -v
composer --version
wp --info

Set-Location $PluginPath

Step "Git baseline"
git status --short
$branch = (git branch --show-current).Trim()
Write-Host "Branch: $branch"

if ($AutoCommit -and ($branch -eq "main" -or $branch -eq "master") -and -not $AllowMain) {
    throw "AutoCommit on main/master is blocked. Use a feature branch or pass -AllowMain explicitly."
}

Invoke-Gate "Composer install" {
    composer install --no-interaction --prefer-dist
}

Invoke-Gate "PHP syntax gate" {
    $files = @(
        Get-ChildItem -Path (Join-Path $PluginPath "includes") -Recurse -Filter *.php -File
        Get-Item (Join-Path $PluginPath "formula-price-sync.php")
        Get-Item (Join-Path $PluginPath "uninstall.php")
        Get-ChildItem -Path (Join-Path $PluginPath "tests") -Recurse -Filter *.php -File
    )
    foreach ($file in $files) {
        php -l $file.FullName
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
}

Invoke-Gate "Existing smoke gates" {
    php tests/run-smoke.php
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    php tests/run-r07r08.php
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    php tests/run-r09r11r12r13.php
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    php tests/smoke-install-hpos.php
}

Invoke-Gate "PHPUnit" {
    if (-not (Test-Path "vendor/bin/phpunit")) {
        throw "vendor/bin/phpunit not found after composer install"
    }
    vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage
}

Invoke-Gate "PHPCS" {
    composer run lint:phpcs
}

Invoke-Gate "Metadata gate" {
    bash bin/check-metadata-consistency.sh
}

Step "WordPress / WooCommerce runtime"
wp --path="$WpPath" core version
wp --path="$WpPath" plugin status formula-price-sync
wp --path="$WpPath" plugin status woocommerce

if ($RunRealIntegration) {
    Invoke-Gate "Real WP + WooCommerce + HPOS integration" {
        wp --path="$WpPath" eval-file tests/Integration/real-wp-wc-hpos-e2e.php prepare
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        wp --path="$WpPath" action-scheduler run --hooks=fps_process_queue_continuation --group=fps-price-sync --force
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        wp --path="$WpPath" eval-file tests/Integration/real-wp-wc-hpos-e2e.php verify
    }
}

Step "Final gate"
Write-Host "ALL REQUESTED GATES PASSED." -ForegroundColor Green

if ($AutoCommit) {
    Step "Commit and push"
    git add .
    if ($LASTEXITCODE -ne 0) { throw "git add failed" }

    $pending = git status --porcelain
    if ([string]::IsNullOrWhiteSpace($pending)) {
        Write-Host "No working-tree changes to commit."
    } else {
        git diff --cached --name-status
        git commit -m "test: pass real local WAMP gate"
        if ($LASTEXITCODE -ne 0) { throw "git commit failed" }

        git push -u origin $branch
        if ($LASTEXITCODE -ne 0) { throw "git push failed" }

        Write-Host "Committed and pushed branch: $branch" -ForegroundColor Green
    }
}
