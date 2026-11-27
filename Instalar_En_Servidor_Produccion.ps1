<#
.SYNOPSIS
  Instalador oficial de produccion - La Esperanza Pallets (Acceso 3 RH TITANIUM)
  Automatiza PHP + pdo_firebird, Python ONNX, claves criptograficas nuevas,
  Cloudflare Tunnel (dominio fijo rubicknet.com sin tocar otros tuneles) y arranque 24/7.
#>
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

function Write-Title($msg) {
    Write-Host ""
    Write-Host "======================================================================" -ForegroundColor DarkGreen
    Write-Host "  $msg" -ForegroundColor Green
    Write-Host "======================================================================" -ForegroundColor DarkGreen
}

function New-RandomHex([int]$Bytes = 32) {
    $buf = New-Object byte[] $Bytes
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($buf)
    $rng.Dispose()
    return ([BitConverter]::ToString($buf) -replace '-', '').ToLowerInvariant()
}

function Find-Exe([string[]]$Candidates, [string]$CmdName) {
    foreach ($c in $Candidates) {
        if (Test-Path $c) { return (Resolve-Path $c).Path }
    }
    $found = Get-Command $CmdName -ErrorAction SilentlyContinue
    if ($found) { return $found.Source }
    return $null
}

Write-Title "INSTALADOR OFICIAL DE PRODUCCION - LA ESPERANZA PALLETS"
Write-Host "Carpeta del sistema: $Root" -ForegroundColor Gray

# 0. Verificar e instalar automaticamente requisitos faltantes (PHP 8.3 x64, Python 3.11 x64, VC++, Firebird, Cloudflare)
$PrereqScript = Join-Path $Root 'scripts\Verificar_E_Instalar_Requisitos.ps1'
if (Test-Path $PrereqScript) {
    & $PrereqScript
    # Refrescar PATH en esta sesion por si se acaban de instalar PHP o Python
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
}

# 1. Detectar PHP 8.x y Python 3.10+
$phpCandidates = @(
    'C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe',
    'C:\laragon\bin\php\php-8.2.20-Win32-vs16-x64\php.exe',
    'C:\laragon\bin\php\php-8.1.10-Win32-vs16-x64\php.exe',
    'C:\php\php.exe',
    'C:\xampp\php\php.exe'
)
if (Test-Path 'C:\laragon\bin\php') {
    Get-ChildItem 'C:\laragon\bin\php' -Directory | Sort-Object Name -Descending | ForEach-Object {
        $phpCandidates = @((Join-Path $_.FullName 'php.exe')) + $phpCandidates
    }
}
$PhpExe = Find-Exe $phpCandidates 'php.exe'
if (-not $PhpExe) {
    Write-Host "[ERROR] No se encontro PHP 8.1+ en el equipo." -ForegroundColor Red
    Write-Host "Instale Laragon o PHP 8.3 x64 (C:\php) y vuelva a ejecutar este instalador." -ForegroundColor Yellow
    exit 1
}
Write-Host "[OK] PHP detectado    : $PhpExe" -ForegroundColor Green

$pyCandidates = @(
    'C:\laragon\bin\python\python-3.10\python.exe',
    'C:\Python311\python.exe',
    'C:\Python310\python.exe',
    'C:\Python312\python.exe',
    "$env:LOCALAPPDATA\Programs\Python\Python311\python.exe",
    "$env:LOCALAPPDATA\Programs\Python\Python310\python.exe",
    "$env:LOCALAPPDATA\Programs\Python\Python312\python.exe"
)
$PyExe = Find-Exe $pyCandidates 'python.exe'
if (-not $PyExe) {
    Write-Host "[ERROR] No se encontro Python 3.10 - 3.12 en el equipo." -ForegroundColor Red
    Write-Host "Instale Python 3.10 o 3.11 (64-bit) marcando 'Add Python to PATH' y vuelva a ejecutar." -ForegroundColor Yellow
    exit 1
}
Write-Host "[OK] Python detectado : $PyExe" -ForegroundColor Green

$CfExe = Find-Exe @(
    (Join-Path $Root 'scripts\cloudflared.exe'),
    'C:\Program Files (x86)\cloudflared\cloudflared.exe',
    'C:\Program Files\cloudflared\cloudflared.exe'
) 'cloudflared.exe'
if ($CfExe) {
    Write-Host "[OK] cloudflared      : $CfExe" -ForegroundColor Green
}

