# ==============================================================================
# Abre un túnel HTTPS instantáneo hacia http://127.0.0.1:8080 con Cloudflare Tunnel
# Permite probar cámara, GPS y Web Crypto directamente desde teléfonos celulares
# ==============================================================================
param(
    [int]$LocalPort = 8080
)

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Cf = Join-Path $ScriptDir "cloudflared.exe"
if (-not (Test-Path $Cf)) {
    throw "No se encontró cloudflared.exe en $ScriptDir."
}

Write-Host "Abriendo Cloudflare Tunnel HTTPS hacia http://127.0.0.1:$LocalPort ..." -ForegroundColor Cyan
& $Cf tunnel --no-autoupdate --url "http://127.0.0.1:$LocalPort"
