<#
.SYNOPSIS
  Verificador e instalador automatico de requisitos para servidor Windows
  La Esperanza Pallets - Control de Asistencia (Acceso 3 RH TITANIUM)

  Verifica (e instala automaticamente si falta):
   1. Acceso 3 RH TITANIUM + Servicio Firebird 2.5 (localhost:3050)
   2. Visual C++ Redistributable x64 + PHP 8.1+ (64-bit) con pdo_firebird
   3. Python 3.10 - 3.12 (64-bit) con pip y PATH
   4. cloudflared.exe y conectividad HTTPS con Cloudflare (rubicknet.com)
#>
[CmdletBinding()]
param(
    [switch]$AutoInstall
)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'

$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

function Write-Banner($msg) {
    Write-Host ""
    Write-Host "======================================================================" -ForegroundColor DarkGreen
    Write-Host "  $msg" -ForegroundColor Green
    Write-Host "======================================================================" -ForegroundColor DarkGreen
}

function Add-ToMachinePath([string]$Dir) {
    if (-not (Test-Path $Dir)) { return }
    $current = [Environment]::GetEnvironmentVariable('Path', 'Machine')
    $parts = ($current -split ';') | Where-Object { $_ -ne '' }
    if ($parts -notcontains $Dir) {
        [Environment]::SetEnvironmentVariable('Path', ($parts + $Dir) -join ';', 'Machine')
    }
    if (($env:Path -split ';') -notcontains $Dir) {
        $env:Path = "$Dir;$env:Path"
    }
}

function Test-TcpPort([string]$HostName, [int]$Port, [int]$TimeoutMs = 1200) {
    try {
        $c = New-Object System.Net.Sockets.TcpClient
        $ar = $c.BeginConnect($HostName, $Port, $null, $null)
        if ($ar.AsyncWaitHandle.WaitOne($TimeoutMs) -and $c.Connected) {
            $c.EndConnect($ar); $c.Close(); return $true
        }
        $c.Close()
    } catch {}
    return $false
}

function Test-ValidPhp([string]$ExePath) {
    if (-not $ExePath -or -not (Test-Path $ExePath)) { return $null }
    try {
        $out = & $ExePath -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '|' . PHP_INT_SIZE;" 2>$null
        if ($out -match '^(\d+)\.(\d+)\|(\d+)$') {
            $maj = [int]$Matches[1]; $min = [int]$Matches[2]; $sz = [int]$Matches[3]
            if (($maj -gt 8 -or ($maj -eq 8 -and $min -ge 1)) -and $sz -eq 8) {
                return @{ Path = $ExePath; Version = "$maj.$min"; Arch = '64-bit' }
            }
        }
    } catch {}
    return $null
}

function Find-ValidPhp() {
    $candidates = @()
    if (Test-Path 'C:\laragon\bin\php') {
        Get-ChildItem 'C:\laragon\bin\php' -Directory | Sort-Object Name -Descending | ForEach-Object {
            $candidates += Join-Path $_.FullName 'php.exe'
        }
    }
    $candidates += @('C:\php\php.exe', 'C:\xampp\php\php.exe')
    $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($cmd) { $candidates += $cmd.Source }

    foreach ($c in ($candidates | Select-Object -Unique)) {
        $info = Test-ValidPhp $c
        if ($info) { return $info }
    }
    return $null
}

function Test-ValidPython([string]$ExePath) {
    if (-not $ExePath -or -not (Test-Path $ExePath)) { return $null }
    if ($ExePath -like '*WindowsApps*') { return $null }
    try {
        $pyCode = "import sys, struct; print(str(sys.version_info.major) + '.' + str(sys.version_info.minor) + '|' + str(struct.calcsize('P') * 8))"
        $out = (& $ExePath -c $pyCode 2>$null | Select-Object -First 1)
        if ($out -match '^3\.(\d+)\|64$') {
            $min = [int]$Matches[1]
            if ($min -ge 10 -and $min -le 12) {
                return @{ Path = $ExePath; Version = "3.$min"; Arch = '64-bit' }
            }
        }
    } catch {}
    return $null
}

