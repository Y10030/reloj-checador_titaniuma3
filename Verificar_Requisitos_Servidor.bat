@echo off
chcp 65001 >nul
echo Solicitando permisos de Administrador para verificar e instalar requisitos...
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell -Verb RunAs -ArgumentList '-NoExit -NoProfile -ExecutionPolicy Bypass -File \"%~dp0scripts\Verificar_E_Instalar_Requisitos.ps1\"'"
