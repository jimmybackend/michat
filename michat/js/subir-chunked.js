// subir-chunked.js
document.addEventListener('DOMContentLoaded', function () {
  const API = window.UPLOAD_API || 'api/upload.php';
  const CHUNK_SIZE = 15 * 1024 * 1024; // 15MB
  const MAX_RETRIES = 5;

  const input = document.getElementById('archivoGrande');
  const fileName = document.getElementById('fileNameGrande');
  const btnSubir = document.getElementById('btnSubirGrande');
  const btnCancelar = document.getElementById('btnCancelarGrande');
  const spinner = document.getElementById('spinnerGrande');
  const btnTxt = document.getElementById('btnTxtGrande');
  const result = document.getElementById('uploadResultGrande');
  const progressBar = document.getElementById('progressBarGrande');
  const progressInner = document.getElementById('progressBarGrandeInner');

  let cancelRequested = false;

  // ---------- LocalStorage para reanudar ----------
  const LS_PREFIX = 's3v2_chunked_';

  function fileFingerprint(file) {
    return [file.name, String(file.size), String(file.lastModified || 0)].join('|');
  }

  function loadSession(file) {
    const k = LS_PREFIX + fileFingerprint(file);
    try {
      const raw = localStorage.getItem(k);
      return raw ? JSON.parse(raw) : null;
    } catch (e) {
      return null;
    }
  }

  function saveSession(file, sessionObj) {
    const k = LS_PREFIX + fileFingerprint(file);
    try {
      localStorage.setItem(k, JSON.stringify(sessionObj));
    } catch (e) {}
  }

  function clearSession(file) {
    const k = LS_PREFIX + fileFingerprint(file);
    try { localStorage.removeItem(k); } catch (e) {}
  }

  // ---------- UI ----------
  if (input) {
    input.addEventListener('change', function () {
      const f = input.files && input.files[0] ? input.files[0] : null;
      if (!f) {
        if (fileName) fileName.textContent = '';
        setStatus('Selecciona un archivo y pulsa Subir (15MB)', 'muted');
        return;
      }

      if (fileName) fileName.textContent = 'Archivo: ' + f.name + ' (' + formatFileSize(f.size) + ')';

      const saved = loadSession(f);
      if (saved && saved.uploadId && saved.key) {
        setStatus('Sesión previa detectada. Puedes reanudar la subida.', 'warning');
      } else {
        setStatus('Listo para subir por partes (15MB).', 'muted');
      }
    });
  }

  if (btnSubir) {
    btnSubir.addEventListener('click', function () {
      subirChunked().catch(function (e) {
        console.error(e);
      });
    });
  }

  if (btnCancelar) {
    btnCancelar.addEventListener('click', function () {
      cancelRequested = true;
      setStatus('Cancelando… (puedes reanudar luego)', 'warning');
      btnCancelar.disabled = true;
    });
  }

  // ---------- Main ----------
  async function subirChunked() {
    const file = input && input.files ? input.files[0] : null;
    if (!file) {
      setStatus('❌ Selecciona un archivo.', 'danger');
      return;
    }

    cancelRequested = false;
    toggleBusy(true);
    showProgress(true);
    setProgress(0);

    let uploadId = null;
    let key = null;
    let stateId = null;

    try {
      // 0) Si hay sesión guardada => reanudar
      const saved = loadSession(file);
      if (saved && saved.uploadId && saved.key && saved.stateId) {
        uploadId = saved.uploadId;
        key = saved.key;
        stateId = saved.stateId;
        setStatus('Reanudando sesión anterior…', 'primary');
      } else {
        // 1) INIT
        setStatus('Iniciando multipart…', 'primary');

        const initBody = new URLSearchParams();
        initBody.append('filename', file.name);
        initBody.append('filesize', String(file.size));
        initBody.append('mime', file.type || 'application/octet-stream');

        const initResp = await fetch(API + '?mode=chunked&action=init', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          body: initBody.toString()
        });

        const initJson = await safeJson(initResp);
        if (!initResp.ok || !initJson || !initJson.uploadId || !initJson.key) {
          throw new Error((initJson && initJson.error) ? initJson.error : ('init falló HTTP ' + initResp.status));
        }

        uploadId = initJson.uploadId;
        key = initJson.key;
        stateId = initJson.stateId || '';

        // Guardar sesión
        saveSession(file, {
          stateId: stateId,
          uploadId: uploadId,
          key: key,
          filename: file.name,
          filesize: file.size,
          lastModified: file.lastModified || 0,
          createdAt: Date.now()
        });
      }

      // 2) RESUME: consultar partes ya subidas
      let existingEtags = {};
      try {
        const resumeBody = new URLSearchParams();
        resumeBody.append('step', 'resume');
        if (stateId) resumeBody.append('stateId', stateId);
        resumeBody.append('uploadId', uploadId);
        resumeBody.append('key', key);

        const resumeResp = await fetch(API + '?mode=chunked&action=part', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          body: resumeBody.toString()
        });

        const resumeJson = await safeJson(resumeResp);
        if (resumeResp.ok && resumeJson && resumeJson.etags) {
          existingEtags = resumeJson.etags || {};
        }
      } catch (e) {
        // no bloquea
      }

      // 3) Upload loop
      const totalParts = Math.ceil(file.size / CHUNK_SIZE);
      const etags = {}; // partNumber => etag sin comillas

      // mezclar lo reanudable
      for (const kPart in existingEtags) {
        if (Object.prototype.hasOwnProperty.call(existingEtags, kPart)) {
          etags[kPart] = existingEtags[kPart];
        }
      }

      // bytes ya subidos (aprox exacto por tamaño de cada parte)
      let uploadedBytes = 0;
      for (const partStr in etags) {
        const p = parseInt(partStr, 10);
        if (!isFinite(p) || p <= 0) continue;
        const start = (p - 1) * CHUNK_SIZE;
        const end = Math.min(file.size, start + CHUNK_SIZE);
        uploadedBytes += (end - start);
      }
      setProgress(Math.floor((uploadedBytes / file.size) * 100));

      setStatus('Subiendo partes…', 'primary');

      for (let partNumber = 1; partNumber <= totalParts; partNumber++) {
        if (cancelRequested) throw new Error('Cancelado por el usuario.');

        // saltar si ya estaba subida
        if (etags[String(partNumber)]) {
          setStatus('Reanudado: parte ' + partNumber + '/' + totalParts + ' ya estaba subida.', 'muted');
          continue;
        }

        const start = (partNumber - 1) * CHUNK_SIZE;
        const end = Math.min(file.size, start + CHUNK_SIZE);
        const chunk = file.slice(start, end);
        const contentLength = end - start;

        // 3a) pedir URL firmada para esta parte
        const signBody = new URLSearchParams();
        signBody.append('step', 'sign');
        signBody.append('uploadId', uploadId);
        signBody.append('key', key);
        signBody.append('partNumber', String(partNumber));
        signBody.append('contentLength', String(contentLength));

        const signResp = await fetch(API + '?mode=chunked&action=part', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With': 'XMLHttpRequest'
          },
          credentials: 'same-origin',
          body: signBody.toString()
        });

        const signJson = await safeJson(signResp);
        if (!signResp.ok || !signJson || !signJson.url) {
          throw new Error((signJson && signJson.error) ? signJson.error : ('sign falló HTTP ' + signResp.status));
        }

        // 3b) subir chunk (con reintento + progreso real)
        setStatus('Subiendo parte ' + partNumber + '/' + totalParts + '…', 'primary');

        const etag = await putChunkWithRetry(signJson.url, chunk, function (loaded) {
          const totalLoaded = uploadedBytes + loaded;
          const pct = Math.floor((totalLoaded / file.size) * 100);
          setProgress(pct);
        }, MAX_RETRIES);

        etags[String(partNumber)] = etag;
        uploadedBytes += contentLength;

        setProgress(Math.floor((uploadedBytes / file.size) * 100));

        // guardar progreso para reanudar si se cae la señal
        saveSession(file, {
          stateId: stateId,
          uploadId: uploadId,
          key: key,
          filename: file.name,
          filesize: file.size,
          lastModified: file.lastModified || 0,
          etags: etags,
          updatedAt: Date.now()
        });
      }

      // 4) COMPLETE
      setStatus('Completando multipart…', 'primary');

      const completeBody = new URLSearchParams();
      if (stateId) completeBody.append('stateId', stateId);
      completeBody.append('uploadId', uploadId);
      completeBody.append('key', key);
      completeBody.append('etags', JSON.stringify(etags));

      const completeResp = await fetch(API + '?mode=chunked&action=complete', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin',
        body: completeBody.toString()
      });

      const completeJson = await safeJson(completeResp);
      if (!completeResp.ok || !completeJson || completeJson.ok === false) {
        throw new Error((completeJson && completeJson.error) ? completeJson.error : ('complete falló HTTP ' + completeResp.status));
      }

      setProgress(100);
      setStatus('✅ Subido completo: ' + key + ' (' + formatFileSize(file.size) + ')', 'success');

      // refrescar listado
      if (typeof window.actualizarBloqueArchivos === 'function') {
        window.actualizarBloqueArchivos();
      }

      // limpiar sesión y UI
      clearSession(file);
      if (input) input.value = '';
      if (fileName) fileName.textContent = '';

      toggleBusy(false);
      return;

    } catch (e) {
      const msg = (e && e.message) ? e.message : String(e);
      setStatus('❌ Error: ' + msg, 'danger');
      console.error(e);
      // dejamos la sesión guardada para reanudar
    } finally {
      toggleBusy(false);
    }
  }

  // ---------- PUT XHR (progreso + ETag) ----------
  function putChunkXHR(url, blob, onProgress) {
    return new Promise(function (resolve, reject) {
      const xhr = new XMLHttpRequest();
      xhr.open('PUT', url, true);

      xhr.upload.onprogress = function (evt) {
        if (evt.lengthComputable && typeof onProgress === 'function') {
          onProgress(evt.loaded);
        }
      };

      xhr.onload = function () {
        if (xhr.status >= 200 && xhr.status < 300) {
          // Necesitamos ETag para completar multipart
          // OJO: requiere CORS ExposeHeaders: ETag
          let etag = xhr.getResponseHeader('ETag') || xhr.getResponseHeader('etag') || '';
          etag = String(etag).replace(/"/g, '').trim();
          if (!etag) {
            return reject(new Error('No se recibió ETag. Revisa CORS del bucket (ExposeHeaders: ETag).'));
          }
          resolve(etag);
        } else {
          reject(new Error('PUT chunk falló HTTP ' + xhr.status));
        }
      };

      xhr.onerror = function () {
        reject(new Error('Error de red en PUT chunk'));
      };

      xhr.send(blob);
    });
  }

  function sleep(ms) {
    return new Promise(function (r) { setTimeout(r, ms); });
  }

  async function putChunkWithRetry(url, blob, onProgress, maxRetries) {
    maxRetries = maxRetries || 5;
    let attempt = 0;
    let lastErr = null;

    while (attempt < maxRetries) {
      if (cancelRequested) throw new Error('Cancelado por el usuario.');

      try {
        const etag = await putChunkXHR(url, blob, onProgress);
        return etag;
      } catch (e) {
        lastErr = e;
        attempt++;

        if (attempt >= maxRetries) break;

        const wait = 800 * attempt; // backoff simple
        setStatus('Fallo en chunk. Reintentando (' + attempt + '/' + maxRetries + ')…', 'warning');
        await sleep(wait);
      }
    }

    throw lastErr || new Error('Falló PUT chunk tras reintentos');
  }

  // ---------- UI helpers ----------
  function toggleBusy(busy) {
    if (btnSubir) btnSubir.disabled = !!busy;
    if (btnCancelar) btnCancelar.disabled = !busy;

    if (spinner) spinner.classList[busy ? 'remove' : 'add']('d-none');
    if (btnTxt) btnTxt.textContent = busy ? 'Subiendo…' : 'Subir (15MB)';

    if (!busy) cancelRequested = false;
  }

  function showProgress(show) {
    if (!progressBar) return;
    progressBar.style.display = show ? 'block' : 'none';
  }

  function setProgress(pct) {
    pct = Math.max(0, Math.min(100, parseInt(pct, 10) || 0));
    if (progressInner) progressInner.style.width = pct + '%';
  }

  function setStatus(message, kind) {
    if (!result) return;
    const classes = {
      muted: 'text-muted small',
      primary: 'text-primary small',
      success: 'text-success small',
      danger: 'text-danger small',
      warning: 'text-warning small'
    };
    result.className = classes[kind] || classes.muted;
    result.textContent = message;
  }

  async function safeJson(resp) {
    try { return await resp.json(); } catch (e) { return null; }
  }

  function formatFileSize(bytes) {
    bytes = Number(bytes);
    if (!isFinite(bytes) || bytes <= 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    const num = bytes / Math.pow(k, i);
    return (Math.round(num * 100) / 100) + ' ' + sizes[i];
  }
});