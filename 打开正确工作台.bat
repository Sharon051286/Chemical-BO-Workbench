@echo off
chcp 65001 >nul
echo.
echo  正确页面:  http://127.0.0.1:8080/
echo  正确分支:  current-workbench
echo  不要用:    http://127.0.0.1:8000/  （旧 Livewire 页）
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\open-workbench.ps1"
if errorlevel 1 pause
