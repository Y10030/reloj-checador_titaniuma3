# Sistema de Checadas Móviles — Integración Acceso 3 TITANIUM (Vía A)

Aplicación web móvil diseñada para habilitar **teléfonos celulares como estaciones escáner** y para empleados selectivos, con verificación facial en servidor (**Python FastAPI + InsightFace ONNX + Anti-Spoofing**), validación geográfica con **bloqueo automático fuera de área permitida** e integración directa con **Acceso 3 TITANIUM** vía **Firebird 2.5 (`localhost:3050`)**.

---

## Arquitectura del proyecto

```text
checador-titanium/
├── .env                          # Configuración local/producción
├── database/
│   ├── schema_mysql.sql          # Esquema MySQL 8 / MariaDB (producción)
│   └── schema_sqlite.sql         # Esquema SQLite (desarrollo/pruebas instantáneas)
├── backend/
│   ├── config/config.php         # Carga de variables de entorno
│   ├── src/
│   │   ├── Database.php          # Conexión PDO (MySQL / SQLite)
│   │   ├── CryptoService.php     # AES-256-GCM + verificación ECDSA P-256 Web Crypto
│   │   ├── GeoService.php        # Haversine + bloqueo fuera de área permitida
│   │   ├── TitaniumConnector.php # Conector Firebird 2.5 (Vía A) + modo demo
│   │   ├── FacialClient.php      # Cliente HTTP local hacia Python FastAPI
│   │   ├── AuditService.php      # Bitácora de rechazos + rate limiting
│   │   ├── AuthService.php       # Sesiones seguras + CSRF para panel admin
│   │   └── AttendanceService.php # Orquestador completo de validaciones y cola TITANIUM
│   └── public/
│       ├── index.php             # Interfaz móvil (Celular Escáner / Individual)
│       ├── admin.php             # Panel administrativo web
│       └── api.php               # API REST JSON
├── facial-service/
│   ├── app.py                    # Microservicio nativo Python FastAPI + InsightFace ONNX
│   ├── requirements.txt          # Dependencias Python
│   └── install_windows_service.ps1
├── scripts/
│   ├── start_local.ps1           # Arranque rápido local
│   ├── setup_cloudflare_tunnel.ps1
│   └── sync_titanium_queue.php   # Reintento automático para Programador de Tareas Windows
└── tests/
    └── run_tests.php             # Suite de pruebas automatizadas extremo a extremo
```

---

## Puesta en marcha en el servidor Windows local

### 1. Instalar el microservicio facial (Python 3.10 + InsightFace ONNX)
```powershell
cd facial-service
.\install_windows_service.ps1
```

### 2. Configurar `.env` para producción con Acceso 3 TITANIUM
1. Genere una clave maestra AES-256 segura:
   ```powershell
   php -r "echo bin2hex(random_bytes(32));"
   ```
2. Configure en `.env`:
   - `DB_DRIVER=mysql` e importe `database/schema_mysql.sql` en MySQL/MariaDB.
   - `TITANIUM_MODE=firebird`, `TITANIUM_FB_HOST=localhost`, `TITANIUM_FB_PORT=3050` y la ruta real de su `.fdb`.
   - Active `extension=pdo_firebird` en `php.ini` (asegurando que `fbclient.dll` de Firebird 2.5 esté en el `PATH` de Windows).
   - Verifique en `DemoAcceso.fdb` los nombres exactos de tabla/columnas de checadas y ajústelos en las variables `TITANIUM_TBL_*` y `TITANIUM_COL_*`.

### 3. Iniciar servicios locales y exponer por Cloudflare Tunnel (HTTPS)
```powershell
.\scripts\start_local.ps1
# En otra terminal (para acceso HTTPS desde celulares sin abrir puertos):
cloudflared tunnel --url http://127.0.0.1:8080
```

### 4. Credenciales iniciales del Panel Administrativo
- URL: `http://127.0.0.1:8080/admin.php`
- Usuario por defecto: `admin`
- Contraseña por defecto: `Titanium2026!` *(cambiar inmediatamente en producción)*