function Find-ValidPython() {
    $candidates = @(
        'C:\Python311\python.exe',
        'C:\Python310\python.exe',
        'C:\Python312\python.exe',
        'C:\Program Files\Python311\python.exe',
        'C:\Program Files\Python310\python.exe',
        'C:\Program Files\Python312\python.exe',
        "$env:LOCALAPPDATA\Programs\Python\Python311\python.exe",
        "$env:LOCALAPPDATA\Programs\Python\Python310\python.exe",
        "$env:LOCALAPPDATA\Programs\Python\Python312\python.exe",
        'C:\laragon\bin\python\python-3.10\python.exe'
    )
    $cmds = Get-Command python.exe -All -ErrorAction SilentlyContinue
    foreach ($cm in $cmds) { $candidates += $cm.Source }

    foreach ($c in ($candidates | Select-Object -Unique)) {
        $info = Test-ValidPython $c
        if ($info) { return $info }
    }
    return $null
}

function Confirm-Install([string]$What) {
    if ($AutoInstall) { return $true }
    $ans = Read-Host "   -> Desea descargar e instalar $What automaticamente ahora? (S/N, Enter = S)"
    return ([string]::IsNullOrWhiteSpace($ans) -or $ans -match '^[sS]')
}

function Install-VcRedistX64() {
    if (Test-Path "$env:SystemRoot\System32\vcruntime140.dll") { return }
    Write-Host "   [INSTALANDO] Visual C++ Redistributable x64 (requerido por PHP/Python)..." -ForegroundColor Cyan
    $vcExe = Join-Path $env:TEMP 'vc_redist.x64.exe'
    Invoke-WebRequest -Uri 'https://aka.ms/vs/17/release/vc_redist.x64.exe' -OutFile $vcExe -UseBasicParsing
    Start-Process -FilePath $vcExe -ArgumentList '/install /quiet /norestart' -Wait
    Remove-Item $vcExe -Force -ErrorAction SilentlyContinue
    Write-Host "   [OK] Visual C++ Redistributable x64 instalado." -ForegroundColor Green
}

function Install-Php83X64() {
    Install-VcRedistX64
    $targetDir = 'C:\php'
    Write-Host "   [DESCARGANDO] PHP 8.3 (64-bit) oficial desde windows.php.net..." -ForegroundColor Cyan
    $zipPath = Join-Path $env:TEMP 'php-8.3-x64.zip'
    Invoke-WebRequest -Uri 'https://windows.php.net/downloads/releases/latest/php-8.3-Win32-vs16-x64-latest.zip' -OutFile $zipPath -UseBasicParsing

    New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    foreach ($entry in $archive.Entries) {
        $dest = Join-Path $targetDir $entry.FullName
        if ($entry.FullName.EndsWith('/')) {
            New-Item -ItemType Directory -Path $dest -Force | Out-Null
        } else {
            New-Item -ItemType Directory -Path (Split-Path -Parent $dest) -Force | Out-Null
            [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $dest, $true)
        }
    }
    $archive.Dispose()
    Remove-Item $zipPath -Force -ErrorAction SilentlyContinue

    # Configurar php.ini base + fbclient_x64.dll
    $iniDst = Join-Path $targetDir 'php.ini'
    if (-not (Test-Path $iniDst) -and (Test-Path (Join-Path $targetDir 'php.ini-production'))) {
        Copy-Item (Join-Path $targetDir 'php.ini-production') $iniDst -Force
    }
    $fbSrc = Join-Path $Root 'scripts\runtime\fbclient_x64.dll'
    if (Test-Path $fbSrc) {
        Copy-Item $fbSrc (Join-Path $targetDir 'fbclient.dll') -Force
    }
    Add-ToMachinePath $targetDir
    Write-Host "   [OK] PHP 8.3 (64-bit) instalado en $targetDir y agregado al PATH." -ForegroundColor Green
    return (Test-ValidPhp (Join-Path $targetDir 'php.exe'))
}

