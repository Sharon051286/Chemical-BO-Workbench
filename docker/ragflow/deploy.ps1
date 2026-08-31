# deploy.ps1 - One-click local RAGFlow deployment for Windows (Docker Desktop)
#
# What it does:
#   1. Checks Docker / Docker Desktop is installed and running
#   2. Reads total physical RAM and sizes the Elasticsearch JVM heap
#      (>=16GB -> 1g, 8-15GB -> 512m, <8GB -> 256m) to avoid OOM on small PCs
#   3. Writes a .env next to this script so docker compose picks up ES_JAVA_OPTS
#   4. Runs `docker compose up -d`
#   5. Polls http://localhost:9380/api/v1/health until RAGFlow is ready
#   6. Prints the next steps (get API key, configure .env, run ragflow:setup)
#
# Run it from this directory:
#   powershell -ExecutionPolicy Bypass -File deploy.ps1
# Or just double-click deploy.bat in the same folder.

$ErrorActionPreference = 'Stop'

function Step($m) { Write-Host ''; Write-Host "==> $m" -ForegroundColor Cyan }

# ---------------------------------------------------------------- 1. Docker
Step 'Checking Docker'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host 'ERROR: docker not found. Install Docker Desktop first:' -ForegroundColor Red
    Write-Host '  https://www.docker.com/products/docker-desktop/  (enable WSL2 backend)' -ForegroundColor Yellow
    exit 1
}
function Ensure-DockerRunning {
    try { docker info | Out-Null; return $true } catch { }

    $dockerExe = (Get-Command docker -ErrorAction SilentlyContinue).Source
    if (-not $dockerExe) { return $false }

    $ddPath = Join-Path (Split-Path $dockerExe -Parent) 'Docker Desktop.exe'
    if (-not (Test-Path $ddPath)) {
        $progs = ${env:ProgramFiles}, ${env:ProgramFiles(x86)}, ${env:LocalAppData}
        foreach ($base in $progs) {
            if (-not $base) { continue }
            $candidates = @(
                Join-Path $base 'Docker\Docker\Docker Desktop.exe'
                Join-Path $base 'Docker Desktop\Docker Desktop.exe'
            )
            foreach ($c in $candidates) {
                if (Test-Path $c) { $ddPath = $c; break }
            }
            if (Test-Path $ddPath) { break }
        }
    }

    if (Test-Path $ddPath) {
        Write-Host 'Docker Desktop is installed but not running. Starting it now...' -ForegroundColor Yellow
        Start-Process -FilePath $ddPath -NoNewWindow
        Write-Host 'Waiting for Docker engine to start (this may take a minute)...' -ForegroundColor Yellow
        for ($i = 1; $i -le 24; $i++) {
            Start-Sleep -Seconds 5
            try { docker info | Out-Null; return $true } catch { }
            Write-Host "  still waiting... ($i/24, ~$($i * 5)s)"
        }
    }
    return $false
}

if (-not (Ensure-DockerRunning)) {
    Write-Host 'ERROR: Docker daemon is not running. Start Docker Desktop manually and retry.' -ForegroundColor Red
    exit 1
}

# ------------------------------------------------------ 2. RAM-based heap
Step 'Detecting RAM to size Elasticsearch heap'
$physGB = [math]::Round((Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB)
Write-Host "Physical RAM: $physGB GB"
if ($physGB -ge 16) { $heap = '1g' }
elseif ($physGB -ge 8) { $heap = '512m' }
else { $heap = '256m' }
if ($physGB -lt 8) {
    Write-Host 'WARNING: <8GB RAM. The RAGFlow stack (MySQL+ES+Redis) is heavy and may' -ForegroundColor Yellow
    Write-Host 'be slow or fail. Close other apps, or raise RAM if possible.' -ForegroundColor Yellow
}
Write-Host "ES_JAVA_OPTS = -Xms$heap -Xmx$heap"

# write .env for compose (pure ASCII)
@"
ES_JAVA_OPTS=-Xms$heap -Xmx$heap
"@ | Set-Content -Encoding ascii .env

# --------------------------------------------------- 3. Pull + start stack
Step 'Preparing docker compose command'
if (docker compose version 2>$null) { $compose = 'docker compose' }
elseif (Get-Command docker-compose -ErrorAction SilentlyContinue) { $compose = 'docker-compose' }
else { Write-Host 'ERROR: docker compose not available. Update Docker Desktop.' -ForegroundColor Red; exit 1 }

Step 'Starting RAGFlow stack'
& $compose up -d
if ($LASTEXITCODE -ne 0) {
    Write-Host 'ERROR: docker compose failed. See output above.' -ForegroundColor Red
    exit 1
}

# ---------------------------------------------- 4. Wait for health endpoint
Step 'Waiting for RAGFlow to become ready (polling /api/v1/health)'
$ready = $false
for ($i = 1; $i -le 90; $i++) {
    try {
        $r = Invoke-WebRequest -Uri 'http://localhost:9380/api/v1/health' `
            -TimeoutSec 3 -UseBasicParsing -ErrorAction SilentlyContinue
        if ($r -and $r.StatusCode -eq 200) { $ready = $true; break }
    } catch { }
    Start-Sleep -Seconds 5
    Write-Host "  waiting... ($i/90, ~$(($i) * 5)s elapsed)"
}
if (-not $ready) {
    Write-Host 'TIMEOUT: RAGFlow not ready in ~7.5 min. Inspect logs:' -ForegroundColor Yellow
    Write-Host '  docker compose logs -f ragflow' -ForegroundColor Yellow
} else {
    Write-Host 'RAGFlow is UP at http://localhost:9380' -ForegroundColor Green
}

# ----------------------------------------------------------- 5. Next steps
Step 'Next steps'
Write-Host '1. Open http://localhost:9380 and create an account'
Write-Host '2. Go to Avatar -> API, copy your API Key'
Write-Host '3. In the project root .env set:'
Write-Host '      RAGFLOW_API_URL=http://localhost:9380'
Write-Host '      RAGFLOW_API_KEY=<your key>'
Write-Host '4. Run:  php artisan ragflow:setup'
Write-Host '5. In the app menu "Prior Data Driven", click "Check Connection".'
