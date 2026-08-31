@echo off
chcp 65001 >nul 2>&1
title Install WSL2 (required by Docker Desktop)

echo ============================================================
echo  WSL2 Installer for Docker Desktop
echo ============================================================
echo.

:: Check administrator
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [ERROR] This script must be run as Administrator.
    echo Right-click this file and choose "Run as administrator".
    echo.
    pause
    exit /b 1
)

echo [1/2] Installing WSL and default Ubuntu distribution...
echo       This downloads from Microsoft. Please wait.
echo.
wsl --install -d Ubuntu
if %errorLevel% neq 0 (
    echo.
    echo [ERROR] wsl --install failed (code %errorLevel%).
    echo Try opening PowerShell as admin and run:  wsl --install -d Ubuntu
    echo.
    pause
    exit /b 1
)

echo.
echo [2/2] WSL installation started.
echo.
echo IMPORTANT: You MUST restart your computer now.
echo After reboot:
echo   1. Ubuntu will open once and ask you to create a username/password.
echo      (Use a simple lowercase username, e.g. "user")
echo   2. Then start Docker Desktop from the Start menu.
echo   3. Wait until the whale icon shows "Docker Desktop is running".
echo   4. Run docker/ragflow/check_status.bat to verify.
echo   5. Then run docker/ragflow/deploy.bat to start RAGFlow.
echo.
echo Press any key to close this window, then RESTART your PC.
pause >nul