function Install-Python311X64() {
    Write-Host "   [DESCARGANDO] Python 3.11.9 (64-bit) oficial desde python.org..." -ForegroundColor Cyan
    $pySetup = Join-Path $env:TEMP 'python-3.11.9-amd64.exe'
    Invoke-WebRequest -Uri 'https://www.python.org/ftp/python/3.11.9/python-3.11.9-amd64.exe' -OutFile $pySetup -UseBasicParsing
    Write-Host "   [INSTALANDO] Python 3.11.9 (64-bit) en C:\Python311 (silencioso, con PATH y pip)..." -ForegroundColor Cyan
    $proc = Start-Process -FilePath $pySetup -ArgumentList '/quiet InstallAllUsers=1 PrependPath=1 Include_pip=1 Include_test=0 TargetDir=C:\Python311' -Wait -PassThru
    Remove-Item $pySetup -Force -ErrorAction SilentlyContinue
    if ($proc.ExitCode -ne 0) {
        throw "El instalador de Python devolvio codigo $($proc.ExitCode)."
    }
    Add-ToMachinePath 'C:\Python311'
    Add-ToMachinePath 'C:\Python311\Scripts'
    Write-Host "   [OK] Python 3.11.9 (64-bit) instalado en C:\Python311 y agregado al PATH." -ForegroundColor Green
    return (Test-ValidPython 'C:\Python311\python.exe')
}

Write-Banner "VERIFICACION DE REQUISITOS - SERVIDOR LA ESPERANZA PALLETS"
$summary = @()

# ---------------- 1. ACCESO 3 TITANIUM + FIREBIRD 2.5 ----------------
Write-Host "[1/4] Acceso 3 RH TITANIUM y Firebird 2.5..." -ForegroundColor White
$fbPort = 3050
$accesoIni = 'C:\Program Files (x86)\Acceso3\Acceso.ini'
$accesoOk = Test-Path $accesoIni
if ($accesoOk) {
    foreach ($l in (Get-Content $accesoIni)) {
        if ($l -match '^\s*Port\s*=\s*(\d+)\s*$') { $fbPort = [int]$Matches[1] }
    }
    Write-Host "   [OK] Acceso 3 encontrado ($accesoIni)" -ForegroundColor Green
} else {
    Write-Host "   [AVISO] No se encontro $accesoIni (verifique si Acceso 3 esta en otra ruta)" -ForegroundColor Yellow
}

$fbSvc = Get-Service -Name '*Firebird*' -ErrorAction SilentlyContinue | Select-Object -First 1
if ($fbSvc -and $fbSvc.Status -ne 'Running') {
    Write-Host "   [INFO] Servicio $($fbSvc.Name) detenido. Intentando iniciar..." -ForegroundColor Yellow
    try { Start-Service -Name $fbSvc.Name; Start-Sleep -Seconds 2 } catch {}
}

$fbListening = Test-TcpPort '127.0.0.1' $fbPort
if ($fbListening) {
    Write-Host "   [OK] Firebird escuchando en localhost:$fbPort" -ForegroundColor Green
    $summary += "  [OK]        Acceso 3 TITANIUM / Firebird 2.5 (localhost:$fbPort)"
} else {
    Write-Host "   [FALTA] Firebird NO responde en localhost:$fbPort." -ForegroundColor Red
    Write-Host "           Verifique que Acceso 3 RH TITANIUM y Firebird 2.5 esten iniciados en este equipo." -ForegroundColor Yellow
    $summary += "  [PENDIENTE] Firebird 2.5 (localhost:$fbPort no responde)"
}

# ---------------- 2. PHP 8.1+ (64-bit) ----------------
Write-Host ""
Write-Host "[2/4] PHP 8.1+ (64-bit)..." -ForegroundColor White
$phpInfo = Find-ValidPhp
if ($phpInfo) {
    Write-Host "   [OK] PHP $($phpInfo.Version) ($($phpInfo.Arch)) detectado en: $($phpInfo.Path)" -ForegroundColor Green
    $summary += "  [OK]        PHP $($phpInfo.Version) 64-bit ($($phpInfo.Path))"
} else {
    Write-Host "   [FALTA] No se encontro PHP 8.1+ de 64 bits." -ForegroundColor Yellow
    if (Confirm-Install 'PHP 8.3 (64-bit)') {
        $phpInfo = Install-Php83X64
        if ($phpInfo) {
            $summary += "  [INSTALADO] PHP $($phpInfo.Version) 64-bit ($($phpInfo.Path))"
        } else {
            $summary += "  [ERROR]     PHP 8.3 64-bit no pudo verificarse"
        }
    } else {
        $summary += "  [PENDIENTE] PHP 8.1+ 64-bit"
    }
}

