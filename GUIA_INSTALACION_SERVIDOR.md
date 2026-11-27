# Guía Oficial de Instalación en Servidor de Producción
**La Esperanza Pallets · Control de Asistencia Móvil conectado a Acceso 3 RH TITANIUM**

---

## Requisitos en el servidor de Acceso 3 TITANIUM
1. **Acceso 3 RH TITANIUM** con servicio **Firebird 2.5** corriendo localmente (`localhost:3050`).
2. **Internet** y acceso a su cuenta de **Cloudflare** (`rubicknet.com`) para autorizar con 1 clic en el navegador.
3. **PHP 8.1+ (64-bit)**, **Visual C++ x64**, **Python 3.10 – 3.12 (64-bit)** y **`cloudflared.exe`**:
   - **No necesita instalarlos manualmente:** al ejecutar `Instalar_En_Servidor_Produccion.bat` (o solo `Verificar_Requisitos_Servidor.bat` si desea revisar antes), el sistema verifica cada requisito e **instala automáticamente lo que falte**.

---

## Paso 1: Copiar y ejecutar el instalador automático (todo incluido)
1. Copie **`checador-titanium-produccion.zip`** al servidor y descomprímalo en una ruta fija, por ejemplo:
   `C:\checador-titanium`
2. Haga doble clic en **`Instalar_En_Servidor_Produccion.bat`** (acepte permisos de Administrador).
3. El asistente configurará automáticamente todo:
   - **PHP + Firebird:** copia `fbclient.dll` (64-bit) y activa `pdo_firebird`, `pdo_sqlite`, `openssl`, `curl` y `gd`.
   - **Acceso 3 TITANIUM:** detecta automáticamente el `.FDB` desde `C:\Program Files (x86)\Acceso3\Acceso.ini`.
   - **Cloudflare Tunnel automático (3 opciones a elegir):**
     - **Opción 1 (Recomendada — 100% automática):** abre el navegador 1 vez para que autorices `rubicknet.com`, crea un túnel dedicado independiente (no toca ni interfiere con `checador.rubicknet.com`), crea el registro DNS automático del subdominio (ej. `esperanza.rubicknet.com`) y guarda `cloudflared\config.yml`.
     - **Opción 2 (Token):** pega un Token de Cloudflare Zero Trust si prefieres crear el túnel desde el panel web.
     - **Opción 3 (Túnel existente):** si prefieres agregar tú mismo la ruta `http://127.0.0.1:8085` en el túnel actual.
   - **Seguridad:** genera claves criptográficas nuevas (`APP_ENCRYPTION_KEY` y `FACIAL_SERVICE_TOKEN` de 256 bits) y solicita una contraseña robusta de Administrador.
   - **Motor Facial Python ONNX:** crea `.venv` e instala paquetes precompilados (sin compilador C++).
   - **Verificación en vivo:** se conecta a Firebird 2.5 y muestra el total real de empleados.
   - **Arranque automático 24/7 en Windows (`SYSTEM`):** registra e inicia:
     - `EsperanzaPallets_FacialONNX` (`127.0.0.1:8001`)
     - `EsperanzaPallets_WebPHP` (`127.0.0.1:8085`)
     - `EsperanzaPallets_Cloudflared` (`https://esperanza.rubicknet.com`)
     - `EsperanzaPallets_SyncTitanium` (reintento automático cada 5 min)

---

## Arranque manual opcional con el ejecutable
Si prefiere encenderlo manualmente en lugar de dejarlo 24/7 en segundo plano, **`Iniciar_Sistema_Esperanza_Pallets.exe`** lee automáticamente `CLOUDFLARE_MODE` de `.env` y levanta PHP + Python ONNX + su túnel fijo de Cloudflare (`esperanza.rubicknet.com`) desde una sola ventana.

---

## Seguridad y respaldo en producción
- **Puertos cerrados al exterior:** PHP (`127.0.0.1:8085`), Python (`127.0.0.1:8001`) y Firebird (`127.0.0.1:3050`) solo escuchan localmente; todo el tráfico externo entra cifrado por Cloudflare HTTPS sin abrir puertos en el módem.
- **Protección extra opcional en Cloudflare:** activar **Always Use HTTPS** y opcionalmente una regla de **Cloudflare Access** únicamente para `/admin.php`.
- **Respaldo diario:** respalde `C:\checador-titanium\database\checador_local.sqlite`, `C:\checador-titanium\.env` y `C:\checador-titanium\cloudflared\` junto con `ACCESODB.FDB`.
