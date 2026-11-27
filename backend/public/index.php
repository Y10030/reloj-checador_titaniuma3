<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/SecurityHeaders.php';
SecurityHeaders::applyHtml();
?><!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <meta name="theme-color" content="#1B4332">
  <link rel="manifest" href="manifest.json">
  <title>Pallets La Esperanza · Planta Coacalco · Control de Asistencia</title>
  <link rel="stylesheet" href="assets/css/scanner.css?v=<?= (int)@filemtime(__DIR__ . '/assets/css/scanner.css') ?>">
</head>
<body>
  <!-- CONTENEDOR DE TOASTS FLOTANTES -->
  <div id="toastStack" class="toast-stack" aria-live="polite"></div>

  <div class="app-shell">
    <!-- ENCABEZADO CORPORATIVO -->
    <header class="brand-header">
      <div class="brand-top">
        <div class="brand-pill">PALLETS LA ESPERANZA · PLANTA COACALCO</div>
      </div>
      <div id="liveClock" class="live-clock">--:--:--</div>
      <div id="liveDate" class="live-date">Sincronizando hora oficial del servidor...</div>
      <div class="device-line">
        <span id="deviceBadge" class="badge badge-muted">Sin vincular</span>
        <span id="modePill" class="badge badge-soft hidden">Escáner General</span>
      </div>
    </header>

    <!-- PANTALLA 1: VINCULACIÓN DE DISPOSITIVO -->
    <main id="viewSetup" class="card hidden">
      <div class="setup-badge">Autorización de Equipo</div>
      <h1 class="setup-title">Vincular Celular · Pallets La Esperanza</h1>
      <p class="muted">
        Planta Coacalco · Ingrese el código de invitación de un solo uso generado en el Panel Administrativo para autorizar este teléfono.
      </p>
      <form id="setupForm" class="stack">
        <div>
          <label for="inviteCode">Código de invitación</label>
          <input id="inviteCode" type="text" placeholder="Ej. ABCD-2345" maxlength="9" autocomplete="off" required>
        </div>
        <button id="btnBindDevice" type="submit" class="btn-primary">Vincular celular autorizado</button>
      </form>
      <div id="setupFeedback" class="alert hidden"></div>
    </main>

    <!-- PANTALLA 2: CHECADOR ACTIVO -->
    <main id="viewScanner" class="hidden">
      <!-- EMPLEADO Y PLANTA (se muestra al reconocer rostro, en Modo Individual o al usar Huella) -->
      <section id="empCard" class="card hidden">
        <div class="card-kicker">Empleado (Acceso 3 TITANIUM)</div>
        <div id="empLookupRow" class="emp-lookup-row hidden">
          <input id="claveInput" type="number" inputmode="numeric" placeholder="Ingrese su clave de empleado">
          <button id="btnVerifyEmp" type="button" class="btn-verify">Verificar</button>
        </div>

        <div id="empInfoBox" class="emp-info hidden">
          <div class="emp-name" id="empName">—</div>
          <div class="emp-meta">
            <span class="planta-tag" id="empPlanta">Planta: —</span>
            <span class="dept-tag" id="empDepto">—</span>
          </div>
          <div class="emp-bio-row">
            <span id="empBioStatus" class="bio-status">Biometría: —</span>
            <button id="btnEnrollFp" type="button" class="btn-fp-activate hidden">Activar Huella Digital</button>
          </div>
        </div>
      </section>

      <!-- UBICACIÓN -->
      <section class="card geo-card">
        <div class="geo-row">
          <div>
            <div class="card-kicker" style="margin-bottom:4px;">Validación de Área Permitida</div>
            <span id="geoPill" class="pill pill-wait">Validando ubicación...</span>
          </div>
          <button id="btnRefreshGeo" type="button" class="btn-outline">Actualizar GPS</button>
        </div>
      </section>

      <!-- MÉTODO DE VERIFICACIÓN (ROSTRO O HUELLA DIGITAL) -->
      <section class="card">
        <div class="card-kicker">Método de Verificación Biométrica</div>
        <div class="method-tabs">
          <button type="button" id="tabFace" class="method-tab active" data-method="FACIAL">
            &#128247; Reconocimiento Facial
          </button>
          <button type="button" id="tabFingerprint" class="method-tab" data-method="HUELLA">
            &#128070; Huella Digital
          </button>
        </div>

        <!-- Panel Cámara Facial -->
        <div id="facePanel" class="camera-wrap">
          <video id="cameraVideo" autoplay playsinline muted></video>
          <canvas id="captureCanvas" class="hidden"></canvas>
          <div class="face-guide"><div class="oval"></div></div>
          <div id="livenessBanner" class="liveness-banner">
            Centre su rostro dentro del óvalo y presione el botón de checada
          </div>
        </div>

        <!-- Panel Huella Digital -->
        <div id="fpPanel" class="fp-panel hidden">
          <div class="fp-icon">&#128274;</div>
          <div class="fp-title">Verificación con Huella Digital del Celular</div>
          <p id="fpHelpText" class="fp-desc">
            Ingrese y verifique su <strong>Clave de empleado</strong> arriba. Al pulsar Entrada o Salida, el teléfono solicitará su huella digital (o Face ID).
          </p>
        </div>
      </section>

      <!-- 4 BOTONES DE REGISTRO DE ASISTENCIA -->
      <section class="card">
        <div id="punchHeader" class="card-kicker">Registrar Asistencia (Rostro / Huella)</div>
        <div class="punch-grid">
          <button type="button" class="punch-btn btn-entrada" data-evento="ENTRADA" disabled>
            <span class="dot-ico"></span>
            <span>Entrada</span>
          </button>
          <button type="button" class="punch-btn btn-salida" data-evento="SALIDA" disabled>
            <span class="dot-ico"></span>
            <span>Salida</span>
          </button>
          <button type="button" class="punch-btn btn-comida-ini" data-evento="COMIDA_INI" disabled>
            <span class="emoji-ico">&#127869;</span>
            <span>Inicio Comida</span>
          </button>
          <button type="button" class="punch-btn btn-comida-fin" data-evento="COMIDA_FIN" disabled>
            <span class="emoji-ico">&#128260;</span>
            <span>Fin Comida</span>
          </button>
        </div>

        <input id="notaInput" type="text" maxlength="160" class="nota-input" placeholder="Nota opcional (ej. Cambio de turno / visita planta)">
      </section>

      <footer class="bottom-links">
        <span>Hora blindada del servidor · Pallets La Esperanza · Planta Coacalco</span>
        <button id="btnUnbindLocal" type="button" class="link-danger">Desvincular (Admin)</button>
      </footer>
    </main>
  </div>

  <!-- MODAL DE DESVINCULACIÓN PROTEGIDA POR ADMIN -->
  <div id="unbindModal" class="modal-backdrop hidden">
    <div class="modal-box">
      <h3>Autorización de Administrador</h3>
      <p class="muted" style="margin-bottom:10px;">Ingrese credenciales administrativas para desvincular este celular.</p>
      <form id="unbindForm" class="stack">
        <div>
          <label for="unbindUser">Usuario admin</label>
          <input id="unbindUser" type="text" value="admin" required>
        </div>
        <div>
          <label for="unbindPass">Contraseña</label>
          <input id="unbindPass" type="password" required>
        </div>
        <div class="modal-actions">
          <button id="btnCancelUnbind" type="button" class="btn-outline">Cancelar</button>
          <button type="submit" class="btn-primary" style="width:auto;padding:9px 16px;">Confirmar desvinculación</button>
        </div>
      </form>
    </div>
  </div>

  <script src="assets/js/crypto.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/crypto.js') ?>"></script>
  <script src="assets/js/scanner.js?v=<?= (int)@filemtime(__DIR__ . '/assets/js/scanner.js') ?>"></script>
</body>
</html>
