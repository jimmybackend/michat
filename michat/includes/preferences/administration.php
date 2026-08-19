<div class="settings-pane-intro settings-pane-intro-administration">
    <div class="settings-pane-icon"><i class="fas fa-shield-alt"></i></div>
    <div>
        <h6 class="mb-1">Administración</h6>
        <p class="mb-0 small text-muted">Herramientas de control de datos y operaciones destructivas separadas del resto de preferencias.</p>
    </div>
</div>

<section class="settings-card">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Datos internos</span>
            <h6>Control de datos de IA</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-database"></i> Administración</span>
    </div>
    <p class="small text-muted mb-3">Inspecciona y administra datos internos de IA desde el controlador avanzado existente.</p>
    <div class="settings-action-row">
        <button id="btnAiDataControl" class="btn btn-sm btn-outline-warning" title="Control avanzado de datos internos de la IA" type="button">
            <i class="fas fa-sliders-h mr-1"></i> Abrir Control IA
        </button>
    </div>
</section>

<?php if ($mostrarTruncate): ?>
<section class="settings-card settings-danger-zone">
    <div class="settings-card-heading">
        <div><span class="settings-card-kicker">Administración</span><h6><i class="fas fa-exclamation-triangle mr-1"></i> Zona peligrosa</h6></div>
    </div>
    <p class="small text-muted mb-3">
        Trunca tablas excepto <strong>Users</strong>, <strong>TokenUsage</strong>, <strong>FileS3</strong>, <strong>S3Folders</strong> y <strong>Projects</strong>.
    </p>
    <div class="settings-action-row">
        <button type="button" onclick="adminTruncateTables('dry_run')" class="btn btn-sm btn-outline-info">
            <i class="fas fa-eye mr-1"></i> Simular
        </button>
        <button type="button" onclick="adminTruncateTables('confirm')" class="btn btn-sm btn-danger">
            <i class="fas fa-trash mr-1"></i> Truncar
        </button>
    </div>
    <pre id="truncate-result" class="settings-danger-result"></pre>
</section>
<script>
async function adminTruncateTables(mode) {
    const resultBox = document.getElementById('truncate-result');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!token) {
        alert('Falta el token CSRF. Recarga la página.');
        return;
    }

    if (mode === 'confirm') {
        const ok = confirm(
            '⚠️ Esto truncará tablas excepto:\n\n' +
            '• Users\n• TokenUsage\n• FileS3\n• S3Folders\n• Projects\n\n' +
            'Esta acción NO se puede deshacer.\n\n¿Continuar?'
        );
        if (!ok) return;
    }

    const formData = new FormData();
    formData.append('action', 'admin_truncate_tables');
    formData.append('truncate_mode', mode);
    formData.append('csrf_token', token);

    if (resultBox) {
        resultBox.style.display = 'block';
        resultBox.textContent = '⏳ Procesando...';
    }

    try {
        // ✅ APUNTA DIRECTAMENTE A truncate.php
        const response = await fetch('truncate.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const data = await response.json();

        if (!data.ok) {
            throw new Error(data.error || 'Error desconocido.');
        }

        let text = data.message + "\n\n";

        if (Array.isArray(data.tablas) && data.tablas.length) {
            text += (mode === 'confirm' ? "✅ Tablas truncadas:\n" : "🔍 Tablas que se truncarían:\n") + data.tablas.join("\n");
        } else {
            text += "No se procesaron tablas.";
        }

        if (Array.isArray(data.omitidas) && data.omitidas.length) {
            text += "\n\n⚠️ Tablas omitidas o inexistentes:\n" + data.omitidas.join("\n");
        }

        if (resultBox) resultBox.textContent = text;
    } catch (error) {
        if (resultBox) resultBox.textContent = '❌ Error: ' + error.message;
        else alert('Error: ' + error.message);
    }
}
</script>
<?php endif; ?>
