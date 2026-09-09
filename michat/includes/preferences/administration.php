<?php
$canRuntimeReset = false;
try {
    if (isset($db_connection) && $db_connection instanceof mysqli) {
        require_once dirname(__DIR__) . '/Auth/AuthorizationService.php';
        require_once dirname(__DIR__) . '/Chat/ChatIdentity.php';
        $runtimeResetUserId = ChatIdentity::resolveUserId($db_connection);
        if ($runtimeResetUserId > 0) {
            $canRuntimeReset = (new AuthorizationService($db_connection))->allows($runtimeResetUserId, 'system.reset');
        }
    }
} catch (Throwable) {
    $canRuntimeReset = false;
}
?>
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

<?php if ($canRuntimeReset): ?>
<section class="settings-card border-danger">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker text-danger">Superadmin</span>
            <h6>Limpiar datos runtime</h6>
        </div>
        <span class="settings-card-badge text-danger"><i class="fas fa-exclamation-triangle"></i> Zona peligrosa</span>
    </div>
    <p class="small text-muted mb-2">
        Limpia las tablas de trabajo del chat, memoria temporal, RAG, trazas y Tasks.
        Conserva usuarios, configuración de IA, preferencias, proyectos, almacenamiento S3,
        memoria procedural y el histórico de uso de tokens.
    </p>
    <p class="small text-danger mb-3">
        Esta operación no se puede deshacer. Antes de ejecutarla se hará una simulación y se mostrará cuántas tablas serán limpiadas.
    </p>
    <div class="settings-action-row">
        <button id="adminTruncateTables" class="btn btn-sm btn-outline-danger" type="button">
            <i class="fas fa-trash-alt mr-1"></i> Limpiar tablas runtime
        </button>
    </div>
</section>

<script>
(() => {
    const button = document.getElementById('adminTruncateTables');
    if (!button || button.dataset.bound === '1') return;
    button.dataset.bound = '1';

    const csrfToken = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    async function resetRequest(mode) {
        const body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('truncate_mode', mode);
        if (mode === 'confirm') body.set('confirm_text', 'RESET_RUNTIME_DATA');

        const response = await fetch('truncate.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
            body: body.toString()
        });

        let payload = null;
        try { payload = await response.json(); } catch (_) {}
        if (!response.ok || !payload || payload.ok !== true) {
            throw new Error(payload?.error || `HTTP ${response.status}`);
        }
        return payload;
    }

    button.addEventListener('click', async () => {
        if (button.disabled) return;
        button.disabled = true;
        const originalHtml = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Revisando…';

        try {
            const preview = await resetRequest('dry_run');
            const resetCount = Array.isArray(preview.tablas) ? preview.tablas.length : 0;
            const preserved = Array.isArray(preview.preservadas) ? preview.preservadas.join(', ') : '';
            const message =
                `Se limpiarán ${resetCount} tablas de runtime.\n\n` +
                `Se conservarán: ${preserved}.\n\n` +
                '¿Deseas continuar?';

            if (!window.confirm(message)) return;
            if (!window.confirm('CONFIRMACIÓN FINAL: se eliminarán los datos runtime. ¿Continuar?')) return;

            button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Limpiando…';
            const result = await resetRequest('confirm');
            window.alert(result.message || 'Datos runtime limpiados correctamente.');
            window.location.reload();
        } catch (error) {
            console.error('Runtime reset:', error);
            window.alert(`No se pudo limpiar la base de datos: ${error.message}`);
        } finally {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    });
})();
</script>
<?php endif; ?>
