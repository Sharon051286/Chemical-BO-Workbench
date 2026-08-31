@echo off
echo before %1 %2 %3 %4
shift
echo after %1 %2 %3 %4
set "ARGS=%1 %2 %3 %4"
echo ARGS=%ARGS%
call echo hello %ARGS%
