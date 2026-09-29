param(
    [string]$ProjectRoot = $PSScriptRoot,
    [switch]$CleanBuild
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path -LiteralPath $ProjectRoot).Path
$compose = Join-Path $ProjectRoot 'compose.yaml'
if (-not (Test-Path -LiteralPath $compose)) {
    throw "compose.yaml was not found in $ProjectRoot. Put this script in the project folder or pass -ProjectRoot."
}

$source = 'https://raw.githubusercontent.com/IBOTS-AI/2026-Scouting-Vibetest/main'
$stamp = [DateTimeOffset]::UtcNow.ToUnixTimeMilliseconds()
$files = @(
    'Dockerfile',
    'public/index.php',
    'public/style.css',
    'public/dashboard.js',
    'public/auto-path.js',
    'public/strategy.js',
    'public/pick-list.js',
    'public/pit-tags.js',
    'sql/schema.sql',
    'fixtures/wpi-2026.json'
)

foreach ($relative in $files) {
    $target = Join-Path $ProjectRoot ($relative.Replace('/', '\'))
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $target) | Out-Null
    $temporary = "$target.download"
    & curl.exe --fail --location --silent --show-error --retry 3 --retry-delay 1 --connect-timeout 10 --max-time 90 --output $temporary "${source}/${relative}?v=${stamp}"
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $temporary) -or (Get-Item -LiteralPath $temporary).Length -eq 0) {
        Remove-Item -LiteralPath $temporary -ErrorAction SilentlyContinue
        throw "Download failed: $relative. The existing copy was left in place."
    }
    Move-Item -LiteralPath $temporary -Destination $target -Force
    Write-Host "Downloaded $relative"
}

if ($CleanBuild) {
    & docker compose -f $compose build --no-cache web
    if ($LASTEXITCODE -ne 0) { throw 'The clean Docker build failed.' }
}
& docker compose -f $compose up --build --force-recreate -d web
if ($LASTEXITCODE -ne 0) { throw 'Docker Compose failed to start the updated web container.' }

$checks = @{
    'public/index.php' = '/var/www/html/index.php'
    'public/style.css' = '/var/www/html/style.css'
    'public/dashboard.js' = '/var/www/html/dashboard.js'
    'public/auto-path.js' = '/var/www/html/auto-path.js'
    'public/strategy.js' = '/var/www/html/strategy.js'
    'public/pick-list.js' = '/var/www/html/pick-list.js'
    'public/pit-tags.js' = '/var/www/html/pit-tags.js'
    'sql/schema.sql' = '/var/www/sql/schema.sql'
    'fixtures/wpi-2026.json' = '/var/www/fixtures/wpi-2026.json'
}
foreach ($relative in $checks.Keys) {
    $local = Join-Path $ProjectRoot ($relative.Replace('/', '\'))
    $localHash = (Get-FileHash -LiteralPath $local -Algorithm SHA256).Hash
    $output = & docker compose -f $compose exec -T web sha256sum $checks[$relative]
    if ($LASTEXITCODE -ne 0) { throw "Could not verify $relative in the running container." }
    $match = [regex]::Match(($output -join "`n"), '^\s*([0-9a-fA-F]{64})\s+', [System.Text.RegularExpressions.RegexOptions]::Multiline)
    if (-not $match.Success -or $match.Groups[1].Value.ToUpperInvariant() -ne $localHash) {
        throw "The running container has a different copy of $relative. Try this script again with -CleanBuild."
    }
}
Write-Host 'Updated successfully. The running container matches the downloaded files.' -ForegroundColor Green
Write-Host 'Open http://localhost:8080 and refresh the page. CSS and JavaScript URLs change automatically when their contents change.'
