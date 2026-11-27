# ==============================================================================
# Instalador del Microservicio Facial Nativo en Windows (100% ruedas binarias, sin C++)
# Descarga: OpenCV YuNet ONNX (232 KB) + InsightFace ArcFace ONNX w600k_mbf (~16 MB)
# ==============================================================================
param(
    [string]$PythonExe = "C:\laragon\bin\python\python-3.10\python.exe"
)

$ErrorActionPreference = "Stop"
$ServiceDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$VenvDir = Join-Path $ServiceDir ".venv"
$ModelsDir = Join-Path $ServiceDir "models"

if (-not (Test-Path $PythonExe)) {
    $cmd = Get-Command python -ErrorAction SilentlyContinue
    if ($cmd) { $PythonExe = $cmd.Source } else { throw "No se encontró Python 3.10+. Instale Python o especifique -PythonExe." }
}

if (-not (Test-Path $VenvDir)) {
    Write-Host "[1/4] Creando entorno virtual en $VenvDir..." -ForegroundColor Cyan
    & $PythonExe -m venv $VenvDir
} else {
    Write-Host "[1/4] Usando entorno virtual existente en $VenvDir..." -ForegroundColor Cyan
}

$VenvPython = Join-Path $VenvDir "Scripts\python.exe"

Write-Host "[2/4] Instalando FastAPI, OpenCV y ONNX Runtime (sin compilar C++)..." -ForegroundColor Cyan
& $VenvPython -m pip install --upgrade pip
& $VenvPython -m pip install -r (Join-Path $ServiceDir "requirements.txt")

Write-Host "[3/4] Descargando modelos ONNX oficiales (YuNet + InsightFace w600k_mbf)..." -ForegroundColor Cyan
if (-not (Test-Path $ModelsDir)) { New-Item -ItemType Directory -Path $ModelsDir | Out-Null }

$YunetFile = Join-Path $ModelsDir "face_detection_yunet_2023mar.onnx"
if (-not (Test-Path $YunetFile)) {
    Invoke-WebRequest -Uri "https://github.com/opencv/opencv_zoo/raw/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx" -OutFile $YunetFile -UseBasicParsing
}

$ArcFaceFile = Join-Path $ModelsDir "w600k_mbf.onnx"
if (-not (Test-Path $ArcFaceFile)) {
    $ZipFile = Join-Path $ModelsDir "buffalo_sc.zip"
    Invoke-WebRequest -Uri "https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_sc.zip" -OutFile $ZipFile -UseBasicParsing
    $TmpExtract = Join-Path $ModelsDir "_tmp_buffalo"
    Expand-Archive -Path $ZipFile -DestinationPath $TmpExtract -Force
    $Found = Get-ChildItem $TmpExtract -Recurse -Filter "w600k_mbf.onnx" | Select-Object -First 1
    if (-not $Found) { throw "No se encontró w600k_mbf.onnx dentro de buffalo_sc.zip." }
    Move-Item -Path $Found.FullName -Destination $ArcFaceFile -Force
    Remove-Item -Path $TmpExtract -Recurse -Force
    Remove-Item -Path $ZipFile -Force
}

Write-Host "[4/4] Verificando carga de ambos modelos ONNX en CPU..." -ForegroundColor Cyan
& $VenvPython -c "import cv2, onnxruntime as ort; d = cv2.FaceDetectorYN.create(r'$YunetFile', '', (640,640)); s = ort.InferenceSession(r'$ArcFaceFile', providers=['CPUExecutionProvider']); print('OK -> YuNet + InsightFace ArcFace ONNX listos:', s.get_inputs()[0].shape)"

Write-Host "`nInstalación completada con éxito." -ForegroundColor Green
Write-Host "Ahora ejecute desde la raíz del proyecto:" -ForegroundColor Yellow
Write-Host "  ..\scripts\start_local.ps1"
