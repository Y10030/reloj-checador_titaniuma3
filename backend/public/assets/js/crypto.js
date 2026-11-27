/**
 * Criptografía del dispositivo (Web Crypto ECDSA P-256 en IndexedDB)
 * + Biometría nativa de Huella Digital / Face ID del celular (WebAuthn platform).
 */
const DeviceCrypto = (() => {
  const DB_NAME = 'TitaniumChecadorCryptoDB';
  const STORE = 'keys';
  const KEY_ID = 'device_ecdsa_p256';
  const META_STORAGE = 'titanium_device_meta_v1';

  function openDb() {
    return new Promise((resolve, reject) => {
      const req = indexedDB.open(DB_NAME, 1);
      req.onupgradeneeded = () => {
        if (!req.result.objectStoreNames.contains(STORE)) {
          req.result.createObjectStore(STORE);
        }
      };
      req.onsuccess = () => resolve(req.result);
      req.onerror = () => reject(req.error);
    });
  }

  async function saveKeyPair(keyPair) {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).put(keyPair, KEY_ID);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  async function loadKeyPair() {
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readonly');
      const req = tx.objectStore(STORE).get(KEY_ID);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror = () => reject(req.error);
    });
  }

  async function clearAll() {
    localStorage.removeItem(META_STORAGE);
    const db = await openDb();
    return new Promise((resolve, reject) => {
      const tx = db.transaction(STORE, 'readwrite');
      tx.objectStore(STORE).delete(KEY_ID);
      tx.oncomplete = () => resolve();
      tx.onerror = () => reject(tx.error);
    });
  }

  function bufferToBase64(buf) {
    const bytes = new Uint8Array(buf);
    let bin = '';
    for (let i = 0; i < bytes.byteLength; i++) bin += String.fromCharCode(bytes[i]);
    return btoa(bin);
  }

  function bufferToBase64Url(buf) {
    return bufferToBase64(buf).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function base64UrlToBytes(str) {
    const pad = str.length % 4;
    const base64 = (str + (pad ? '='.repeat(4 - pad) : '')).replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(base64);
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  function spkiToPem(spkiBuffer) {
    const b64 = bufferToBase64(spkiBuffer);
    const lines = b64.match(/.{1,64}/g) || [];
    return `-----BEGIN PUBLIC KEY-----\n${lines.join('\n')}\n-----END PUBLIC KEY-----`;
  }

  async function generateAndStoreKeyPair() {
    if (!window.crypto || !window.crypto.subtle) {
      throw new Error('Web Crypto API requiere conexión segura HTTPS (Cloudflare Tunnel) o localhost.');
    }
    const keyPair = await window.crypto.subtle.generateKey(
      { name: 'ECDSA', namedCurve: 'P-256' },
      false,
      ['sign', 'verify']
    );
    const spki = await window.crypto.subtle.exportKey('spki', keyPair.publicKey);
    await saveKeyPair(keyPair);
    return spkiToPem(spki);
  }

  async function signPayload(payloadString) {
    const pair = await loadKeyPair();
    if (!pair || !pair.privateKey) {
      throw new Error('No se encontró la clave privada del dispositivo. Vuelva a vincular el equipo.');
    }
    const data = new TextEncoder().encode(payloadString);
    const signature = await window.crypto.subtle.sign(
      { name: 'ECDSA', hash: { name: 'SHA-256' } },
      pair.privateKey,
      data
    );
    return bufferToBase64(signature);
  }

  /**
   * Registra la Huella Digital / Face ID nativa del teléfono vía WebAuthn (platform).
   */
  async function registerPlatformFingerprint(claveEmpleado, nombreEmpleado) {
    if (!window.PublicKeyCredential || !navigator.credentials) {
      throw new Error('Este navegador no soporta huella digital (WebAuthn). Abra la app por HTTPS en Chrome o Safari.');
    }
    const challenge = window.crypto.getRandomValues(new Uint8Array(32));
    const userId = new TextEncoder().encode(`emp_${claveEmpleado}`);
    const cred = await navigator.credentials.create({
      publicKey: {
        challenge,
        rp: { name: 'Pallets La Esperanza · Planta Coacalco' },
        user: {
          id: userId,
          name: `emp_${claveEmpleado}`,
          displayName: nombreEmpleado || `Empleado ${claveEmpleado}`,
        },
        pubKeyCredParams: [{ type: 'public-key', alg: -7 }], // ES256 (ECDSA P-256)
        authenticatorSelection: {
          authenticatorAttachment: 'platform',
          userVerification: 'required',
          residentKey: 'preferred',
        },
        timeout: 60000,
      },
    });
    if (!cred) throw new Error('Registro de huella cancelado.');
    let publicKeyPem = null;
    if (cred.response && typeof cred.response.getPublicKey === 'function') {
      const spki = cred.response.getPublicKey();
      if (spki) publicKeyPem = spkiToPem(spki);
    }
    return {
      credential_id: bufferToBase64Url(cred.rawId),
      public_key_pem: publicKeyPem,
    };
  }

  /**
   * Solicita la Huella Digital / Face ID del teléfono firmando el nonce del servidor.
   */
  async function verifyPlatformFingerprint(credentialId, nonceString) {
    if (!window.PublicKeyCredential || !navigator.credentials) {
      throw new Error('Este navegador no soporta huella digital (WebAuthn).');
    }
    const challenge = new TextEncoder().encode(nonceString);
    const assertion = await navigator.credentials.get({
      publicKey: {
        challenge,
        allowCredentials: [
          { type: 'public-key', id: base64UrlToBytes(credentialId) },
        ],
        userVerification: 'required',
        timeout: 60000,
      },
    });
    if (!assertion) throw new Error('Verificación de huella cancelada.');
    return {
      credential_id: bufferToBase64Url(assertion.rawId),
      client_data_json: bufferToBase64Url(assertion.response.clientDataJSON),
      authenticator_data: bufferToBase64Url(assertion.response.authenticatorData),
      signature: bufferToBase64Url(assertion.response.signature),
    };
  }

  function saveDeviceMeta(meta) { localStorage.setItem(META_STORAGE, JSON.stringify(meta)); }
  function getDeviceMeta() {
    try { const raw = localStorage.getItem(META_STORAGE); return raw ? JSON.parse(raw) : null; }
    catch { return null; }
  }

  function uuidV4() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
  }

  return {
    generateAndStoreKeyPair,
    signPayload,
    loadKeyPair,
    registerPlatformFingerprint,
    verifyPlatformFingerprint,
    saveDeviceMeta,
    getDeviceMeta,
    clearAll,
    uuidV4,
  };
})();
