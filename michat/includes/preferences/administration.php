<?php
$canRuntimeReset = false;
$workerPanelData = null;
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
// Panel de control del worker bajo demanda (solo superusuario; reutiliza auth+CSRF existentes).
if ($canRuntimeReset) {
    try {
        require_once dirname(__DIR__) . '/Tasks/bootstrap.php';
        $workerRuntimeConfig = TaskWorkerRuntimeConfig::fromEnvironment();
        $workerActivityObj = new TaskWorkerActivity($workerRuntimeConfig->activityFile);
        $workerPanelData = [
            'status' => $workerActivityObj->getStatus(),
            'idle' => $workerActivityObj->getIdleSeconds(),
            'timeout' => $workerRuntimeConfig->idleTimeoutSeconds,
            'pending' => TaskWorkerWorkload::getPendingCount($db_connection),
            'activeExecutions' => TaskWorkerWorkload::hasActiveExecutions($db_connection),
        ];
    } catch (Throwable) {
        $workerPanelData = null;
    }
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

<?php if ($canRuntimeReset && $workerPanelData !== null): ?>
<?php
    $wActive = (bool)($workerPanelData['status']['active'] ?? false);
    $wPid = $wActive ? (int)($workerPanelData['status']['pid'] ?? 0) : 0;
    $wMem = $wActive ? (float)($workerPanelData['status']['memory_mb'] ?? 0) : 0.0;
    $wTs = (int)($workerPanelData['status']['timestamp'] ?? 0);
    $wIdleRaw = (int)$workerPanelData['idle'];
    $wIdle = $wIdleRaw >= PHP_INT_MAX / 2 ? null : $wIdleRaw;
    $wRemain = ($wActive && $wIdle !== null) ? max(0, (int)$workerPanelData['timeout'] - $wIdle) : null;
    $wHasActive = (bool)$workerPanelData['activeExecutions'];
    $fmtIdle = static function (?int $s): string {
        if ($s === null) return '—';
        $m = intdiv($s, 60); $sec = $s % 60;
        return $m > 0 ? "{$m}m {$sec}s" : "{$sec}s";
    };
?>
<section class="settings-card">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Worker de tareas</span>
            <h6>Control bajo demanda</h6>
        </div>
        <span class="settings-card-badge"><i class="fas fa-microchip"></i> On-demand</span>
    </div>
    <p class="small text-muted mb-3">
        El worker se enciende automáticamente al crear tareas y se apaga solo tras
        <?= (int)$workerPanelData['timeout'] ?> s de inactividad. Consume 0 MB cuando está apagado.
    </p>
    <table class="table table-sm small mb-3">
        <tbody>
            <tr><th scope="row">Estado</th><td id="workerStateCell"><?= $wActive ? '<span class="text-success font-weight-bold">● Activo</span>' : '<span class="text-secondary">○ Apagado</span>' ?></td></tr>
            <tr><th scope="row">PID</th><td><?= $wPid > 0 ? htmlspecialchars((string)$wPid, ENT_QUOTES) : '—' ?></td></tr>
            <tr><th scope="row">RAM aproximada</th><td><?= $wActive ? number_format($wMem, 1) . ' MiB' : '0 MiB' ?></td></tr>
            <tr><th scope="row">Última actividad</th><td><?= $wTs > 0 ? htmlspecialchars(gmdate('Y-m-d H:i:s', $wTs) . ' UTC', ENT_QUOTES) . " (hace {$fmtIdle($wIdle)})" : '—' ?></td></tr>
            <tr><th scope="row">Tareas pendientes</th><td><?= (int)$workerPanelData['pending'] ?></td></tr>
            <tr><th scope="row">Autoapagado en</th><td><?= $wRemain !== null ? $fmtIdle($wRemain) : '—' ?></td></tr>
        </tbody>
    </table>
    <div class="settings-action-row d-flex gap-2">
        <button id="btnWorkerStart" class="btn btn-sm btn-outline-success" type="button" <?= $wActive ? 'disabled' : '' ?>>
            <i class="fas fa-power-off mr-1"></i> Encender worker
        </button>
        <button id="btnWorkerStop" class="btn btn-sm btn-outline-danger" type="button" <?= (!$wActive || $wHasActive) ? 'disabled' : '' ?>
            title="<?= $wHasActive ? 'No se puede apagar con ejecuciones activas' : 'Apaga el servicio systemd del worker' ?>">
            <i class="fas fa-stop mr-1"></i> Apagar worker
        </button>
    </div>
</section>

<script>
(() => {
    const csrfToken = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const startBtn = document.getElementById('btnWorkerStart');
    const stopBtn = document.getElementById('btnWorkerStop');
    if ((!startBtn || !stopBtn) && startBtn.dataset.bound !== '1') {}
    [startBtn, stopBtn].forEach((btn) => {
        if (!btn || btn.dataset.bound === '1') return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', async () => {
            const action = btn === startBtn ? 'start' : 'stop';
            if (action === 'stop' && !window.confirm('¿Apagar el worker ahora? Las tareas pendientes se procesarán al volver a encenderlo.')) return;
            btn.disabled = true;
            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> …';
            try {
                const body = new URLSearchParams();
                body.set('csrf_token', csrfToken);
                body.set('action', action);
                const response = await fetch('worker_control.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: body.toString()
                });
                let payload = null;
                try { payload = await response.json(); } catch (_) {}
                if (response.status === 409) {
                    window.alert('No se puede apagar: hay ejecuciones activas que deben terminar primero.');
                } else if (!response.ok || !payload || payload.ok !== true) {
                    throw new Error((payload && payload.error) ? payload.error : `HTTP ${response.status}`);
                }
                window.location.reload();
            } catch (error) {
                console.error('Worker control:', error);
                window.alert(`No se pudo ${action === 'start' ? 'encender' : 'apagar'} el worker: ${error.message}`);
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            }
        });
    });
})();
</script>
<?php endif; ?>

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