# 2. Instalar fbclient_x64.dll junto a php.exe y habilitar extensiones
Write-Title "PASO 1/6 - CONFIGURACION DE PHP Y FIREBIRD (pdo_firebird)"
$PhpDir = Split-Path -Parent $PhpExe
$FbSrc = Join-Path $Root 'scripts\runtime\fbclient_x64.dll'
$FbDst = Join-Path $PhpDir 'fbclient.dll'
if ((Test-Path $FbSrc) -and -not (Test-Path $FbDst)) {
    Copy-Item $FbSrc $FbDst -Force
    Write-Host "[OK] Se copio fbclient.dll (64-bit) en $PhpDir" -ForegroundColor Green
} elseif (Test-Path $FbDst) {
    Write-Host "[OK] fbclient.dll ya existe en $PhpDir" -ForegroundColor Green
}

$PhpIni = Join-Path $PhpDir 'php.ini'
if (-not (Test-Path $PhpIni) -and (Test-Path (Join-Path $PhpDir 'php.ini-production'))) {
    Copy-Item (Join-Path $PhpDir 'php.ini-production') $PhpIni -Force
}
if (Test-Path $PhpIni) {
    $ini = Get-Content $PhpIni -Raw
    $exts = @('curl', 'gd', 'mbstring', 'openssl', 'pdo_firebird', 'pdo_sqlite')
    $changed = $false
    foreach ($ext in $exts) {
        if ($ini -match "(?m)^\s*;\s*extension\s*=\s*$ext\b") {
            $ini = [regex]::Replace($ini, "(?m)^\s*;\s*extension\s*=\s*$ext\b", "extension=$ext")
            $changed = $true
        } elseif ($ini -notmatch "(?m)^\s*extension\s*=\s*$ext\b") {
            $ini += "`r`nextension=$ext"
            $changed = $true
        }
    }
    if ($ini -match '(?m)^\s*;\s*extension_dir\s*=\s*"ext"') {
        $ini = [regex]::Replace($ini, '(?m)^\s*;\s*extension_dir\s*=\s*"ext"', 'extension_dir = "ext"')
        $changed = $true
    }
    if ($changed) {
        Set-Content -Path $PhpIni -Value $ini -Encoding ASCII
        Write-Host "[OK] Extensiones PHP habilitadas en $PhpIni" -ForegroundColor Green
    } else {
        Write-Host "[OK] php.ini ya tiene las extensiones requeridas" -ForegroundColor Green
    }
}

$mods = & $PhpExe -m
if ($mods -notcontains 'pdo_firebird') {
    Write-Host "[AVISO] PHP aun no reporta 'pdo_firebird'. Verifique que PHP sea 64-bit y revise $PhpIni." -ForegroundColor Yellow
} else {
    Write-Host "[OK] Extension pdo_firebird cargada correctamente" -ForegroundColor Green
}

# 3. Datos de produccion y auto-deteccion de Acceso.ini (compatible con TITANIUM con licencia)
Write-Title "PASO 2/6 - DATOS DE PRODUCCION Y ACCESO 3 TITANIUM"
$DefaultFdb = 'C:\Program Files (x86)\Acceso3\DB\ACCESODB.FDB'
$DefaultFbPort = '3050'
$DefaultFbUser = 'sysdba'
$AccesoIni = 'C:\Program Files (x86)\Acceso3\Acceso.ini'
if (Test-Path $AccesoIni) {
    $iniEngine = ''
    foreach ($l in (Get-Content $AccesoIni)) {
        if ($l -match '^\s*DataBase\s*=\s*(Firebird|SQLServer|MSSQL)\s*$') { $iniEngine = $Matches[1].Trim() }
        if ($l -match '^\s*DataBase\s*=\s*(.+\.fdb)\s*$') { $DefaultFdb = $Matches[1].Trim() }
        if ($l -match '^\s*Port\s*=\s*(\d+)\s*$') { $DefaultFbPort = $Matches[1].Trim() }
        if ($l -match '^\s*UserName\s*=\s*(\S+)\s*$') { $DefaultFbUser = $Matches[1].Trim() }
    }
    if ($iniEngine -match 'SQL') {
        Write-Host "[AVISO] Acceso.ini indica motor '$iniEngine' (no Firebird). Revise si su TITANIUM usa SQL Server." -ForegroundColor Yellow
    } else {
        Write-Host "[INFO] Acceso.ini detectado -> Motor: Firebird | Puerto: $DefaultFbPort | Base: $DefaultFdb" -ForegroundColor Cyan
    }
}

