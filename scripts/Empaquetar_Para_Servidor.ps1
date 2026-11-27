<#
.SYNOPSIS
  Genera un paquete ZIP limpio y autocontenido de produccion listo para copiar al servidor de Acceso 3 TITANIUM.
  Incluye modelos ONNX, fbclient_x64.dll, cloudflared.exe, ejecutable y el instalador automatico.
  Excluye archivos de desarrollo (.env, .venv, SQLite de pruebas, logs).
#>
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Parent = Split-Path -Parent $Root
$Stage = Join-Path $env:TEMP ('checador-titanium-stage-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
$ZipOut = Join-Path $Parent 'checador-titanium-produccion.zip'

Write-Host "Empaquetando version limpia y autocontenida de produccion (incluye cloudflared.exe)..." -ForegroundColor Green
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

# Copiar todo excepto carpetas/archivos de desarrollo y credenciales locales
robocopy $Root $Stage /E /NFL /NDL /NJH /NJS /NC /NS `
    /XD "$Root\facial-service\.venv" "$Root\facial-service\__pycache__" "$Root\logs" "$Root\.git" "$Root\cloudflared" `
    /XF "$Root\.env" "$Root\database\checador_local.sqlite" "$Root\database\checador_local.sqlite-wal" "$Root\database\checador_local.sqlite-shm" | Out-Null

if ($LASTEXITCODE -ge 8) {
    throw "Error al copiar archivos con robocopy (codigo $LASTEXITCODE)"
}

New-Item -ItemType Directory -Path (Join-Path $Stage 'logs') -Force | Out-Null

# Usar el ejecutable actualizado compilado para servidor si existe
$ServerExe = Join-Path $Root 'scripts\runtime\Iniciar_Sistema_Esperanza_Pallets.exe'
if (Test-Path $ServerExe) {
    Copy-Item $ServerExe (Join-Path $Stage 'Iniciar_Sistema_Esperanza_Pallets.exe') -Force
    Remove-Item (Join-Path $Stage 'scripts\runtime\Iniciar_Sistema_Esperanza_Pallets.exe') -Force -ErrorAction SilentlyContinue
}

if (Test-Path $ZipOut) { Remove-Item $ZipOut -Force }
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($Stage, $ZipOut, [System.IO.Compression.CompressionLevel]::Optimal, $false)
Remove-Item $Stage -Recurse -Force

$sizeMb = [Math]::Round((Get-Item $ZipOut).Length / 1MB, 2)
Write-Host "[OK] Paquete listo: $ZipOut ($sizeMb MB)" -ForegroundColor Cyan
Write-Host "Copie ese archivo ZIP al servidor donde corre Acceso 3 TITANIUM, descomprimalo en C:\checador-titanium y ejecute Instalar_En_Servidor_Produccion.bat" -ForegroundColor White
