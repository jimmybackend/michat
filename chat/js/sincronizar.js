(() => {
  'use strict';

  function showToast({ title = 'Aviso', message = '', actions = [] } = {}) {
    let host = document.getElementById('chatToasts');
    if (!host) {
      host = document.createElement('div');
      host.id = 'chatToasts';
      host.className = 'chat-toasts';
      document.body.appendChild(host);
    }
    const toast = document.createElement('div');
    toast.className = 'chat-toast';
    const h = document.createElement('div');
    h.className = 'ct-title';
    h.textContent = title;
    toast.appendChild(h);
    if (message) {
      const p = document.createElement('div');
      p.className = 'ct-msg';
      p.textContent = message;
      toast.appendChild(p);
    }
    const bar = document.createElement('div');
    bar.className = 'ct-actions';
    const btnClose = document.createElement('button');
    btnClose.className = 'ct-close';
    btnClose.textContent = 'Cerrar';
    btnClose.addEventListener('click', () => toast.remove());
    bar.appendChild(btnClose);
    (actions || []).forEach(a => {
      const b = document.createElement('button');
      b.textContent = a.label || 'Acción';
      b.addEventListener('click', a.onClick || (()=>{}));
      bar.appendChild(b);
    });
    toast.appendChild(bar);
    host.appendChild(toast);
    setTimeout(() => { if (toast.isConnected) toast.remove(); }, 5000);
  }

  const statusHostId = 'syncStatus';

  async function renderStatusLoading() {
    const host = document.getElementById(statusHostId);
    if (!host) return;
    try {
      const html = await (await fetch('sync_status.php?view=html&loading=1', { cache: 'no-store' })).text();
      host.innerHTML = html;
    } catch {
      host.innerHTML = `
        <div class="sync-box sync-loading sync-green" role="status">
  <div class="sync-line">
    <span class="sync-spinner"></span>
    <strong>Sincronizando…</strong>
  </div>
  <div class="sync-text">
    Comparando S3 y la base de datos.
  </div>
</div>`;
    }
  }

  async function renderStatusFinal() {
    const host = document.getElementById(statusHostId);
    if (!host) return;
    try {
      const html = await (await fetch('sync_status.php?view=html', { cache: 'no-store' })).text();
      host.innerHTML = html;
    } catch {
      host.innerHTML = `<div class="sync-box sync-success sync-green" role="status">
  <div class="sync-line">
    <strong>Estatus actualizado</strong>
  </div>
</div>`;
    }
  }

  async function triggerSyncS3(evt) {
  if (evt) evt.preventDefault();

  const btn  = document.getElementById('btnSyncS3');
  const icon = btn ? btn.querySelector('i') : null;
  const originalHTML = btn ? btn.innerHTML : '';

  const setBtn = (isLoading) => {
    if (!btn) return;
    btn.disabled = isLoading;
    btn.classList.toggle('disabled', isLoading);
    btn.classList.toggle('btn-animando', isLoading);
    if (icon) icon.classList.toggle('fa-rotate-right', isLoading);
    btn.innerHTML = isLoading
      ? (icon ? `<i class="${icon.className}"></i> Sincronizando…` : `⟳ Sincronizando…`)
      : (originalHTML || '<i class="fas fa-rotate"></i> Sincronizar S3');
  };

  setBtn(true);
  await renderStatusLoading();
  showToast({ title: 'Sincronización', message: 'Iniciando…' });

  let ok = false;
  let errMsg = '';

  try {
    const res = await fetch('sync_s3_to_db.php', { method: 'POST', cache: 'no-store' });
    const bodyText = await res.text(); // leemos siempre

    // Si el server devolvió HTTP error
    if (!res.ok) {
      errMsg = `HTTP ${res.status}.`;
      // intenta extraer mensaje si venía JSON
      try {
        const j = JSON.parse(bodyText);
        if (j && j.error) errMsg = j.error;
      } catch {}
      throw new Error(errMsg);
    }

    // Intentar JSON
    let j = null;
    try { j = JSON.parse(bodyText); } catch {
      // si no es JSON, probablemente PHP warning/HTML/redirect
      errMsg = 'Respuesta inválida del servidor (no es JSON). Revisa logs de PHP.';
      throw new Error(errMsg);
    }

    if (j && j.ok === true) {
      ok = true;
    } else {
      errMsg = (j && (j.error || j.message)) ? (j.error || j.message) : 'Error al sincronizar.';
      throw new Error(errMsg);
    }

    await renderStatusFinal();
    showToast({ title: 'Sincronización', message: '✅ Sincronización completada.' });

  } catch (e) {
    console.error(e);
    await renderStatusFinal();
    showToast({ title: 'Sincronización', message: `⚠️ ${errMsg || 'No se pudo sincronizar.'}` });
  } finally {
    setBtn(false);
  }
}

  const btnSync = document.getElementById('btnSyncS3');
  if (btnSync) btnSync.addEventListener('click', triggerSyncS3);
  document.addEventListener('DOMContentLoaded', renderStatusFinal);
  window.triggerSyncS3 = triggerSyncS3;
})();
