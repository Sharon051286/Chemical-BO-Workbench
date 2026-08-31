@echo off
REM Ax engine wrapper - run Python scripts via micromamba in edbo-ax env
REM Usage: ax_run.bat healthcheck
REM        ax_run.bat optimize --config <path> --output <path>

set MICROMAMBA="D:\miniconda_install\Library\bin\micromamba.exe"
set ENV_PREFIX="D:\miniconda3\envs\edbo-ax"
set SCRIPTS_DIR=%~dp0

if "%1"=="healthcheck" goto healthcheck
if "%1"=="optimize" goto optimize

echo Usage: ax_run.bat [healthcheck^|optimize --config ^<path^> --output ^<path^>]
exit /b 1

:healthcheck
%MICROMAMBA% run -p %ENV_PREFIX% python "%SCRIPTS_DIR%ax_healthcheck.py"
exit /b %ERRORLEVEL%

:optimize
shift
call %MICROMAMBA% run -p %ENV_PREFIX% python "%SCRIPTS_DIR%ax_runner.py" %1 %2 %3 %4 %5 %6 %7 %8
exit /b %ERRORLEVEL%
