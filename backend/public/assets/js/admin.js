(() => {
  const $ = (id) => document.getElementById(id);
  const state = {
    csrf: '',
    admin: null,
    enrollStream: null,
    locationsMap: new Map(),
    geoMap: null,
    draftMarker: null,
    draftCircle: null,
    savedLayerGroup: null,
    selectedLocId: null,
    activeEmployees: [],
    appVersion: null,
  };

  const TAB_TITLES = {
    dashboard: 'Resumen y Monitor Operativo',
    devices: 'Escáneres y Celulares Autorizados',
    enroll: 'Rostros desde Acceso 3 TITANIUM',
    locations: 'Áreas Permitidas (Geocercas)',
    rejections: 'Bitácora de Intentos Bloqueados',
  };

  function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  }

  async function api(action, method = 'GET', body = null, params = null) {
    const headers = { Accept: 'application/json' };
    if (method !== 'GET' && state.csrf) {
      headers['X-CSRF-Token'] = state.csrf;
    }
    const opts = { method, headers, credentials: 'same-origin' };
    if (body !== null) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    const qs = new URLSearchParams({ action });
    if (params) {
      Object.entries(params).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
      });
    }
    const resp = await fetch(`api.php?${qs.toString()}`, opts);
    const data = await resp.json().catch(() => ({ ok: false, mensaje: 'Error de respuesta.' }));
    return { status: resp.status, data };
  }

  function showTab(tab) {
    document.querySelectorAll('.tab-panel').forEach((p) => p.classList.add('hidden'));
    document.querySelectorAll('.nav-btn').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    $(`tab-${tab}`).classList.remove('hidden');
    $('tabTitle').textContent = TAB_TITLES[tab] || tab;

    if (tab === 'dashboard') { loadDashboard(); loadPunches(); }
    if (tab === 'devices') loadDevices();
    if (tab === 'enroll') loadEmployees();
    if (tab === 'locations') loadLocations();
    if (tab === 'rejections') loadRejections();
  }

  async function loadDashboard() {
    const { data } = await api('admin_dashboard');
    if (!data.ok) return;
    $('serverClock').textContent = `Hora oficial del servidor: ${data.server_time}`;
    $('mChecadas').textContent = data.metrics.checadas_hoy;
    $('mPendientes').textContent = data.metrics.pendientes_sync;
    $('mRechazos').textContent = data.metrics.rechazos_hoy;
    $('mEscaneres').textContent = data.metrics.escaneres_activos;
    $('mEnrolados').textContent = data.metrics.empleados_enrolados;
    $('mUbicaciones').textContent = data.metrics.ubicaciones_activas;

    const tPill = $('healthTitanium');
    tPill.className = 'pill ' + (data.titanium.ok ? 'pill-ok' : 'pill-no');
    tPill.textContent = `TITANIUM (${data.titanium.mode}): ${data.titanium.ok ? 'OK' : 'Sin conexión'}`;

    const fPill = $('healthFacial');
    fPill.className = 'pill ' + (data.facial.ok ? 'pill-ok' : 'pill-warn');
    fPill.textContent = `Python ONNX: ${data.facial.ok ? data.facial.mode : 'Detenido'}`;
  }

  async function loadPunches() {
    const { data } = await api('admin_punches');
    if (!data.ok) return;
    $('punchesBody').innerHTML = data.punches.length
      ? data.punches.map((r) => `
        <tr>
          <td>#${r.id_checada}</td>
          <td>${esc(r.fecha_hora_servidor)}</td>
          <td><strong>${esc(r.nombre_empleado)}</strong> (${esc(r.clave_empleado)})<br><small style="color:#15803D;font-weight:600;">Planta: ${esc(r.planta || 'Matriz')}</small></td>
          <td><strong>${esc(r.tipo_evento)}</strong><br><small>${r.metodo_verificacion === 'HUELLA' ? 'Huella Digital' : 'Rostro (' + Number(r.puntaje_facial).toFixed(2) + ')'}</small></td>
          <td>${esc(r.alias_dispositivo || r.id_dispositivo)}</td>
          <td><span class="pill pill-ok">En área permitida</span><br><small>${esc(r.nombre_ubicacion || '')}</small></td>
          <td><span class="pill ${r.estado_sync_titanium === 'SINCRONIZADO' ? 'pill-ok' : 'pill-warn'}">${esc(r.estado_sync_titanium)}</span></td>
        </tr>`).join('')
      : '<tr><td colspan="7" class="muted">Sin checadas registradas aún.</td></tr>';
  }

  function renderDeviceEmployeeSelect(filter = '') {
    const sel = $('devEmpClave');
    if (!sel) return;
    const prevVal = sel.value;
    const q = filter.trim().toLowerCase();
    const list = state.activeEmployees.filter((e) => {
      if (!q) return true;
      return String(e.clave_empleado).includes(q)
        || String(e.nombre_completo || '').toLowerCase().includes(q)
        || String(e.planta || '').toLowerCase().includes(q);
    });
    if (!list.length) {
      sel.innerHTML = '<option value="">Sin empleados activos que coincidan</option>';
      return;
    }
    sel.innerHTML = '<option value="">-- Seleccione empleado (ID y nombre) --</option>'
      + list.map((e) => `<option value="${esc(e.clave_empleado)}">#${esc(e.clave_empleado)} — ${esc(e.nombre_completo)} (${esc(e.planta || 'Sin planta')})</option>`).join('');
    if (prevVal && list.some((e) => String(e.clave_empleado) === String(prevVal))) {
      sel.value = prevVal;
    } else if (q && list.length === 1) {
      sel.value = String(list[0].clave_empleado);
    }
  }

  async function loadActiveEmployeesForSelect() {
    const { data } = await api('admin_employees');
    if (!data.ok || !Array.isArray(data.employees)) return;
    state.activeEmployees = data.employees.filter((e) => String(e.estatus).toLowerCase() === 'alta');
    renderDeviceEmployeeSelect($('devEmpSearch') ? $('devEmpSearch').value : '');
  }

  async function loadDevices() {
    loadActiveEmployeesForSelect();
    const { data } = await api('admin_devices');
    if (!data.ok) return;
    $('invitesBody').innerHTML = data.invites.length
      ? data.invites.map((i) => `
        <tr>
          <td><strong>${esc(i.codigo)}</strong></td>
          <td>${esc(i.alias_dispositivo)}</td>
          <td>${esc(i.tipo_modo === 'INDIVIDUAL' ? 'Escáner Personal' : 'Escáner General')}</td>
          <td>${esc(i.expira_en)}</td>
        </tr>`).join('')
      : '<tr><td colspan="4" class="muted">Sin invitaciones pendientes.</td></tr>';

    $('devicesBody').innerHTML = data.devices.length
      ? data.devices.map((d) => `
        <tr>
          <td><strong>${esc(d.alias_dispositivo)}</strong></td>
          <td>${esc(d.tipo_modo === 'INDIVIDUAL' ? 'Escáner Personal' : 'Escáner General')}</td>
          <td>${d.clave_empleado_asignado ? 'Clave ' + esc(d.clave_empleado_asignado) : 'Todos'}</td>
          <td><span class="pill ${d.estado === 'ACTIVO' ? 'pill-ok' : 'pill-no'}">${esc(d.estado)}</span></td>
          <td>${esc(d.ultimo_uso || 'Sin uso')}</td>
          <td>${d.estado === 'ACTIVO'
            ? `<button class="btn-danger-sm" data-revoke-dev="${d.id_dispositivo}">Revocar</button>`
            : '—'}</td>
        </tr>`).join('')
      : '<tr><td colspan="6" class="muted">Sin celulares vinculados todavía.</td></tr>';

    document.querySelectorAll('[data-revoke-dev]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        if (!confirm('¿Revocar este celular? Ya no podrá registrar checadas.')) return;
        await api('admin_device_revoke', 'POST', { id_dispositivo: Number(btn.dataset.revokeDev) });
        loadDevices();
        loadDashboard();
      });
    });
  }

  async function loadEmployees(q = '') {
    const { data } = await api('admin_employees', 'GET', null, q ? { q } : null);
    if (!data.ok) {
      $('employeesBody').innerHTML = `<tr><td colspan="6" class="muted">${esc(data.mensaje || 'Error cargando empleados.')}</td></tr>`;
      return;
    }
    $('employeesBody').innerHTML = data.employees.map((e) => {
      const isAlta = String(e.estatus).toLowerCase() === 'alta';
      const hasTitPhoto = Boolean(e.tiene_foto_titanium);
      return `
        <tr>
          <td><strong>${esc(e.clave_empleado)}</strong></td>
          <td><strong>${esc(e.nombre_completo)}</strong><br><small style="color:#26533D;font-weight:600;">Planta: ${esc(e.planta || 'Matriz')}</small> · <small>${esc(e.departamento || '')}</small></td>
          <td><span class="pill ${isAlta ? 'pill-ok' : 'pill-no'}">${esc(e.estatus)}</span></td>
          <td>${hasTitPhoto ? '<span class="pill pill-ok">Con foto en TITANIUM</span>' : '<span class="pill pill-muted">Sin foto</span>'}</td>
          <td>${e.enrolado ? '<span class="pill pill-ok">Listo para comparar</span>' : (hasTitPhoto ? '<span class="pill pill-warn">Pendiente sincronizar</span>' : '<span class="pill pill-muted">Sin foto en TITANIUM</span>')}</td>
          <td>
            ${isAlta && hasTitPhoto ? `<button class="btn-outline-sm" data-sync-emp="${e.clave_empleado}">Actualizar</button>` : '—'}
          </td>
        </tr>`;
    }).join('');

    document.querySelectorAll('[data-sync-emp]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const clave = Number(btn.dataset.syncEmp);
        btn.disabled = true;
        showEnrollFeedback(true, `Leyendo foto oficial de Clave #${clave} desde Acceso 3 TITANIUM...`);
        const { data: res } = await api('admin_sync_titanium_faces', 'POST', { clave_empleado: clave });
        btn.disabled = false;
        if (res.ok) {
          showEnrollFeedback(true, `Clave #${clave} lista para comparación facial usando su foto de Acceso 3 TITANIUM.`);
          loadEmployees($('empSearch').value);
          loadDashboard();
        } else {
          showEnrollFeedback(false, res.mensaje || 'No se pudo procesar la foto de TITANIUM.');
        }
      });
    });
  }

  function initGeoMap() {
    if (state.geoMap || typeof L === 'undefined') return;
    const initLat = Number($('locLat').value) || 19.60786;
    const initLon = Number($('locLon').value) || -98.9891;
    const initRad = Number($('locRadius').value) || 250;

    state.geoMap = L.map('geoMap', { zoomControl: true }).setView([initLat, initLon], 16);
    const streetsLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 19,
      attribution: '&copy; Esri World Street Map',
    });
    const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: 19,
      attribution: '&copy; Esri World Imagery',
    });
    streetsLayer.addTo(state.geoMap);
    L.control.layers({ 'Calles': streetsLayer, 'Satélite': satelliteLayer }).addTo(state.geoMap);

    state.savedLayerGroup = L.layerGroup().addTo(state.geoMap);

    const pinIcon = L.divIcon({
      className: '',
      html: '<div class="map-pin-icon"></div>',
      iconSize: [26, 26],
      iconAnchor: [13, 26],
    });

    state.draftMarker = L.marker([initLat, initLon], { draggable: true, icon: pinIcon }).addTo(state.geoMap);
    state.draftCircle = L.circle([initLat, initLon], {
      radius: initRad,
      color: '#1B4332',
      weight: 2.5,
      fillColor: '#2E6349',
      fillOpacity: 0.22,
    }).addTo(state.geoMap);

    const syncFromLatLng = (latlng) => {
      $('locLat').value = latlng.lat.toFixed(7);
      $('locLon').value = latlng.lng.toFixed(7);
      state.draftMarker.setLatLng(latlng);
      state.draftCircle.setLatLng(latlng);
    };

    state.geoMap.on('click', (e) => syncFromLatLng(e.latlng));
    state.draftMarker.on('drag', (e) => state.draftCircle.setLatLng(e.target.getLatLng()));
    state.draftMarker.on('dragend', (e) => syncFromLatLng(e.target.getLatLng()));
  }

  function updateDraftMapVisual(fitView = false) {
    if (!state.geoMap || !state.draftMarker || !state.draftCircle) return;
    const lat = Number($('locLat').value);
    const lon = Number($('locLon').value);
    const rad = Math.max(15, Number($('locRadius').value) || 100);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return;

    $('locRadiusLabel').textContent = `${rad} m`;
    $('locRadiusRange').value = String(Math.min(1500, Math.max(20, rad)));

    const ll = L.latLng(lat, lon);
    state.draftMarker.setLatLng(ll);
    state.draftCircle.setLatLng(ll);
    state.draftCircle.setRadius(rad);

    if (fitView) {
      const bounds = state.draftCircle.getBounds();
      state.geoMap.fitBounds(bounds, { padding: [36, 36], maxZoom: 17 });
    }
  }

  function renderSavedCirclesOnMap() {
    if (!state.geoMap || !state.savedLayerGroup) return;
    state.savedLayerGroup.clearLayers();
    state.locationsMap.forEach((loc) => {
      const active = Number(loc.activo) === 1;
      const circle = L.circle([Number(loc.latitud), Number(loc.longitud)], {
        radius: Number(loc.radio_metros),
        color: active ? '#2E6349' : '#7B8A80',
        weight: 1.5,
        dashArray: active ? null : '5,5',
        fillColor: active ? '#4A7C62' : '#94A39B',
        fillOpacity: active ? 0.12 : 0.06,
      });
      circle.bindTooltip(`${loc.nombre} (${loc.radio_metros} m)`, { sticky: true });
      circle.on('click', () => selectLocationForEdit(loc));
      circle.addTo(state.savedLayerGroup);
    });
  }

  function selectLocationForEdit(loc) {
    state.selectedLocId = Number(loc.id_ubicacion);
    $('locId').value = String(loc.id_ubicacion);
    $('locFormTitle').textContent = `Editando: ${loc.nombre}`;
    $('locName').value = loc.nombre;
    $('locLat').value = Number(loc.latitud).toFixed(7);
    $('locLon').value = Number(loc.longitud).toFixed(7);
    $('locRadius').value = String(loc.radio_metros);
    $('locAcc').value = String(loc.precision_max_metros);
    updateDraftMapVisual(true);
    renderLocationsTable();
  }

  function resetLocationForm() {
    state.selectedLocId = null;
    $('locId').value = '';
    $('locFormTitle').textContent = 'Nueva Área Permitida';
    $('locName').value = '';
    $('locFeedback').classList.add('hidden');
    renderLocationsTable();
  }

  function renderLocationsTable() {
    const locs = Array.from(state.locationsMap.values());
    $('locationsBody').innerHTML = locs.map((l) => {
      const isSel = Number(l.id_ubicacion) === state.selectedLocId;
      return `
      <tr class="${isSel ? 'row-selected' : ''}">
        <td>
          <strong>${esc(l.nombre)}</strong><br>
          <small style="color:#54695C;">${Number(l.latitud).toFixed(5)}, ${Number(l.longitud).toFixed(5)}</small>
        </td>
        <td><strong>${esc(l.radio_metros)} m</strong><br><small>GPS &le; ${esc(l.precision_max_metros)} m</small></td>
        <td><span class="pill ${Number(l.activo) ? 'pill-ok' : 'pill-no'}">${Number(l.activo) ? 'Activa' : 'Inactiva'}</span></td>
        <td>
          <button class="btn-outline-sm" data-focus-loc="${Number(l.id_ubicacion)}">Ver en mapa</button>
          <button class="btn-outline-sm" data-toggle-loc="${Number(l.id_ubicacion)}">${Number(l.activo) ? 'Desactivar' : 'Activar'}</button>
        </td>
      </tr>`;
    }).join('');

    document.querySelectorAll('[data-focus-loc]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const loc = state.locationsMap.get(Number(btn.dataset.focusLoc));
        if (loc) selectLocationForEdit(loc);
      });
    });

    document.querySelectorAll('[data-toggle-loc]').forEach((btn) => {
      btn.addEventListener('click', async () => {
        const loc = state.locationsMap.get(Number(btn.dataset.toggleLoc));
        if (!loc) return;
        await api('admin_location_save', 'POST', { ...loc, activo: Number(loc.activo) ? 0 : 1 });
        loadLocations();
        loadDashboard();
      });
    });
  }

  async function loadLocations() {
    initGeoMap();
    setTimeout(() => { if (state.geoMap) state.geoMap.invalidateSize(); }, 120);

    const { data } = await api('admin_locations');
    if (!data.ok) return;
    state.locationsMap.clear();
    data.locations.forEach((l) => state.locationsMap.set(Number(l.id_ubicacion), l));
    renderLocationsTable();
    renderSavedCirclesOnMap();

    // Centrar automáticamente en la primera ubicación activa al abrir el mapa por primera vez
    if (state.selectedLocId === null && data.locations.length > 0) {
      const firstActive = data.locations.find((l) => Number(l.activo) === 1) || data.locations[0];
      selectLocationForEdit(firstActive);
    }
  }

  async function loadRejections() {
    const { data } = await api('admin_rejections');
    if (!data.ok) return;
    $('rejectionsBody').innerHTML = data.rejections.length
      ? data.rejections.map((r) => `
        <tr>
          <td>${esc(r.fecha_hora_servidor)}</td>
          <td><span class="pill pill-no">${esc(r.motivo_rechazo)}</span></td>
          <td>${esc(r.alias_dispositivo || 'No autorizado')}</td>
          <td>${r.clave_empleado_intento ? 'Clave ' + esc(r.clave_empleado_intento) : '—'}</td>
          <td>${r.dentro_area_permitida === null ? '—' : (Number(r.dentro_area_permitida) ? 'En área' : 'Fuera de área')}</td>
          <td>${esc(r.detalle || '')}</td>
          <td>${esc(r.ip_origen)}</td>
        </tr>`).join('')
      : '<tr><td colspan="7" class="muted">Sin rechazos registrados.</td></tr>';
  }

  function showEnrollFeedback(ok, msg) {
    const el = $('enrollFeedback');
    el.className = 'alert ' + (ok ? 'ok' : 'err');
    el.textContent = msg;
    el.classList.remove('hidden');
  }

  function bindEvents() {
    $('loginForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      $('loginError').classList.add('hidden');
      const { data } = await api('admin_login', 'POST', {
        usuario: $('loginUser').value,
        password: $('loginPass').value,
      });
      if (!data.ok) {
        $('loginError').textContent = data.mensaje || 'Acceso denegado.';
        $('loginError').classList.remove('hidden');
        return;
      }
      state.admin = data.admin;
      state.csrf = data.csrf_token;
      enterApp();
    });

    $('btnLogout').addEventListener('click', async () => {
      await api('admin_logout', 'POST');
      location.reload();
    });

    document.querySelectorAll('.nav-btn').forEach((btn) => {
      btn.addEventListener('click', () => showTab(btn.dataset.tab));
    });

    $('btnRefreshPunches').addEventListener('click', () => { loadDashboard(); loadPunches(); });
    $('btnRefreshRejections').addEventListener('click', loadRejections);

    $('btnRetrySync').addEventListener('click', async () => {
      const { data } = await api('admin_sync_retry', 'POST');
      if (data.ok) {
        alert(`Sincronización TITANIUM -> Procesadas: ${data.sync.processed} | Exitosas: ${data.sync.synced} | Fallidas: ${data.sync.failed}`);
        loadDashboard();
        loadPunches();
      }
    });

    $('devMode').addEventListener('change', () => {
      const isInd = $('devMode').value === 'INDIVIDUAL';
      $('devEmpWrap').classList.toggle('hidden', !isInd);
      if (isInd && !state.activeEmployees.length) {
        loadActiveEmployeesForSelect();
      }
    });

    $('devEmpSearch').addEventListener('input', (e) => {
      renderDeviceEmployeeSelect(e.target.value);
    });

    $('inviteForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const { data } = await api('admin_device_invite', 'POST', {
        alias_dispositivo: $('devAlias').value,
        tipo_modo: $('devMode').value,
        clave_empleado_asignado: $('devEmpClave').value,
      });
      const res = $('inviteResult');
      if (data.ok) {
        res.className = 'alert ok';
        res.innerHTML = `Código de vinculación generado: <strong>${esc(data.invite.codigo)}</strong> (expira: ${esc(data.invite.expira_en)})`;
        res.classList.remove('hidden');
        $('devAlias').value = '';
        loadDevices();
      } else {
        res.className = 'alert err';
        res.textContent = data.mensaje || 'Error al generar invitación.';
        res.classList.remove('hidden');
      }
    });

    $('empSearch').addEventListener('input', (e) => loadEmployees(e.target.value));

    $('btnSyncTitaniumFaces').addEventListener('click', async () => {
      const btn = $('btnSyncTitaniumFaces');
      btn.disabled = true;
      showEnrollFeedback(true, 'Sincronizando vectores faciales desde las fotos vigentes de Acceso 3 TITANIUM...');
      const { data } = await api('admin_sync_titanium_faces', 'POST', { force: false });
      btn.disabled = false;
      if (data.ok && data.summary) {
        const s = data.summary;
        let msg = `Sincronización completada -> Con foto en TITANIUM: ${s.con_foto} | Listos para checar: ${s.listos} | Nuevos/actualizados: ${s.actualizados} | Revocados: ${s.revocados}`;
        if (s.invalidas > 0) {
          msg += ` | Fotos sin rostro claro/omitidas (${s.invalidas}): ${(s.detalles_invalidas || []).join(', ')}`;
        }
        showEnrollFeedback(true, msg);
        loadEmployees($('empSearch').value);
        loadDashboard();
      } else {
        showEnrollFeedback(false, data.mensaje || 'Error al sincronizar fotos desde TITANIUM.');
      }
    });

    $('btnNewLocation').addEventListener('click', resetLocationForm);

    $('locRadiusRange').addEventListener('input', (e) => {
      $('locRadius').value = e.target.value;
      updateDraftMapVisual(false);
    });
    $('locRadius').addEventListener('input', () => updateDraftMapVisual(false));
    $('locLat').addEventListener('change', () => updateDraftMapVisual(true));
    $('locLon').addEventListener('change', () => updateDraftMapVisual(true));

    $('btnUseMyLocation').addEventListener('click', () => {
      if (!navigator.geolocation) return alert('Geolocalización no disponible en este navegador.');
      navigator.geolocation.getCurrentPosition((p) => {
        $('locLat').value = p.coords.latitude.toFixed(7);
        $('locLon').value = p.coords.longitude.toFixed(7);
        updateDraftMapVisual(true);
      });
    });

    $('locationForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const idVal = Number($('locId').value) || 0;
      const { data } = await api('admin_location_save', 'POST', {
        id_ubicacion: idVal > 0 ? idVal : undefined,
        nombre: $('locName').value,
        latitud: Number($('locLat').value),
        longitud: Number($('locLon').value),
        radio_metros: Number($('locRadius').value),
        precision_max_metros: Number($('locAcc').value),
        activo: 1,
      });
      const fb = $('locFeedback');
      fb.className = 'alert ' + (data.ok ? 'ok' : 'err');
      fb.textContent = data.ok
        ? (idVal > 0 ? 'Área permitida actualizada en el mapa.' : 'Nueva área permitida guardada.')
        : (data.mensaje || 'Error al guardar.');
      fb.classList.remove('hidden');
      if (data.ok) {
        if (data.id_ubicacion) state.selectedLocId = Number(data.id_ubicacion);
        loadLocations();
        loadDashboard();
      }
    });
  }

  function enterApp() {
    $('loginView').classList.add('hidden');
    $('appView').classList.remove('hidden');
    $('adminName').textContent = `${state.admin.nombre} (${state.admin.rol})`;
    showTab('dashboard');
  }

  async function boot() {
    bindEvents();
    const h0 = await api('health');
    if (h0.data && h0.data.app_version) state.appVersion = h0.data.app_version;

    // Auto-actualización instantánea al detectar cualquier cambio en la web
    const checkAppVersion = async () => {
      if (document.hidden) return;
      try {
        const h = await api('health');
        if (h.data && h.data.ok && state.appVersion && h.data.app_version && h.data.app_version !== state.appVersion) {
          location.reload();
        }
      } catch {}
    };
    setInterval(checkAppVersion, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) checkAppVersion(); });

    const { data } = await api('admin_session');
    if (data.ok && data.authenticated) {
      state.admin = data.admin;
      state.csrf = data.csrf_token;
      enterApp();
    }
  }

  boot();
})();
