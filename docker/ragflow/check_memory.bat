@echo off
chcp 65001 >nul 2>&1
title Memory Usage Check

echo ============================================
echo  Top Memory-Consuming Processes
echo ============================================
echo.

powershell -NoProfile -Command ^
  "Get-Process | Sort-Object WorkingSet64 -Descending | Select-Object -First 25 | ForEach-Object { $mb = [math]::Round($_.WorkingSet64 / 1MB); $name = $_.ProcessName; $id = $_.Id; '{0,-30} PID:{1,-8} {2,6} MB' -f $name, $id, $mb } | ForEach-Object { Write-Host $_ }"

echo.
echo ============================================
echo  Memory Summary
echo ============================================
powershell -NoProfile -Command ^
  "$os = Get-CimInstance Win32_OperatingSystem; $total = [math]::Round($os.TotalVisibleMemorySize / 1KB, 1); $free = [math]::Round($os.FreePhysicalMemory / 1KB, 1); $used = $total - $free; $pct = [math]::Round($used / $total * 100, 1); Write-Host ('Total: ' + $total + ' GB'); Write-Host ('Used:  ' + $used + ' GB (' + $pct + '%)'); Write-Host ('Free:  ' + $free + ' GB')"

echo.
echo ============================================
echo  Docker WSL2 VM Memory (if running)
echo ============================================
powershell -NoProfile -Command ^
  "try { $wsl = wsl.exe -l -v 2>&1; Write-Host $wsl } catch { Write-Host 'WSL not running or not installed' }"

echo.
echo Done. Copy this window text (or screenshot) and send to assistant.
echo.
pause >nul
