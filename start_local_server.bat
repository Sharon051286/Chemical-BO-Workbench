@echo off
REM Start the local Laravel development server for the edbo-web project.
REM Usage: start_local_server.bat  (double-click, or auto-run from Startup folder)
REM
REM Improvements over the original:
REM  - Self-heals storage permissions so Laravel can always write laravel.log
REM    (avoids the "Permission denied" 500 after restarts / admin runs)
REM  - Auto-opens the app in the default browser after a short delay
REM  - Binds to 127.0.0.1:8000 (IPv4) to avoid localhost -> IPv6 mismatches

setlocal
set "DEFAULT_PHP=D:\php\php.exe"
set "PROJECT_ROOT=%~dp0"
set "ROUTER=%PROJECT_ROOT%server.php"
set "APP_URL=http://127.0.0.1:8000"

if exist "%DEFAULT_PHP%" (
    set "PHP_EXEC=%DEFAULT_PHP%"
) else (
    where php >nul 2>&1
    if errorlevel 1 (
        echo ERROR: PHP executable not found at %DEFAULT_PHP% and php is not on PATH.
        echo Please install PHP or add php.exe to your PATH.
        pause
        exit /b 1
    )
    set "PHP_EXEC=php"
)

if not exist "%ROUTER%" (
    echo ERROR: Laravel router file not found: %ROUTER%
    pause
    exit /b 1
)

REM --- Self-heal: grant current user full control on storage (best-effort) ---
REM Fixes the recurring "laravel.log Permission denied" after restarts.
icacls "%PROJECT_ROOT%storage" /grant "%USERNAME%:(OI)(CI)F" /T >nul 2>&1

echo Correct page: http://127.0.0.1:8080/
echo API only (old Livewire): %APP_URL%
echo Press Ctrl+C to stop the API. Frontend stays on 8080.

REM Keep PHP off 8080, start Vite + API, open the workbench.
powershell -NoProfile -ExecutionPolicy Bypass -File "%PROJECT_ROOT%scripts\open-workbench.ps1"
echo.
echo Servers are running in the background. Close those windows to stop.
pause
endlocal
