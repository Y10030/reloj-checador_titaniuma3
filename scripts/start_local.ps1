# ==============================================================================
# Arranque local integrado (Servidor PHP + Microservicio Facial Python)
# ==============================================================================
param(
    [string]$PhpExe = "C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe",
    [string]$PythonExe = "C:\laragon\bin\python\python-3.10\python.exe",
    [int]$WebPort = 8080,
    [int]$FacialPort = 8001,
    [switch]$AllowFallback
)

$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$VenvPython = Join-Path $Root "facial-service\.venv\Scripts\python.exe"
if (Test-Path $VenvPython) { $PythonExe = $VenvPython }

if ($AllowFallback) { $env:FACIAL_ALLOW_FALLBACK = "true" }

Write-Host "Iniciando microservicio facial en http://127.0.0.1:$FacialPort ..." -ForegroundColor Cyan
Start-Process -FilePath $PythonExe -ArgumentList "-m","uvicorn","app:app","--host","127.0.0.1","--port","$FacialPort" -WorkingDirectory (Join-Path $Root "facial-service")

Write-Host "Iniciando servidor web PHP en http://127.0.0.1:$WebPort ..." -ForegroundColor Green
Write-Host "  Celular Escáner : http://127.0.0.1:$WebPort/index.php"
Write-Host "  Panel Admin     : http://127.0.0.1:$WebPort/admin.php"
& $PhpExe -S "127.0.0.1:$WebPort" -t (Join-Path $Root "backend\public")
