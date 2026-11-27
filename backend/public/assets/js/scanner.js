(() => {
  const CHALLENGE_LABELS = {
    MANTEN_ROSTRO_FRENTE: 'Mire de frente a la cámara',
    PARPADEA_SUAVE: 'Parpadee suavemente mirando a la cámara',
    GIRA_LEVEMENTE_IZQUIERDA: 'Gire ligeramente el rostro a la izquierda',
    GIRA_LEVEMENTE_DERECHA: 'Gire ligeramente el rostro a la derecha',
  };

  const EVENT_LABELS = {
    ENTRADA: 'Entrada',
    SALIDA: 'Salida',
    COMIDA_INI: 'Inicio Comida',
    COMIDA_FIN: 'Fin Comida',
    AUTO: 'Asistencia',
  };

  const state = {
    device: null,
    employee: null,
    coords: null,
    inAllowedArea: false,
    cameraReady: false,
    method: 'FACIAL', // 'FACIAL' | 'HUELLA'
    busy: false,
    serverOffsetMs: 0,
    appVersion: null,
    configVersion: null,
  };

  const $ = (id) => document.getElementById(id);

  function esc(str) {
    return String(str ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  async function api(action, method = 'GET', body = null, params = null) {
    const opts = { method, headers: { Accept: 'application/json' } };
    if (body !== null) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const qs = new URLSearchParams({ action });
    if (params) {
      Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
      });
    }
    const resp = await fetch(`api.php?${qs.toString()}`, opts);
    const data = await resp.json().catch(() => ({ ok: false, mensaje: 'Respuesta inválida del servidor.' }));
    return { status: resp.status, data };
  }

  function startServerClock(serverTimeStr) {
    if (serverTimeStr) {
      const parsed = new Date(serverTimeStr.replace(' ', 'T'));
      if (!Number.isNaN(parsed.getTime())) {
        state.serverOffsetMs = parsed.getTime() - Date.now();
      }
    }
    const tick = () => {
      const now = new Date(Date.now() + state.serverOffsetMs);
      $('liveClock').textContent = now.toLocaleTimeString('es-MX', { hour12: false });
      $('liveDate').textContent = 'Hora oficial servidor · ' + now.toLocaleDateString('es-MX', {
        weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
      });
    };
    tick();
    setInterval(tick, 1000);
  }

  function showView(name) {
    $('viewSetup').classList.toggle('hidden', name !== 'setup');
    $('viewScanner').classList.toggle('hidden', name !== 'scanner');
  }

  function updatePunchButtons() {
    const readyBiometric = state.method === 'FACIAL'
      ? state.cameraReady
      : Boolean(state.employee && state.employee.activo && state.employee.tiene_huella);
    const enabled = !state.busy && state.inAllowedArea && state.coords !== null && readyBiometric;
    document.querySelectorAll('.punch-btn').forEach((btn) => { btn.disabled = !enabled; });
  }

  function setGeoUi(kind, text) {
    const pill = $('geoPill');
    pill.className = 'pill ' + (kind === 'ok' ? 'pill-ok' : kind === 'no' ? 'pill-no' : 'pill-wait');
    pill.textContent = text;
  }

  function showResult(ok, title, detail) {
    const stack = $('toastStack');
    if (!stack) return;
    // Mantener máximo 2 toasts visibles
    while (stack.children.length >= 2) {
      stack.firstElementChild.remove();
    }
    const toast = document.createElement('div');
    toast.className = 'toast ' + (ok ? 'ok' : 'err');
    toast.innerHTML = `
      <div class="toast-body">
        <div class="toast-title">${esc(title)}</div>
        <div>${esc(detail)}</div>
      </div>
      <button type="button" class="toast-close" aria-label="Cerrar">&times;</button>
    `;
    const dismiss = () => {
      if (toast.classList.contains('hiding')) return;
      toast.classList.add('hiding');
      setTimeout(() => toast.remove(), 220);
    };
    toast.querySelector('.toast-close').addEventListener('click', dismiss);
    stack.appendChild(toast);
    setTimeout(dismiss, ok ? 6500 : 5000);
  }

  function syncEmployeeCardVisibility() {
    const isInd = Boolean(state.device && state.device.tipo_modo === 'INDIVIDUAL');
    const needManualLookup = !isInd && state.method === 'HUELLA';
    const hasEmp = Boolean(state.employee);

    $('empLookupRow').classList.toggle('hidden', !needManualLookup);
    $('empCard').classList.toggle('hidden', !needManualLookup && !isInd && !hasEmp);
  }

  function renderEmployeeCard(emp) {
    state.employee = emp;
    $('empName').textContent = `${emp.nombre_completo} (ID #${emp.clave_empleado})`;
    $('empPlanta').textContent = `Planta: ${emp.planta}`;
    $('empDepto').textContent = emp.departamento;

    const bioParts = [];
    if (emp.tiene_rostro) bioParts.push('Rostro activo');
    if (emp.tiene_huella) bioParts.push('Huella activa');
    $('empBioStatus').textContent = !emp.activo
      ? 'Estatus: Baja en TITANIUM'
      : (bioParts.length ? bioParts.join(' · ') : 'Sin biometría activa');

    const canActivateFp = emp.activo && Boolean(window.PublicKeyCredential);
    $('btnEnrollFp').classList.toggle('hidden', !canActivateFp);
    $('btnEnrollFp').textContent = emp.tiene_huella ? 'Re-registrar Huella' : 'Activar Huella Digital';

    if (state.method === 'HUELLA') {
      $('fpHelpText').innerHTML = emp.tiene_huella
        ? `Huella lista para <strong>${esc(emp.nombre_completo)}</strong> (Planta: <strong>${esc(emp.planta)}</strong>). Presione cualquier botón de checada abajo.`
        : `Pulse <strong>"Activar Huella Digital"</strong> arriba para vincular la huella de <strong>${esc(emp.nombre_completo)}</strong> en este celular.`;
    }

    $('empInfoBox').classList.remove('hidden');
    syncEmployeeCardVisibility();
    updatePunchButtons();
  }

  async function lookupEmployee(claveNum) {
    const clave = Number(claveNum);
    if (!clave || !state.device) return;
    const { data } = await api('employee_lookup', 'GET', null, {
      device_uuid: state.device.device_uuid,
      clave,
    });
    if (!data.ok) {
      state.employee = null;
      $('empInfoBox').classList.add('hidden');
      showResult(false, 'Empleado no encontrado', data.mensaje || 'Verifique la clave en Acceso 3 TITANIUM.');
      updatePunchButtons();
      return;
    }
    renderEmployeeCard(data.employee);
  }

  function setMethod(method) {
    state.method = method;
    $('tabFace').classList.toggle('active', method === 'FACIAL');
    $('tabFingerprint').classList.toggle('active', method === 'HUELLA');
    $('facePanel').classList.toggle('hidden', method !== 'FACIAL');
    $('fpPanel').classList.toggle('hidden', method !== 'HUELLA');
    $('punchHeader').textContent = method === 'HUELLA'
      ? 'Registrar Asistencia (Pedirá Huella Digital)'
      : 'Registrar Asistencia (Reconocimiento Facial)';
    const isInd = Boolean(state.device && state.device.tipo_modo === 'INDIVIDUAL');
    if (method === 'FACIAL' && !isInd) {
      state.employee = null;
      $('claveInput').value = '';
      $('empInfoBox').classList.add('hidden');
    } else if (state.employee) {
      renderEmployeeCard(state.employee);
    }
    syncEmployeeCardVisibility();
    updatePunchButtons();
  }

  async function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      $('livenessBanner').textContent = 'Cámara no disponible o requiere HTTPS.';
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
        audio: false,
      });
      $('cameraVideo').srcObject = stream;
      state.cameraReady = true;
      updatePunchButtons();
    } catch {
      $('livenessBanner').textContent = 'Permiso de cámara denegado. Habilite la cámara frontal.';
    }
  }

  function captureFrameBase64() {
    const video = $('cameraVideo');
    const canvas = $('captureCanvas');
    const w = video.videoWidth || 640;
    const h = video.videoHeight || 480;
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d').drawImage(video, 0, 0, w, h);
    return canvas.toDataURL('image/jpeg', 0.86);
  }

  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  async function verifyCoordsOnServer(pos) {
    state.coords = {
      latitude: pos.coords.latitude,
      longitude: pos.coords.longitude,
      accuracy: pos.coords.accuracy,
    };
    const { data } = await api('geo_check', 'POST', {
      device_uuid: state.device.device_uuid,
      ...state.coords,
    });
    if (data.ok && data.dentro_area_permitida) {
      state.inAllowedArea = true;
      setGeoUi('ok', 'En área permitida');
    } else {
      state.inAllowedArea = false;
      setGeoUi('no', 'Fuera de área permitida');
    }
    updatePunchButtons();
  }

  async function refreshGeolocation() {
    if (!state.device) return;
    setGeoUi('wait', 'Validando área...');
    state.inAllowedArea = false;
    updatePunchButtons();

    if (!navigator.geolocation) {
      setGeoUi('no', 'Fuera de área permitida');
      return;
    }

    navigator.geolocation.getCurrentPosition(
      verifyCoordsOnServer,
      () => {
        navigator.geolocation.getCurrentPosition(
          verifyCoordsOnServer,
          (err2) => {
            state.coords = null;
            state.inAllowedArea = false;
            setGeoUi('no', 'Fuera de área permitida');
            const reason = err2.code === 1
              ? 'Permiso de ubicación denegado en el navegador.'
              : 'No se pudo obtener ubicación GPS del equipo.';
            showResult(false, 'Sin señal GPS', reason);
            updatePunchButtons();
          },
          { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 }
        );
      },
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 10000 }
    );
  }

  async function handleEnrollFingerprint() {
    if (!state.device || !state.employee) return;
    $('btnEnrollFp').disabled = true;
    try {
      const chRes = await api('punch_challenge', 'POST', { device_uuid: state.device.device_uuid });
      if (!chRes.data.ok) throw new Error(chRes.data.mensaje || 'No se pudo iniciar el registro de huella.');
      const nonce = chRes.data.nonce;

      const fpCred = await DeviceCrypto.registerPlatformFingerprint(
        state.employee.clave_empleado,
        state.employee.nombre_completo
      );

      const signStr = `FP_REG|${state.device.device_uuid}|${nonce}|${state.employee.clave_empleado}|${fpCred.credential_id}`;
      const signature = await DeviceCrypto.signPayload(signStr);

      const { data } = await api('fingerprint_register', 'POST', {
        device_uuid: state.device.device_uuid,
        clave_empleado: state.employee.clave_empleado,
        credential_id: fpCred.credential_id,
        public_key_pem: fpCred.public_key_pem,
        nonce,
        signature,
      });
      if (!data.ok) throw new Error(data.mensaje || 'No se pudo registrar la huella.');
      showResult(true, 'Huella digital activada', `La huella de ${data.fingerprint.nombre_completo} (Planta: ${data.fingerprint.planta}) quedó activa en este celular.`);
      await lookupEmployee(state.employee.clave_empleado);
    } catch (err) {
      showResult(false, 'No se pudo activar huella', err.message || 'Operación cancelada.');
    } finally {
      $('btnEnrollFp').disabled = false;
    }
  }

  async function handlePunch(tipoEvento) {
    if (state.busy || !state.device || !state.coords) return;
    state.busy = true;
    updatePunchButtons();

    try {
      const chRes = await api('punch_challenge', 'POST', { device_uuid: state.device.device_uuid });
      if (!chRes.data.ok) throw new Error(chRes.data.mensaje || 'No se pudo iniciar la checada.');

      const nonce = chRes.data.nonce;
      const reto = chRes.data.reto_liveness;
      const requestUuid = DeviceCrypto.uuidV4();
      const latStr = Number(state.coords.latitude).toFixed(6);
      const lonStr = Number(state.coords.longitude).toFixed(6);
      const payloadToSign = `${state.device.device_uuid}|${nonce}|${requestUuid}|${latStr}|${lonStr}`;
      const signature = await DeviceCrypto.signPayload(payloadToSign);

      const isInd = state.device.tipo_modo === 'INDIVIDUAL';
      const body = {
        device_uuid: state.device.device_uuid,
        nonce,
        request_uuid: requestUuid,
        signature,
        tipo_evento: tipoEvento,
        metodo_verificacion: state.method,
        // En Modo Escáner Facial 1:N se identifica estrictamente por el rostro escaneado (sin ID manual)
        clave_empleado: (state.method === 'FACIAL' && !isInd) ? '' : $('claveInput').value.trim(),
        nota: $('notaInput').value.trim(),
        latitude: state.coords.latitude,
        longitude: state.coords.longitude,
        accuracy: state.coords.accuracy,
      };

      if (state.method === 'HUELLA') {
        if (!state.employee || !state.employee.tiene_huella || !state.employee.credential_id) {
          throw new Error('Primero verifique su Clave de empleado y active su Huella Digital.');
        }
        body.clave_empleado = state.employee.clave_empleado;
        body.webauthn = await DeviceCrypto.verifyPlatformFingerprint(state.employee.credential_id, nonce);
        if (state.cameraReady) {
          try { body.photo_base64 = captureFrameBase64(); } catch {}
        }
      } else {
        $('livenessBanner').textContent = CHALLENGE_LABELS[reto] || 'Mire de frente a la cámara';
        const frame1 = captureFrameBase64();
        await sleep(650);
        const frame2 = captureFrameBase64();
        $('livenessBanner').textContent = 'Validando rostro, área permitida y registro en servidor...';
        body.images_base64 = [frame1, frame2];
      }

      const res = await api('punch_submit', 'POST', body);
      if (res.data.ok) {
        const labelEv = EVENT_LABELS[res.data.tipo_evento] || res.data.tipo_evento;
        const labelMet = res.data.metodo_verificacion === 'HUELLA' ? 'Huella Digital' : 'Reconocimiento Facial';
        showResult(
          true,
          `${labelEv} registrada · ${res.data.nombre_empleado}`,
          `Clave #${res.data.clave_empleado} · Planta: ${res.data.planta} · Hora oficial: ${res.data.fecha_hora_servidor} · ${labelMet} · TITANIUM: ${res.data.estado_sync_titanium}`
        );
        $('notaInput').value = '';
        await lookupEmployee(res.data.clave_empleado);
        // En Escáner 1:N facial, limpiar tarjeta del empleado reconocido tras 8s para el siguiente usuario
        if (state.method === 'FACIAL' && state.device && state.device.tipo_modo !== 'INDIVIDUAL') {
          setTimeout(() => {
            if (state.method === 'FACIAL' && !state.busy) {
              state.employee = null;
              $('empInfoBox').classList.add('hidden');
              syncEmployeeCardVisibility();
            }
          }, 8000);
        }
      } else {
        if (res.data.dentro_area_permitida === false) {
          state.inAllowedArea = false;
          setGeoUi('no', 'Fuera de área permitida');
        }
        showResult(false, 'No se registró la checada', res.data.mensaje || 'Registro bloqueado.');
      }
    } catch (err) {
      showResult(false, 'No se registró la checada', err.message || 'Error de comunicación.');
    } finally {
      $('livenessBanner').textContent = 'Centre su rostro dentro del óvalo y presione el botón de checada';
      state.busy = false;
      updatePunchButtons();
    }
  }

  async function initAuthorizedView(meta) {
    const { data } = await api('device_status', 'GET', null, { device_uuid: meta.device_uuid });
    if (!data.ok || !data.authorized) {
      await DeviceCrypto.clearAll();
      $('deviceBadge').className = 'badge badge-muted';
      $('deviceBadge').textContent = 'Sin vincular';
      showView('setup');
      return;
    }

    startServerClock(data.server_time);
    state.device = { ...meta, ...data.device };
    $('deviceBadge').className = 'badge badge-ok';
    $('deviceBadge').textContent = state.device.alias_dispositivo;

    const isInd = state.device.tipo_modo === 'INDIVIDUAL';
    $('modePill').classList.remove('hidden');
    $('modePill').textContent = isInd
      ? 'Escáner Personal'
      : 'Escáner General';

    if (isInd && state.device.clave_empleado_asignado) {
      $('claveInput').value = state.device.clave_empleado_asignado;
      $('claveInput').readOnly = true;
      await lookupEmployee(state.device.clave_empleado_asignado);
    }

    syncEmployeeCardVisibility();
    showView('scanner');
    await startCamera();
    await refreshGeolocation();
  }

  async function handleSetupSubmit(e) {
    e.preventDefault();
    const codigo = $('inviteCode').value.trim().toUpperCase();
    const fb = $('setupFeedback');
    fb.classList.add('hidden');
    $('btnBindDevice').disabled = true;

    try {
      const publicKeyPem = await DeviceCrypto.generateAndStoreKeyPair();
      const { data } = await api('device_register', 'POST', { codigo, public_key_pem: publicKeyPem });
      if (!data.ok) throw new Error(data.mensaje || 'No se pudo vincular el celular.');
      DeviceCrypto.saveDeviceMeta(data.device);
      await initAuthorizedView(data.device);
    } catch (err) {
      fb.className = 'alert err';
      fb.textContent = err.message || 'Error al vincular.';
      fb.classList.remove('hidden');
    } finally {
      $('btnBindDevice').disabled = false;
    }
  }

  function formatInviteInput(e) {
    const input = e.target;
    const raw = input.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
    if (raw.length > 4) {
      input.value = raw.slice(0, 4) + '-' + raw.slice(4);
    } else if (raw.length === 4 && e.inputType !== 'deleteContentBackward') {
      input.value = raw + '-';
    } else {
      input.value = raw;
    }
  }

  async function boot() {
    $('inviteCode').addEventListener('input', formatInviteInput);
    $('setupForm').addEventListener('submit', handleSetupSubmit);
    $('btnRefreshGeo').addEventListener('click', refreshGeolocation);
    $('btnVerifyEmp').addEventListener('click', () => lookupEmployee($('claveInput').value));
    $('claveInput').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); lookupEmployee($('claveInput').value); }
    });
    $('btnEnrollFp').addEventListener('click', handleEnrollFingerprint);
    $('tabFace').addEventListener('click', () => setMethod('FACIAL'));
    $('tabFingerprint').addEventListener('click', () => setMethod('HUELLA'));
    document.querySelectorAll('.punch-btn').forEach((btn) => {
      btn.addEventListener('click', () => handlePunch(btn.dataset.evento));
    });
    $('btnUnbindLocal').addEventListener('click', () => {
      $('unbindPass').value = '';
      $('unbindModal').classList.remove('hidden');
      $('unbindPass').focus();
    });
    $('btnCancelUnbind').addEventListener('click', () => $('unbindModal').classList.add('hidden'));
    $('unbindForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      if (!state.device) return;
      const { data } = await api('device_unbind_admin', 'POST', {
        device_uuid: state.device.device_uuid,
        usuario: $('unbindUser').value.trim(),
        password: $('unbindPass').value,
      });
      if (!data.ok) {
        showResult(false, 'Autorización denegada', data.mensaje || 'Credenciales administrativas incorrectas.');
        return;
      }
      await DeviceCrypto.clearAll();
      location.reload();
    });

    const health = await api('health');
    if (health.data) {
      if (health.data.server_time) startServerClock(health.data.server_time);
      state.appVersion = health.data.app_version || null;
      state.configVersion = health.data.config_version || null;
    }

    // Auto-actualización instantánea al detectar cambios en archivos o áreas permitidas
    const checkLiveUpdates = async () => {
      if (state.busy || document.hidden) return;
      try {
        const h = await api('health');
        if (!h.data || !h.data.ok) return;
        if (state.appVersion && h.data.app_version && h.data.app_version !== state.appVersion) {
          location.reload();
          return;
        }
        if (state.configVersion && h.data.config_version && h.data.config_version !== state.configVersion) {
          state.configVersion = h.data.config_version;
          if (state.device) refreshGeolocation();
        }
      } catch {}
    };
    setInterval(checkLiveUpdates, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) checkLiveUpdates(); });

    const meta = DeviceCrypto.getDeviceMeta();
    const pair = await DeviceCrypto.loadKeyPair().catch(() => null);
    if (meta && meta.device_uuid && pair) {
      await initAuthorizedView(meta);
    } else {
      showView('setup');
    }
  }

  boot();
})();
