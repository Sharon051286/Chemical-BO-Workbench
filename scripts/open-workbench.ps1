# Start the Tuesday workbench: Vite UI on 8080, Laravel API on 8000 only.
$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Php = if (Test-Path "D:\php\php.exe") { "D:\php\php.exe" } else { "php" }
$Frontend = Join-Path $Root "frontend"

function Get-ListenerPid([int]$Port) {
    $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($conn) { return [int]$conn.OwningProcess }
    return $null
}

function Get-ListenerName([int]$ProcessId) {
    try { return (Get-Process -Id $ProcessId -ErrorAction Stop).ProcessName } catch { return "" }
}

function Test-UrlLooksLikeWorkbench([string]$Url) {
    try {
        $r = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 8
        return $r.Content -match "优化工作台 · EDBO Web|EDBO Web · 实验设计贝叶斯优化"
    } catch {
        return $false
    }
}

# Never let PHP serve 8080 — that is the old Livewire page.
$p8080 = Get-ListenerPid 8080
if ($p8080) {
    $name = Get-ListenerName $p8080
    if ($name -match "php") {
        Write-Host "8080 was taken by PHP (old page). Stopping PID $p8080 ..."
        Stop-Process -Id $p8080 -Force -ErrorAction SilentlyContinue
        Start-Sleep -Seconds 1
    }
}

$p8000 = Get-ListenerPid 8000
if (-not $p8000) {
    Write-Host "Starting Laravel API on 8000 ..."
    Start-Process -FilePath $Php -ArgumentList @("-S", "127.0.0.1:8000", "-t", "public", "server.php") -WorkingDirectory $Root -WindowStyle Minimized
} else {
    Write-Host "Laravel API already on 8000 (PID $p8000)"
}

if (-not (Test-UrlLooksLikeWorkbench "http://127.0.0.1:8080/")) {
    $again = Get-ListenerPid 8080
    if ($again) {
        $name = Get-ListenerName $again
        if ($name -match "php") {
            Stop-Process -Id $again -Force -ErrorAction SilentlyContinue
            Start-Sleep -Seconds 1
        }
    }
    if (-not (Get-ListenerPid 8080)) {
        Write-Host "Starting frontend on 8080 ..."
        Start-Process -FilePath "npm" -ArgumentList @("run", "dev") -WorkingDirectory $Frontend -WindowStyle Minimized
        $deadline = (Get-Date).AddSeconds(40)
        do {
            Start-Sleep -Seconds 2
            if (Test-UrlLooksLikeWorkbench "http://127.0.0.1:8080/") { break }
        } while ((Get-Date) -lt $deadline)
    }
}

Write-Host ""
Write-Host "Correct page:  http://127.0.0.1:8080/"
Write-Host "API only:      http://127.0.0.1:8000/  (old Livewire, do not use daily)"
Write-Host "Git branch:    current-workbench"
Start-Process "http://127.0.0.1:8080/"