$PublicHost = Read-Host "Dominio/subdominio HTTPS (ej. esperanza.rubicknet.com)"
if ([string]::IsNullOrWhiteSpace($PublicHost)) { $PublicHost = 'esperanza.rubicknet.com' }
$PublicHost = ($PublicHost.Trim() -replace '^https?://', '').TrimEnd('/')
$PublicUrl = "https://$PublicHost"

$WebPort = Read-Host "Puerto local PHP (evite chocar con otra app; Enter = 8085)"
if ([string]::IsNullOrWhiteSpace($WebPort)) { $WebPort = '8085' }

$FacialPort = Read-Host "Puerto local Motor Facial Python (Enter = 8001)"
if ([string]::IsNullOrWhiteSpace($FacialPort)) { $FacialPort = '8001' }

$FbPath = Read-Host "Ruta local del archivo .FDB de TITANIUM (Enter = $DefaultFdb)"
if ([string]::IsNullOrWhiteSpace($FbPath)) { $FbPath = $DefaultFdb }

$FbPort = Read-Host "Puerto Firebird (Enter = $DefaultFbPort)"
if ([string]::IsNullOrWhiteSpace($FbPort)) { $FbPort = $DefaultFbPort }

$FbUser = Read-Host "Usuario Firebird (Enter = $DefaultFbUser)"
if ([string]::IsNullOrWhiteSpace($FbUser)) { $FbUser = $DefaultFbUser }

$FbPass = Read-Host "Contrasena Firebird (Enter = masterkey)"
if ([string]::IsNullOrWhiteSpace($FbPass)) { $FbPass = 'masterkey' }

$AdminUser = Read-Host "Usuario Administrador del Panel Web (Enter = admin)"
if ([string]::IsNullOrWhiteSpace($AdminUser)) { $AdminUser = 'admin' }

do {
    $AdminPass = Read-Host "Contrasena NUEVA de Administrador (minimo 10 caracteres)"
} while ($AdminPass.Length -lt 10)

# 4. Configuracion automatica de Cloudflare Tunnel
Write-Title "PASO 3/6 - CONFIGURACION AUTOMATICA DE CLOUDFLARE TUNNEL"
Write-Host "Seleccione como desea publicar $PublicUrl por HTTPS:" -ForegroundColor White
Write-Host "  [1] Automatico completo (Recomendado): crea tunel dedicado + DNS automatico" -ForegroundColor Cyan
Write-Host "      para $PublicHost sin tocar su otro tunel (checador.rubicknet.com)." -ForegroundColor Gray
Write-Host "  [2] Pegar Token de Cloudflare Tunnel (si ya creo el tunel en Zero Trust)." -ForegroundColor White
Write-Host "  [3] Ya voy a agregar el Public Hostname manualmente en mi tunel actual." -ForegroundColor White
$cfChoice = Read-Host "Elija opcion (1, 2 o 3; Enter = 1)"
if ([string]::IsNullOrWhiteSpace($cfChoice)) { $cfChoice = '1' }

$CfMode = 'external'
$CfConfigRel = ''
$CfToken = ''
$CfConfigFull = ''

