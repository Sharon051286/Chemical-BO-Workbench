@echo off
chcp 65001 >nul 2>&1
title Install Docker Desktop (required for RAGFlow)

echo ============================================================
echo  Docker Desktop Installer (for RAGFlow local deployment)
echo ============================================================
echo.

:: ---- 1. Require Administrator ----
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo [ERROR] This script must be run as Administrator.
    echo Right-click this file and choose "Run as administrator".
    echo.
    pause
    exit /b 1
)

:: ---- 2. Check winget ----
where winget >nul 2>&1
if %errorLevel% neq 0 (
    echo [ERROR] winget is not available on this machine.
    echo Please install "App Installer" from the Microsoft Store, then retry.
    echo Or download Docker Desktop manually from:
    echo   https://www.docker.com/products/docker-desktop
    echo.
    pause
    exit /b 1
)

:: ---- 3. Install Docker Desktop silently ----
echo [1/3] Installing Docker Desktop (silent mode)...
echo       This downloads several hundred MB and may take several minutes.
echo       Please do not close this window.
echo.
winget install --id Docker.DockerDesktop -e --accept-package-agreements --accept-source-agreements --silent
if %errorLevel% neq 0 (
    echo.
    echo [ERROR] Installation failed (exit code %errorLevel%).
    echo Common causes: no internet access, or winget package issue.
    echo Fallback: download Docker Desktop manually from
    echo   https://www.docker.com/products/docker-desktop
    echo.
    pause
    exit /b 1
)

:: ---- 4. Done ----
echo.
echo [2/3] Docker Desktop installed successfully.
echo.
echo [3/3] Next steps:
echo   1. Start "Docker Desktop" from the Start menu.
echo   2. First launch: accept the license, and let it configure WSL2.
echo      (If it asks to install WSL2, click "Install" and reboot if required.)
echo   3. Wait until the whale icon shows "Docker Desktop is running".
echo   4. Then go to this project's docker\ragflow folder and
echo      double-click deploy.bat to start RAGFlow.
echo.
echo Press any key to exit.
pause >nul
