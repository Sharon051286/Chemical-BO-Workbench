@echo off
REM EDBO bridge runner batch wrapper
REM Ensures proper console context for micromamba under PHP web server
REM Usage: edbo_run.bat <mode> [args...]
REM   mode=healthcheck : run health check
REM   mode=optimize    : run optimization (args: --config <path> --output <path>)

set MICROMAMBA="D:\miniconda_install\Library\bin\micromamba.exe"
set ENVPREFIX="D:\miniconda3\envs\edbo"
set RUNNER="C:\Users\Sharon\WorkBuddy\2026-08-04-14-42-55\edbo-web\scripts\edbo_runner.py"
set HEALTHSCRIPT="C:\Users\Sharon\WorkBuddy\2026-08-04-14-42-55\edbo-web\scripts\edbo_healthcheck.py"

if "%1"=="healthcheck" goto healthcheck
if "%1"=="optimize" goto optimize

echo Unknown mode: %1
exit /b 1

:healthcheck
%MICROMAMBA% run -p %ENVPREFIX% python %HEALTHSCRIPT%
exit /b %ERRORLEVEL%

:optimize
shift
call %MICROMAMBA% run -p %ENVPREFIX% python "%RUNNER%" %1 %2 %3 %4 %5 %6 %7 %8
exit /b %ERRORLEVEL%