if ($cfChoice -eq '1') {
    if (-not $CfExe) {
        Write-Host "[ERROR] No se encontro cloudflared.exe para configuracion automatica." -ForegroundColor Red
        $CfMode = 'external'
    } else {
        $CfDir = Join-Path $Root 'cloudflared'
        New-Item -ItemType Directory -Path $CfDir -Force | Out-Null

        Write-Host ""
        Write-Host "-> Paso A: Se abrira su navegador para iniciar sesion en Cloudflare." -ForegroundColor Yellow
        Write-Host "   Seleccione el dominio (ej. rubicknet.com) y haga clic en 'Authorize'." -ForegroundColor Yellow
        $UserCert = Join-Path $env:USERPROFILE '.cloudflared\cert.pem'
        if (-not (Test-Path $UserCert)) {
            & $CfExe tunnel login
        } else {
            $relogin = Read-Host "Ya existe un cert.pem de Cloudflare en este equipo. Reutilizarlo? (S/N, Enter = S)"
            if ($relogin -match '^[nN]') {
                Remove-Item $UserCert -Force -ErrorAction SilentlyContinue
                & $CfExe tunnel login
            }
        }

        if (-not (Test-Path $UserCert)) {
            throw "No se completo la autorizacion de Cloudflare (no se genero cert.pem)."
        }

        $TunnelName = 'esperanza-pallets-' + ($PublicHost -replace '[^a-zA-Z0-9-]', '-').ToLowerInvariant()
        if ($TunnelName.Length -gt 48) { $TunnelName = $TunnelName.Substring(0, 48).TrimEnd('-') }

        Write-Host "-> Paso B: Creando tunel dedicado '$TunnelName'..." -ForegroundColor Cyan
        $CredPath = Join-Path $CfDir "$TunnelName.json"
        & $CfExe tunnel --origincert $UserCert create --credentials-file $CredPath $TunnelName
        if ($LASTEXITCODE -ne 0 -or -not (Test-Path $CredPath)) {
            Write-Host "[AVISO] El tunel ya existia o no pudo crearse nuevo; intentando buscar credenciales existentes..." -ForegroundColor Yellow
            $existingJson = Get-ChildItem (Join-Path $env:USERPROFILE '.cloudflared') -Filter '*.json' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
            if ($existingJson) {
                Copy-Item $existingJson.FullName $CredPath -Force
            } else {
                throw "No se encontro archivo de credenciales del tunel Cloudflare."
            }
        }

        $CredObj = Get-Content $CredPath -Raw | ConvertFrom-Json
        $TunnelId = $CredObj.TunnelID
        Write-Host "[OK] Tunel creado: $TunnelName ($TunnelId)" -ForegroundColor Green

        Write-Host "-> Paso C: Creando/actualizando registro DNS para $PublicHost..." -ForegroundColor Cyan
        & $CfExe tunnel --origincert $UserCert route dns --overwrite-dns $TunnelName $PublicHost
        if ($LASTEXITCODE -ne 0) {
            Write-Host "[AVISO] Revise el registro DNS CNAME de $PublicHost en Cloudflare." -ForegroundColor Yellow
        } else {
            Write-Host "[OK] DNS configurado: $PublicHost -> $TunnelId.cfargotunnel.com" -ForegroundColor Green
        }

        $CfConfigFull = Join-Path $CfDir 'config.yml'
        $CfConfigRel = 'cloudflared\config.yml'
        $Yaml = @"
tunnel: $TunnelId
credentials-file: $CredPath

ingress:
  - hostname: $PublicHost
    service: http://127.0.0.1:$WebPort
  - service: http_status:404
"@
        Set-Content -Path $CfConfigFull -Value $Yaml -Encoding UTF8
        $CfMode = 'named_config'
        Write-Host "[OK] Configuracion guardada en $CfConfigFull" -ForegroundColor Green
    }
} elseif ($cfChoice -eq '2') {
    $CfToken = (Read-Host "Pegue el Token del tunel de Cloudflare Zero Trust").Trim()
    if (-not [string]::IsNullOrWhiteSpace($CfToken)) {
        $CfMode = 'named_token'
        Write-Host "[OK] Token de Cloudflare guardado para arranque automatico." -ForegroundColor Green
    }
} else {
    $CfMode = 'external'
    Write-Host "[OK] Se usara su tunel existente. Recuerde apuntar $PublicHost -> http://127.0.0.1:$WebPort." -ForegroundColor Cyan
}

# Generar llaves criptograficas unicas de produccion y escribir .env
$EncKey = New-RandomHex 32
$FacialToken = New-RandomHex 32

$EnvContent = @"
APP_ENV=production
APP_TIMEZONE=America/Mexico_City
APP_BASE_URL=$PublicUrl
APP_TRUST_PROXY=true
WEB_PORT=$WebPort
FACIAL_PORT=$FacialPort
ENABLE_QUICK_TUNNEL=false
CLOUDFLARE_MODE=$CfMode
CLOUDFLARE_CONFIG=$CfConfigRel
CLOUDFLARE_TUNNEL_TOKEN=$CfToken

APP_ENCRYPTION_KEY=$EncKey

ADMIN_DEFAULT_USER=$AdminUser
ADMIN_DEFAULT_PASS=$AdminPass

DB_DRIVER=sqlite
DB_SQLITE_PATH=database/checador_local.sqlite

TITANIUM_MODE=firebird
TITANIUM_FB_HOST=localhost
TITANIUM_FB_PORT=$FbPort
TITANIUM_FB_DATABASE=$FbPath
TITANIUM_FB_USER=$FbUser
TITANIUM_FB_PASS=$FbPass
TITANIUM_FB_CHARSET=UTF8

