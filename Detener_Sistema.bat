@echo off
chcp 65001 >nul
title Detener Sistema - La Esperanza Pallets
echo Deteniendo unicamente los servicios de La Esperanza Pallets (sin afectar otros tuneles)...
powershell -NoProfile -ExecutionPolicy Bypass -Command "$root = (Resolve-Path '%~dp0').Path.TrimEnd('\'); $webPort = 8080; $facialPort = 8001; $envFile = Join-Path $root '.env'; if (Test-Path $envFile) { foreach ($l in Get-Content $envFile) { if ($l -match '^WEB_PORT=(\d+)') { $webPort = [int]$Matches[1] }; if ($l -match '^FACIAL_PORT=(\d+)') { $facialPort = [int]$Matches[1] } } }; foreach ($t in @('EsperanzaPallets_WebPHP','EsperanzaPallets_FacialONNX','EsperanzaPallets_Cloudflared')) { Stop-ScheduledTask -TaskName $t -ErrorAction SilentlyContinue }; foreach ($p in @($webPort, $facialPort)) { Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue } }; Get-CimInstance Win32_Process -Filter \"Name='cloudflared.exe'\" -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like \"*$root*\" -or $_.CommandLine -like \"*:$webPort*\" } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }"
echo Servicios de La Esperanza Pallets detenidos correctamente.
pause
