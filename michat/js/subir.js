document.addEventListener('DOMContentLoaded', function () {
  // API base (ideal: definido en s3.php)
  const API = window.UPLOAD_API || 'api/upload.php';

  // =========================
  // SUBIDA LOCAL (API: local_put)
  // =========================
  const form = document.getElementById('uploadForm');
  const input = document.getElementById('archivo');
  const uploadResult = document.getElementById('uploadResult');
  const fileName = document.getElementById('fileName');
  const spinner = document.getElementById('spinner');
  const buttonText = document.getElementById('buttonText');
  const progressBar = document.getElementById('progressBar');
  const progressBarInner = progressBar ? progressBar.querySelector('.progress-bar') : null;

  if (input) {
    input.addEventListener('change', function () {
      if (this.files && this.files.length > 0) {
        const file = this.files[0];
        if (fileName) fileName.textContent = 'Archivo seleccionado: ' + file.name + ' (' + formatFileSize(file.size) + ')';
        setStatus(uploadResult, 'Presiona "Subir Archivo" para continuar', 'muted');
      } else {
        if (fileName) fileName.textContent = '';
        clearStatus(uploadResult);
      }
    });
  }

  if (form) {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      const archivo = input && input.files ? input.files[0] : null;

      if (!archivo) {
        showErrorLocal('❌ Selecciona un archivo.');
        return;
      }

      setStatus(uploadResult, 'Subiendo...', 'primary');
      toggleButton(form.querySelector('button'), true, spinner, buttonText, 'Subiendo...');
      if (progressBar) progressBar.style.display = 'block';

      try {
        // 1) Obtener URL firmada (backend usa la ruta de sesión)
        const nombre = encodeURIComponent(archivo.name);
        const resFirma = await fetch(API + '?mode=local_put&action=init&nombre=' + nombre, {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!resFirma.ok) throw new Error('Error HTTP init local_put: ' + resFirma.status);

        const json = await safeJson(resFirma);
        if (!json || !json.url) throw new Error('Error al obtener URL firmada');

        // 2) PUT directo a S3 (fetch no expone progreso real)
        const putResp = await fetch(json.url, {
          method: 'PUT',
          headers: { 'Content-Type': archivo.type || 'application/octet-stream' },
          body: archivo
        });
        if (!putResp.ok) throw new Error('Fallo al subir a S3: HTTP ' + putResp.status);

        // Simular barra
        if (progressBarInner) await simulateProgress(progressBarInner);

        // 3) Avisar tamaño real (no bloquea si falla)
        try {
          const body = new URLSearchParams();
          body.append('nombreEncriptado', json.nombreEncriptado || '');
          body.append('tamano', String(archivo.size || 0));

          await fetch(API + '?mode=local_put&action=complete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
            credentials: 'same-origin',
            body: body.toString()
          });
        } catch (e) {
          console.warn('complete local_put falló:', e);
        }

        // 4) OK
        setStatus(
          uploadResult,
          '✅ Subido correctamente.\nArchivo: ' + archivo.name + '\nTamaño: ' + formatFileSize(archivo.size),
          'success'
        );

        // 5) Limpia UI
        if (input) input.value = '';
        if (fileName) fileName.textContent = '';

        // 6) Refresca listado si existe
        if (typeof window.actualizarBloqueArchivos === 'function') {
          window.actualizarBloqueArchivos();
        }
      } catch (error) {
        showErrorLocal('❌ Error en subida: ' + ((error && error.message) ? error.message : error));
        console.error(error);
      } finally {
        toggleButton(form.querySelector('button'), false, spinner, buttonText, 'Subir Archivo');
        if (progressBar) progressBar.style.display = 'none';
        if (progressBarInner) progressBarInner.style.width = '0%';
      }
    });
  }

  function showErrorLocal(message) {
    setStatus(uploadResult, message, 'danger');
    if (form) toggleButton(form.querySelector('button'), false, spinner, buttonText, 'Subir Archivo');
    if (progressBar) progressBar.style.display = 'none';
    if (progressBarInner) progressBarInner.style.width = '0%';
  }

  // ========================================
  // SUBIR DESDE URL (API: remote_url)
  // ========================================
  const urlInput = document.getElementById('urlRemota');
  const btnSubirUrl = document.getElementById('btnSubirUrl');
  const spinnerUrl = document.getElementById('spinnerUrl');
  const btnTxtUrl = document.getElementById('btnTxtUrl');
  const uploadUrlResult = document.getElementById('uploadUrlResult');

  if (btnSubirUrl && urlInput) {
    btnSubirUrl.addEventListener('click', subirDesdeUrl);
    urlInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        subirDesdeUrl();
      }
    });
  }

  async function subirDesdeUrl() {
    const url = (urlInput && urlInput.value ? urlInput.value : '').trim();
    if (!url) {
      setStatus(uploadUrlResult, '❌ Proporciona una URL.', 'danger');
      return;
    }

    toggleButton(btnSubirUrl, true, spinnerUrl, btnTxtUrl, 'Subiendo...');
    setStatus(uploadUrlResult, 'Descargando y subiendo a S3...', 'primary');

    // Timeout de seguridad
    const controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    const timeout = setTimeout(function () {
      if (controller) controller.abort();
    }, 300000); // 5 min

    try {
      const body = new URLSearchParams();
      body.append('url', url);
      body.append('u64', toBase64Utf8(url));

      let resp = await fetch(API + '?mode=remote_url&action=init', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString(),
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
      });

      // Fallback GET con u64 (cuando hay WAF/hosting raro)
      if (!resp.ok) {
        console.warn('POST remote_url falló:', resp.status, resp.statusText);
        let raw1 = '';
        try { raw1 = await resp.text(); } catch (e) {}
        if (raw1) console.warn('Respuesta POST:', raw1.slice(0, 500));

        const qs = new URLSearchParams({ u64: toBase64Utf8(url) }).toString();
        resp = await fetch(API + '?mode=remote_url&action=init&' + qs, {
          method: 'GET',
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          signal: controller ? controller.signal : undefined
        });
      }

      const json = await safeJson(resp);

      // Si no es OK o no hay JSON esperado
      if (!resp.ok || !(json && (json.ok === true || json.ok === 'true' || json.key))) {
        let raw = '';
        try { raw = await resp.text(); } catch (e) {}
        const msg = (json && json.error) ? json.error
          : raw ? ('HTTP ' + resp.status + ': ' + raw.slice(0, 500))
          : ('HTTP ' + resp.status);
        throw new Error(msg);
      }

      const bytes = Number(json.bytes || 0);
      const mb = (bytes > 0) ? (bytes / 1024 / 1024).toFixed(2) : '0.00';

      setStatus(
        uploadUrlResult,
        '✅ Listo: <code>' + escapeHtml(json.key) + '</code> (' + mb + ' MB)'
          + (json.nombreOriginal ? '<br>Original: ' + escapeHtml(json.nombreOriginal) : '')
          + (json.nombreEncriptado ? '<br>Encriptado: ' + escapeHtml(json.nombreEncriptado) : ''),
        'success',
        true
      );

      if (typeof window.actualizarBloqueArchivos === 'function') {
        window.actualizarBloqueArchivos();
      }
    } catch (e) {
      const msg = (e && e.name === 'AbortError')
        ? 'Tiempo de espera agotado.'
        : ((e && e.message) ? e.message : e);
      setStatus(uploadUrlResult, '❌ Error: ' + msg, 'danger', false);
      console.error(e);
    } finally {
      clearTimeout(timeout);
      toggleButton(btnSubirUrl, false, spinnerUrl, btnTxtUrl, 'Subir');
    }
  }

  // ===================
  // Helpers comunes UI
  // ===================
  function formatFileSize(bytes) {
    bytes = Number(bytes);
    if (!isFinite(bytes) || bytes <= 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    const num = bytes / Math.pow(k, i);
    return (Math.round(num * 100) / 100) + ' ' + sizes[i];
  }

  function simulateProgress(progressElement) {
    return new Promise(function (resolve) {
      if (!progressElement) return resolve();
      let width = 0;
      const interval = setInterval(function () {
        if (width >= 100) {
          clearInterval(interval);
          resolve();
        } else {
          width += 5;
          progressElement.style.width = width + '%';
        }
      }, 100);
    });
  }

  function toggleButton(btn, disabled, spinnerEl, labelEl, textWhenEnabled) {
    if (!btn) return;
    btn.disabled = !!disabled;
    if (spinnerEl) spinnerEl.classList[disabled ? 'remove' : 'add']('d-none');
    if (labelEl) labelEl.textContent = disabled ? 'Procesando...' : (textWhenEnabled || 'Aceptar');
  }

  function setStatus(el, message, kind, asHtml) {
    if (!el) return;
    const classes = {
      muted: 'text-muted small',
      primary: 'text-primary small',
      success: 'text-success small',
      danger: 'text-danger small',
      warning: 'text-warning small'
    };
    el.className = classes[kind] || classes.muted;
    if (asHtml) el.innerHTML = message;
    else el.textContent = message;
  }

  function clearStatus(el) {
    if (!el) return;
    el.textContent = '';
    el.className = '';
  }

  async function safeJson(resp) {
    try { return await resp.json(); }
    catch (e) { return null; }
  }

  // Base64 UTF-8 seguro (con fallback si no existe TextEncoder)
  function toBase64Utf8(str) {
    str = String(str);

    // Moderno
    if (typeof TextEncoder !== 'undefined') {
      const bytes = new TextEncoder().encode(str);
      let bin = '';
      for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
      return btoa(bin);
    }

    // Fallback viejo: encodeURIComponent
    // (convierte UTF-8 a bytes simulados)
    return btoa(unescape(encodeURIComponent(str)));
  }

  function escapeHtml(str) {
    str = String(str);
    // Sin replaceAll (compat)
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }
});