TITANIUM_TBL_EMPLEADOS=EMPLEADOS
TITANIUM_COL_EMP_ID=CLAVEEMPLEADO
TITANIUM_COL_EMP_NOMBRE=NOMBRE
TITANIUM_COL_EMP_AP_PAT=APELLIDOPATERNO
TITANIUM_COL_EMP_AP_MAT=APELLIDOMATERNO
TITANIUM_COL_EMP_ESTATUS=STATUS
TITANIUM_COL_EMP_DEPTO=CLAVEDEPARTAMENTO
TITANIUM_COL_EMP_SUCURSAL=CLAVESUCURSAL
TITANIUM_VAL_ESTATUS_ALTA=Alta
TITANIUM_VAL_ESTATUS_ALTA_INT=0
TITANIUM_TBL_CHECADAS=CHECADAS
TITANIUM_VAL_ESTACION=0
TITANIUM_VAL_ORIGEN_MOVIL=MOVIL

FACIAL_SERVICE_URL=http://127.0.0.1:$FacialPort
FACIAL_SERVICE_TOKEN=$FacialToken
FACIAL_TIMEOUT_SECONDS=12
FACIAL_THRESHOLD_1_1=0.45
FACIAL_THRESHOLD_1_N=0.48
FACIAL_MARGIN_1_N=0.08
FACIAL_MODEL_VERSION=buffalo_l_r100

NONCE_TTL_SECONDS=90
DEBOUNCE_MINUTES=3
RATE_LIMIT_MAX_FAILS=8
RATE_LIMIT_WINDOW_MINUTES=10
DEFAULT_MAX_GPS_ACCURACY=2500
"@

Set-Content -Path (Join-Path $Root '.env') -Value $EnvContent -Encoding UTF8
Write-Host "[OK] Archivo .env de produccion generado con llaves criptograficas unicas." -ForegroundColor Green

$SqlitePath = Join-Path $Root 'database\checador_local.sqlite'
if (Test-Path $SqlitePath) {
    $wipe = Read-Host "Existe una base SQLite previa. Desea reiniciarla limpia para produccion? (S/N, Enter = N)"
    if ($wipe -match '^[sS]') {
        Remove-Item "$SqlitePath*" -Force -ErrorAction SilentlyContinue
        Write-Host "[OK] Base SQLite local reiniciada limpia." -ForegroundColor Green
    }
}

# 5. Crear entorno virtual Python ONNX e instalar dependencias
Write-Title "PASO 4/6 - MOTOR FACIAL PYTHON ONNX"
$FacialDir = Join-Path $Root 'facial-service'
$VenvDir = Join-Path $FacialDir '.venv'
$VenvPy = Join-Path $VenvDir 'Scripts\python.exe'
if (-not (Test-Path $VenvPy)) {
    Write-Host "Creando entorno virtual Python..."
    & $PyExe -m venv $VenvDir
}
Write-Host "Instalando paquetes Python precompilados (FastAPI, OpenCV, ONNX Runtime)..."
& $VenvPy -m pip install --upgrade pip --quiet
& $VenvPy -m pip install -r (Join-Path $FacialDir 'requirements.txt')
Write-Host "[OK] Motor Facial Python listo." -ForegroundColor Green

