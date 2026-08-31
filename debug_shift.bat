@echo off
echo before %1 %2 %3 %4
shift
echo after %1 %2 %3 %4
call echo hello %*
