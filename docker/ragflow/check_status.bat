@echo off
chcp 65001 >nul 2>&1
title RAGFlow / Docker Status Check

echo ============================================
echo  Docker and RAGFlow Status Check
echo ============================================
echo.

echo [1] Docker CLI version:
docker --version 2>&1
if %errorLevel% neq 0 (
    echo     --> Docker NOT installed. Run install_docker.bat first.
    echo.
    pause
    exit /b 1
)
echo.

echo [2] Docker daemon running? (docker ps)
docker ps >nul 2>&1
if %errorLevel% neq 0 (
    echo     --> Docker daemon NOT running. Start Docker Desktop first.
    echo.
    pause
    exit /b 1
)
echo     --> Docker daemon is running OK.
echo.

echo [3] RAGFlow containers (filter: ragflow):
docker ps --filter "name=ragflow" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
echo     (if empty above, RAGFlow stack is NOT started -> run deploy.bat)
echo.

echo [4] Probe http://localhost:9380/api/v1/health
powershell -NoProfile -Command "try { $r = Invoke-RestMethod -Uri 'http://localhost:9380/api/v1/health' -TimeoutSec 5 -ErrorAction Stop; Write-Host ('    --> RAGFlow reachable. health: ' + ($r | ConvertTo-Json -Compress)) } catch { Write-Host ('    --> RAGFlow NOT reachable: ' + $_.Exception.Message) }"
echo.

echo Done. Copy this window text (or screenshot) and send to assistant.
echo.
pause >nul