# 6. Probar conexion real a Firebird 2.5 (Acceso 3 TITANIUM)
Write-Title "PASO 5/6 - VERIFICACION EN VIVO CONTRA ACCESO 3 TITANIUM"
$VerifyScript = Join-Path $Root 'scripts\verify_server_connection.php'
@"
<?php
require_once dirname(__DIR__) . '/backend/config/config.php';
require_once dirname(__DIR__) . '/backend/src/Database.php';
require_once dirname(__DIR__) . '/backend/src/TitaniumConnector.php';
`$cfg = AppConfig::load();
`$db = new Database(`$cfg['db'], `$cfg['admin']);
`$t = new TitaniumConnector(`$cfg['titanium'], `$db->pdo());
`$h = `$t->healthCheck();
if (!empty(`$h['ok'])) {
    echo "[OK] Conectado a TITANIUM: " . `$h['database'] . " | Empleados: " . `$h['employees_count'] . PHP_EOL;
    exit(0);
}
echo "[ERROR] No se pudo conectar a TITANIUM: " . (`$h['error'] ?? 'desconocido') . PHP_EOL;
exit(1);
"@ | Set-Content -Path $VerifyScript -Encoding UTF8

& $PhpExe $VerifyScript

# 7. Registrar inicio automatico 24/7 al arrancar Windows (incluyendo Cloudflare Tunnel)
Write-Title "PASO 6/6 - INICIO AUTOMATICO 24/7 EN WINDOWS"
$installTasks = Read-Host "Desea registrar el arranque automatico 24/7 al encender Windows (PHP + Facial + Cloudflare)? (S/N, Enter = S)"
if ([string]::IsNullOrWhiteSpace($installTasks) -or $installTasks -match '^[sS]') {
    $LogsDir = Join-Path $Root 'logs'
    New-Item -ItemType Directory -Path $LogsDir -Force | Out-Null

    $trigBoot = New-ScheduledTaskTrigger -AtStartup
    $setLong = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1)

    # Tarea 1: Motor Facial Python ONNX
    $actFacial = New-ScheduledTaskAction -Execute $VenvPy -Argument "-m uvicorn app:app --host 127.0.0.1 --port $FacialPort" -WorkingDirectory $FacialDir
    Register-ScheduledTask -TaskName 'EsperanzaPallets_FacialONNX' -Action $actFacial -Trigger $trigBoot -Settings $setLong -User 'SYSTEM' -RunLevel Highest -Force | Out-Null

    # Tarea 2: Servidor Web PHP
    $PubDir = Join-Path $Root 'backend\public'
    $actWeb = New-ScheduledTaskAction -Execute $PhpExe -Argument "-S 127.0.0.1:$WebPort -t `"$PubDir`"" -WorkingDirectory $Root
    Register-ScheduledTask -TaskName 'EsperanzaPallets_WebPHP' -Action $actWeb -Trigger $trigBoot -Settings $setLong -User 'SYSTEM' -RunLevel Highest -Force | Out-Null

    # Tarea 3: Reintento automatico de cola hacia TITANIUM cada 5 minutos
    $SyncPhp = Join-Path $Root 'scripts\sync_titanium_queue.php'
    $actSync = New-ScheduledTaskAction -Execute $PhpExe -Argument "`"$SyncPhp`"" -WorkingDirectory $Root
    $trigSync = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration ([TimeSpan]::FromDays(3650))
    $setShort = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 4)
    Register-ScheduledTask -TaskName 'EsperanzaPallets_SyncTitanium' -Action $actSync -Trigger $trigSync -Settings $setShort -User 'SYSTEM' -RunLevel Highest -Force | Out-Null

    Start-ScheduledTask -TaskName 'EsperanzaPallets_FacialONNX'
    Start-ScheduledTask -TaskName 'EsperanzaPallets_WebPHP'

    # Tarea 4: Cloudflare Tunnel dedicado (si se eligio opcion 1 o 2)
    if ($CfExe -and ($CfMode -eq 'named_config' -or $CfMode -eq 'named_token')) {
        $cfArgs = if ($CfMode -eq 'named_config') {
            "tunnel --no-autoupdate --config `"$CfConfigFull`" run"
        } else {
            "tunnel --no-autoupdate run --token $CfToken"
        }
        $actCf = New-ScheduledTaskAction -Execute $CfExe -Argument $cfArgs -WorkingDirectory $Root
        Register-ScheduledTask -TaskName 'EsperanzaPallets_Cloudflared' -Action $actCf -Trigger $trigBoot -Settings $setLong -User 'SYSTEM' -RunLevel Highest -Force | Out-Null
        Start-ScheduledTask -TaskName 'EsperanzaPallets_Cloudflared'
    }

    Write-Host "[OK] Servicios 24/7 registrados e iniciados en segundo plano:" -ForegroundColor Green
    Write-Host "     - EsperanzaPallets_FacialONNX   (127.0.0.1:$FacialPort)" -ForegroundColor Cyan
    Write-Host "     - EsperanzaPallets_WebPHP       (127.0.0.1:$WebPort)" -ForegroundColor Cyan
    Write-Host "     - EsperanzaPallets_SyncTitanium (cada 5 min)" -ForegroundColor Cyan
    if ($CfMode -eq 'named_config' -or $CfMode -eq 'named_token') {
        Write-Host "     - EsperanzaPallets_Cloudflared  ($PublicUrl)" -ForegroundColor Cyan
    }
}

Write-Title "INSTALACION COMPLETADA - SISTEMA EN PRODUCCION"
Write-Host "  Panel Admin : $PublicUrl/admin.php (usuario: $AdminUser)" -ForegroundColor Cyan
Write-Host "  Celulares   : $PublicUrl/index.php" -ForegroundColor Cyan
if ($CfMode -eq 'external') {
    Write-Host "  Pendiente   : Agregar en su tunel Cloudflare actual -> $PublicHost => http://127.0.0.1:$WebPort" -ForegroundColor Yellow
}
Write-Host ""