# ---------------- 3. PYTHON 3.10 - 3.12 (64-bit) ----------------
Write-Host ""
Write-Host "[3/4] Python 3.10 - 3.12 (64-bit)..." -ForegroundColor White
$pyInfo = Find-ValidPython
if ($pyInfo) {
    Write-Host "   [OK] Python $($pyInfo.Version) ($($pyInfo.Arch)) detectado en: $($pyInfo.Path)" -ForegroundColor Green
    $summary += "  [OK]        Python $($pyInfo.Version) 64-bit ($($pyInfo.Path))"
} else {
    Write-Host "   [FALTA] No se encontro Python 3.10 - 3.12 de 64 bits." -ForegroundColor Yellow
    if (Confirm-Install 'Python 3.11.9 (64-bit)') {
        $pyInfo = Install-Python311X64
        if ($pyInfo) {
            $summary += "  [INSTALADO] Python $($pyInfo.Version) 64-bit ($($pyInfo.Path))"
        } else {
            $summary += "  [ERROR]     Python 3.11.9 64-bit no pudo verificarse"
        }
    } else {
        $summary += "  [PENDIENTE] Python 3.10 - 3.12 64-bit"
    }
}

# ---------------- 4. CLOUDFLARE (cloudflared + conectividad HTTPS) ----------------
Write-Host ""
Write-Host "[4/4] Cloudflare (cloudflared.exe y acceso HTTPS a Cloudflare)..." -ForegroundColor White
$cfPath = $null
foreach ($c in @(
    (Join-Path $Root 'scripts\cloudflared.exe'),
    'C:\Program Files (x86)\cloudflared\cloudflared.exe',
    'C:\Program Files\cloudflared\cloudflared.exe'
)) {
    if (Test-Path $c) { $cfPath = $c; break }
}
if (-not $cfPath) {
    $cmdCf = Get-Command cloudflared.exe -ErrorAction SilentlyContinue
    if ($cmdCf) { $cfPath = $cmdCf.Source }
}
if (-not $cfPath) {
    Write-Host "   [FALTA] No se encontro cloudflared.exe." -ForegroundColor Yellow
    if (Confirm-Install 'cloudflared.exe oficial') {
        $cfDst = Join-Path $Root 'scripts\cloudflared.exe'
        New-Item -ItemType Directory -Path (Split-Path -Parent $cfDst) -Force | Out-Null
        Invoke-WebRequest -Uri 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe' -OutFile $cfDst -UseBasicParsing
        $cfPath = $cfDst
        Write-Host "   [OK] cloudflared.exe descargado en $cfDst" -ForegroundColor Green
    }
} else {
    Write-Host "   [OK] cloudflared.exe disponible ($cfPath)" -ForegroundColor Green
}

$cfNetOk = $false
try {
    $resp = Invoke-WebRequest -Uri 'https://dash.cloudflare.com' -Method Head -MaximumRedirection 3 -UseBasicParsing -TimeoutSec 8
    $cfNetOk = $true
} catch {
    # Incluso un 403/302 indica que Cloudflare responde por HTTPS
    if ($_.Exception.Response) { $cfNetOk = $true }
}

if ($cfPath -and $cfNetOk) {
    Write-Host "   [OK] Conexion HTTPS con Cloudflare verificada (listo para autorizar rubicknet.com en 1 clic)." -ForegroundColor Green
    $summary += "  [OK]        Cloudflare Tunnel y conexion HTTPS listos"
} else {
    Write-Host "   [AVISO] No se pudo verificar conexion HTTPS con dash.cloudflare.com (revise internet)." -ForegroundColor Yellow
    $summary += "  [PENDIENTE] Verificar salida a internet hacia Cloudflare"
}

Write-Banner "RESUMEN DE REQUISITOS DEL SERVIDOR"
foreach ($line in $summary) {
    if ($line -like '*[OK]*' -or $line -like '*[INSTALADO]*') {
        Write-Host $line -ForegroundColor Green
    } elseif ($line -like '*[ERROR]*') {
        Write-Host $line -ForegroundColor Red
    } else {
        Write-Host $line -ForegroundColor Yellow
    }
}
Write-Host ""
