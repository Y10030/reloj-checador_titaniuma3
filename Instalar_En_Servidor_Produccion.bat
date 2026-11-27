@echo off
chcp 65001 >nul
echo Solicitando permisos de Administrador para instalar La Esperanza Pallets...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoExit -NoProfile -ExecutionPolicy Bypass -File \"%~dp0Instalar_En_Servidor_Produccion.ps1\"'"
