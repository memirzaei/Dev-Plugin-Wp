[CmdletBinding()]
param(
    [ValidateSet('status','test','smoke','wamp','gate','release-check')]
    [string]$Command = 'status',
    [string]$WpPath = 'C:\wamp64\www\fps-cert',
    [switch]$ContinueOnError,
    [switch]$Json
)
$ErrorActionPreference = 'Stop'
function Write-Section([string]$Name) {
    Write-Host ""
    Write-Host "=== $Name ===" -ForegroundColor Cyan
}
function Resolve-RepoRoot {
    $root = (& git rev-parse --show-toplevel 2>$null)
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace(($root -join ''))) {
        throw "Run fps-agent.ps1 from inside the Formula Price Sync Git repository."
    }
    return (Resolve-Path ($root -join [Environment]::NewLine).Trim()).Path
}
$RepoRoot = Resolve-RepoRoot
$PluginPath = Join-Path $RepoRoot 'formula-price-sync'
$WampGate = Join-Path $PluginPath 'bin\run-wamp-gate.ps1'
if (-not (Test-Path (Join-Path $PluginPath 'formula-price-sync.php'))) {
    throw "Formula Price Sync plugin root not found: $PluginPath"
}
$results = New-Object System.Collections.Generic.List[object]
function Invoke-Step([string]$Name, [scriptblock]$Action) {
    Write-Section $Name
    $sw = [System.Diagnostics.Stopwatch]::StartNew()
    $exitCode = 0
    try {
        & $Action
        if ($null -ne $LASTEXITCODE) { $exitCode = [int]$LASTEXITCODE }
    } catch {
        $exitCode = 1
        Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
    } finally { $sw.Stop() }
    $ok = ($exitCode -eq 0)
    $results.Add([pscustomobject]@{
        name = $Name; passed = $ok; exit_code = $exitCode
        duration_s = [math]::Round($sw.Elapsed.TotalSeconds, 2)
    }) | Out-Null
    if ($ok) {
        Write-Host "[PASS] $Name ($([math]::Round($sw.Elapsed.TotalSeconds,2))s)" -ForegroundColor Green
    } else {
        Write-Host "[FAIL] $Name (exit $exitCode)" -ForegroundColor Red
        if (-not $ContinueOnError) { throw "$Name failed with exit code $exitCode" }
    }
    return $ok
}
function Invoke-Php([string[]]$Arguments) {
    & php @Arguments
    if ($LASTEXITCODE -ne 0) { throw "php failed with exit code $LASTEXITCODE" }
}
function Invoke-Composer([string[]]$Arguments) {
    & composer @Arguments
    if ($LASTEXITCODE -ne 0) { throw "composer failed with exit code $LASTEXITCODE" }
}
Set-Location $RepoRoot
switch ($Command) {
    'status' {
        Write-Section 'FPS Agent Status'
        Write-Host "Repo:   $RepoRoot"
        Write-Host "Plugin: $PluginPath"
        Write-Host "WAMP:   $WpPath"
        Write-Host "Branch: $((& git branch --show-current).Trim())"
        git status --short
        Invoke-Step 'Toolchain' { php -v; composer --version; wp --info }
    }
    'test' {
        Set-Location $PluginPath
        Invoke-Step 'Smoke tests' { Invoke-Php @('tests/run-smoke.php') }
        Invoke-Step 'PHPUnit' {
            if (-not (Test-Path 'vendor/bin/phpunit')) { Invoke-Composer @('install','--no-interaction','--prefer-dist') }
            & 'vendor/bin/phpunit' '--configuration' 'phpunit.xml.dist' '--no-coverage'
            if ($LASTEXITCODE -ne 0) { throw "PHPUnit failed with exit code $LASTEXITCODE" }
        }
    }
    'smoke' {
        Set-Location $PluginPath
        Invoke-Step 'Core smoke' { Invoke-Php @('tests/run-smoke.php') }
        Invoke-Step 'R07/R08 smoke' { Invoke-Php @('tests/run-r07r08.php') }
        Invoke-Step 'R09/R11/R12/R13 smoke' { Invoke-Php @('tests/run-r09r11r12r13.php') }
        Invoke-Step 'WP + WooCommerce + HPOS smoke' { Invoke-Php @('tests/smoke-install-hpos.php') }
    }
    'wamp' {
        if (-not (Test-Path $WampGate)) { throw "WAMP gate runner not found: $WampGate" }
        Invoke-Step 'Real WAMP gate' {
            & powershell -NoProfile -ExecutionPolicy Bypass -File $WampGate -PluginPath $PluginPath -WpPath $WpPath -RunRealIntegration
            if ($LASTEXITCODE -ne 0) { throw "run-wamp-gate.ps1 failed with exit code $LASTEXITCODE" }
        }
    }
    'gate' {
        Set-Location $PluginPath
        Invoke-Step 'Composer install' { Invoke-Composer @('install','--no-interaction','--prefer-dist') }
        Invoke-Step 'Smoke suite' {
            Invoke-Php @('tests/run-smoke.php')
            Invoke-Php @('tests/run-r07r08.php')
            Invoke-Php @('tests/run-r09r11r12r13.php')
            Invoke-Php @('tests/smoke-install-hpos.php')
        }
        Invoke-Step 'PHPUnit' {
            & 'vendor/bin/phpunit' '--configuration' 'phpunit.xml.dist' '--no-coverage'
            if ($LASTEXITCODE -ne 0) { throw "PHPUnit failed with exit code $LASTEXITCODE" }
        }
        Invoke-Step 'PHPCS' {
            Invoke-Composer @('run','lint:phpcs')
        }
        Invoke-Step 'Metadata consistency' {
            & bash 'bin/check-metadata-consistency.sh'
            if ($LASTEXITCODE -ne 0) { throw "Metadata gate failed with exit code $LASTEXITCODE" }
        }
        Invoke-Step 'Real WAMP gate' {
            & powershell -NoProfile -ExecutionPolicy Bypass -File $WampGate -PluginPath $PluginPath -WpPath $WpPath -RunRealIntegration
            if ($LASTEXITCODE -ne 0) { throw "run-wamp-gate.ps1 failed with exit code $LASTEXITCODE" }
        }
    }
    'release-check' {
        Set-Location $PluginPath
        Invoke-Step 'Release metadata' {
            & bash 'bin/check-metadata-consistency.sh'
            if ($LASTEXITCODE -ne 0) { throw "Metadata gate failed with exit code $LASTEXITCODE" }
        }
        Invoke-Step 'Release build' {
            & bash 'bin/build-release.sh'
            if ($LASTEXITCODE -ne 0) { throw "Release build failed with exit code $LASTEXITCODE" }
        }
        Invoke-Step 'Repository bypass/skip scan' {
            $patterns = @('FPS_ENGINEERING_CANDIDATE','FPS_SKIP_TESTS=1','Skip PHPUnit','Optional PHPUnit')
            $scanFiles = @('bin','README.md','readme.txt','.github')
            foreach ($pattern in $patterns) {
                $matches = Get-ChildItem -Path $scanFiles -Recurse -File -ErrorAction SilentlyContinue |
                    Select-String -Pattern $pattern -SimpleMatch -ErrorAction SilentlyContinue
                if ($matches) { throw "Release bypass/skip pattern found: $pattern" }
            }
        }
        Write-Host "Release-check is non-deploying and does not publish or push artifacts." -ForegroundColor Yellow
    }
}
if ($Json) {
    [pscustomobject]@{
        command = $Command; repo = $RepoRoot; plugin = $PluginPath; wamp = $WpPath
        passed = (($results | Where-Object { -not $_.passed }).Count -eq 0)
        steps = @($results)
    } | ConvertTo-Json -Depth 5
}
$failed = @($results | Where-Object { -not $_.passed }).Count
if ($failed -gt 0) { exit 1 }
exit 0
