# ==============================================================================
# Guía automatizada de Cloudflare Tunnel (HTTPS sin abrir puertos en el router)
# ==============================================================================
param(
    [string]$TunnelName = "checador-titanium",
    [string]$Hostname = "checador.tuempresa.com",
    [int]$LocalPort = 8080
)

Write-Host "=== Configuración de Cloudflare Tunnel ($TunnelName) ===" -ForegroundColor Cyan
Write-Host "1. Si no tiene cloudflared, instálelo con:"
Write-Host "   winget install --id Cloudflare.cloudflared" -ForegroundColor Yellow
Write-Host ""
Write-Host "2. Inicie sesión con su cuenta de Cloudflare:"
Write-Host "   cloudflared tunnel login" -ForegroundColor Yellow
Write-Host ""
Write-Host "3. Cree el túnel y asigne el subdominio HTTPS:"
Write-Host "   cloudflared tunnel create $TunnelName" -ForegroundColor Yellow
Write-Host "   cloudflared tunnel route dns $TunnelName $Hostname" -ForegroundColor Yellow
Write-Host ""
Write-Host "4. Ejecute el túnel apuntando al servidor local (sin exponer Firebird 3050):"
Write-Host "   cloudflared tunnel run --url http://127.0.0.1:$LocalPort $TunnelName" -ForegroundColor Green
Write-Host ""
Write-Host "5. Para pruebas rápidas temporales (sin dominio propio aún):"
Write-Host "   cloudflared tunnel --url http://127.0.0.1:$LocalPort" -ForegroundColor Green
