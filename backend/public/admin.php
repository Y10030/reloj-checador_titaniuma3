<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/SecurityHeaders.php';
SecurityHeaders::applyHtml();
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pallets La Esperanza · Planta Coacalco · Panel Administrativo</title>
  <link rel="stylesheet" href="assets/vendor/leaflet/leaflet.css">
  <link rel="stylesheet" href="assets/css/admin.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/admin.css') ?>">
</head>
<body>
  <!-- LOGIN -->
  <section id="loginView" class="login-wrap">
    <div class="login-card">
      <div class="login-badge">PALLETS LA ESPERANZA · PLANTA COACALCO</div>
      <h1>Panel Administrativo</h1>
      <p class="muted">Gestione escáneres móviles, enrolamiento facial, plantas, áreas permitidas y sincronización con Firebird 2.5.</p>
      <form id="loginForm" class="stack">
        <div>
          <label for="loginUser">Usuario</label>
          <input id="loginUser" type="text" value="admin" required autocomplete="username">
        </div>
        <div>
          <label for="loginPass">Contraseña</label>
          <input id="loginPass" type="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary">Iniciar sesión</button>
      </form>
      <div id="loginError" class="alert err hidden"></div>
      <div class="login-foot">
        <a href="index.php">&larr; Ir a Vista Celular Escáner</a>
      </div>
    </div>
  </section>

  <!-- DASHBOARD PRINCIPAL -->
  <div id="appView" class="layout hidden">
    <aside class="sidebar">
      <div class="side-brand">
        <div class="side-title">Pallets La Esperanza</div>
        <div class="side-sub">Planta Coacalco · Acceso 3 TITANIUM</div>
      </div>
      <nav class="nav">
        <button class="nav-btn active" data-tab="dashboard">Resumen y Monitor</button>
        <button class="nav-btn" data-tab="devices">Escáneres y Celulares</button>
        <button class="nav-btn" data-tab="enroll">Rostros TITANIUM</button>
        <button class="nav-btn" data-tab="locations">Áreas Permitidas</button>
        <button class="nav-btn" data-tab="rejections">Bitácora de Rechazos</button>
      </nav>
      <div class="side-footer">
        <div id="adminName" class="admin-user">Administrador</div>
        <a href="index.php" class="side-link">Abrir Vista Móvil</a>
        <button id="btnLogout" class="btn-outline-sm">Cerrar sesión</button>
      </div>
    </aside>

    <main class="main">
      <header class="main-header">
        <div>
          <h2 id="tabTitle">Resumen y Monitor Operativo</h2>
          <div id="serverClock" class="muted-sm">Hora del servidor: --</div>
        </div>
        <div class="health-bar">
          <span id="healthTitanium" class="pill pill-muted">TITANIUM: --</span>
          <span id="healthFacial" class="pill pill-muted">Python ONNX: --</span>
          <button id="btnRetrySync" class="btn btn-sm">Sincronizar Pendientes</button>
        </div>
      </header>

      <!-- TAB 1: DASHBOARD + CHECADAS -->
      <section id="tab-dashboard" class="tab-panel">
        <div class="kpi-grid">
          <div class="kpi"><span class="kpi-label">Checadas Hoy</span><strong id="mChecadas">0</strong></div>
          <div class="kpi"><span class="kpi-label">Pendientes TITANIUM</span><strong id="mPendientes">0</strong></div>
          <div class="kpi"><span class="kpi-label">Rechazos Hoy</span><strong id="mRechazos">0</strong></div>
          <div class="kpi"><span class="kpi-label">Escáneres Activos</span><strong id="mEscaneres">0</strong></div>
          <div class="kpi"><span class="kpi-label">Empleados Enrolados</span><strong id="mEnrolados">0</strong></div>
          <div class="kpi"><span class="kpi-label">Áreas Activas</span><strong id="mUbicaciones">0</strong></div>
        </div>

        <div class="panel-card">
          <div class="card-head">
            <h3>Últimas Checadas Aprobadas</h3>
            <button id="btnRefreshPunches" class="btn-outline-sm">Actualizar</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Folio</th>
                  <th>Fecha / Hora Servidor</th>
                  <th>Empleado / Planta</th>
                  <th>Evento / Método</th>
                  <th>Escáner</th>
                  <th>Área Permitida</th>
                  <th>TITANIUM</th>
                </tr>
              </thead>
              <tbody id="punchesBody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- TAB 2: DISPOSITIVOS -->
      <section id="tab-devices" class="tab-panel hidden">
        <div class="grid-2">
          <div class="panel-card">
            <h3>Autorizar Nuevo Celular Escáner o Individual</h3>
            <form id="inviteForm" class="stack">
              <div>
                <label for="devAlias">Alias del celular</label>
                <input id="devAlias" type="text" placeholder="Ej. Escáner Recepción" required>
              </div>
              <div>
                <label for="devMode">Modalidad</label>
                <select id="devMode">
                  <option value="ESCANER">Escáner General (compartido)</option>
                  <option value="INDIVIDUAL">Escáner Personal (empleado fijo)</option>
                </select>
              </div>
              <div id="devEmpWrap" class="hidden">
                <label for="devEmpSearch">Empleado asignado (Acceso 3 TITANIUM · estatus Alta)</label>
                <input id="devEmpSearch" type="search" placeholder="Filtrar lista por ID o nombre..." style="margin-bottom:6px;">
                <select id="devEmpClave">
                  <option value="">Cargando lista de empleados...</option>
                </select>
              </div>
              <button type="submit" class="btn btn-primary">Generar código de vinculación</button>
            </form>
            <div id="inviteResult" class="alert ok hidden"></div>
          </div>

          <div class="panel-card">
            <h3>Invitaciones Pendientes</h3>
            <div class="table-wrap">
              <table>
                <thead><tr><th>Código</th><th>Alias</th><th>Modo</th><th>Expira</th></tr></thead>
                <tbody id="invitesBody"></tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="panel-card">
          <h3>Celulares Vinculados</h3>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Alias</th>
                  <th>Modo</th>
                  <th>Empleado</th>
                  <th>Estado</th>
                  <th>Último Uso</th>
                  <th>Acción</th>
                </tr>
              </thead>
              <tbody id="devicesBody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- TAB 3: ROSTROS DESDE ACCESO 3 TITANIUM (SOLO LECTURA DE FOTOS EN TITANIUM) -->
      <section id="tab-enroll" class="tab-panel hidden">
        <div class="panel-card">
          <div class="card-head">
            <div>
              <h3>Fotografías Oficiales en Acceso 3 TITANIUM</h3>
              <p class="muted" style="margin:4px 0 0;">Las imágenes de los empleados viven <strong>únicamente en Acceso 3 TITANIUM</strong> (tabla <code>USUARIOSTERMINAL</code>). La web no guarda fotos; solo compara en vivo contra las fotos vigentes en TITANIUM.</p>
            </div>
            <button id="btnSyncTitaniumFaces" type="button" class="btn btn-primary">Sincronizar Fotos desde TITANIUM</button>
          </div>
          <div id="enrollFeedback" class="alert hidden"></div>
        </div>

        <div class="panel-card">
          <div class="card-head">
            <h3>Catálogo de Empleados (Acceso 3 TITANIUM)</h3>
            <input id="empSearch" type="search" placeholder="Buscar por clave o nombre..." class="search-sm">
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Clave</th>
                  <th>Nombre / Planta</th>
                  <th>Estatus</th>
                  <th>Foto en TITANIUM</th>
                  <th>Comparación Face ID</th>
                  <th>Acción</th>
                </tr>
              </thead>
              <tbody id="employeesBody"></tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- TAB 4: UBICACIONES CON MAPA INTERACTIVO -->
      <section id="tab-locations" class="tab-panel hidden">
        <div class="panel-card">
          <div class="card-head">
            <h3>Mapa Interactivo de Áreas Permitidas y Radio</h3>
            <span id="mapHint" class="muted-sm">Haga clic en el mapa o arrastre el pin verde para elegir el punto; mueva el deslizador para ajustar el radio.</span>
          </div>
          <div id="geoMap" class="geo-map"></div>
        </div>

        <div class="grid-2">
          <div class="panel-card">
            <div class="card-head">
              <h3 id="locFormTitle">Nueva Área Permitida</h3>
              <button id="btnNewLocation" type="button" class="btn-outline-sm">Limpiar / Nueva</button>
            </div>
            <form id="locationForm" class="stack">
              <input id="locId" type="hidden" value="">
              <div>
                <label for="locName">Nombre de ubicación / planta</label>
                <input id="locName" type="text" placeholder="Ej. Pallets La Esperanza — Planta Coacalco" required>
              </div>
              <div class="row-2">
                <div>
                  <label for="locLat">Latitud</label>
                  <input id="locLat" type="number" step="0.0000001" value="19.6078600" required>
                </div>
                <div>
                  <label for="locLon">Longitud</label>
                  <input id="locLon" type="number" step="0.0000001" value="-98.9891000" required>
                </div>
              </div>
              <button id="btnUseMyLocation" type="button" class="btn-outline-sm">Centrar mapa en mi ubicación actual</button>
              <div>
                <label for="locRadius">Radio permitido en el mapa: <strong id="locRadiusLabel">250 m</strong></label>
                <div class="radius-row">
                  <input id="locRadiusRange" type="range" min="20" max="1500" step="10" value="250">
                  <input id="locRadius" type="number" value="250" min="15" max="5000" required style="width:95px;">
                </div>
              </div>
              <div>
                <label for="locAcc">Precisión GPS máx. aceptada (metros)</label>
                <input id="locAcc" type="number" value="2500" min="15" max="5000" required>
              </div>
              <button id="btnSaveLocation" type="submit" class="btn btn-primary">Guardar área permitida</button>
            </form>
            <div id="locFeedback" class="alert hidden"></div>
          </div>

          <div class="panel-card">
            <h3>Áreas Configuradas (clic para ver en mapa)</h3>
            <div class="table-wrap">
              <table>
                <thead>
                  <tr><th>Nombre</th><th>Radio</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody id="locationsBody"></tbody>
              </table>
            </div>
          </div>
        </div>
      </section>

      <!-- TAB 5: RECHAZOS -->
      <section id="tab-rejections" class="tab-panel hidden">
        <div class="panel-card">
          <div class="card-head">
            <h3>Bitácora de Intentos Bloqueados</h3>
            <button id="btnRefreshRejections" class="btn-outline-sm">Actualizar</button>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Fecha / Hora</th>
                  <th>Motivo</th>
                  <th>Escáner</th>
                  <th>Clave Intento</th>
                  <th>Área</th>
                  <th>Detalle</th>
                  <th>IP Origen</th>
                </tr>
              </thead>
              <tbody id="rejectionsBody"></tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="assets/vendor/leaflet/leaflet.js"></script>
  <script src="assets/js/admin.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/admin.js') ?>"></script>
</body>
</html>
