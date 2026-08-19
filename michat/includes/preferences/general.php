<div class="settings-pane-intro ">
    <div class="settings-pane-icon"><i class="fas fa-user-cog"></i></div>
    <div>
        <h6 class="mb-1">General</h6>
        <p class="mb-0 small text-muted">Interfaz, transparencia y acciones de sesión para el uso diario.</p>
    </div>
</div>

<section class="settings-card">
    <div class="settings-card-heading">
        <div>
            <span class="settings-card-kicker">Transparencia</span>
            <h6>Actividad del agente</h6>
        </div>
    </div>
    <label class="settings-switch-row mb-0" for="chatActivityEnabled">
        <span class="settings-switch-copy">
            <strong>Mostrar actividad real durante la respuesta</strong>
            <small>Muestra modelos, prompts de la aplicación, RAG, tool calls, tokens y tiempos; no expone razonamiento privado.</small>
        </span>
        <input type="checkbox" id="chatActivityEnabled" checked
               title="Muestra en el chat los eventos reales del pipeline mientras se genera la respuesta.">
    </label>
</section>

<div class="settings-two-column">
    <section class="settings-card mb-0">
        <div class="settings-card-heading">
            <div><span class="settings-card-kicker">Interfaz</span><h6>Apariencia</h6></div>
        </div>
        <div class="settings-field-label mb-2">Modo</div>
        <div class="settings-action-row">
            <button class="btn btn-sm btn-outline-secondary js-set-mode" data-mode="theme-dark" type="button">
                <i class="fas fa-moon mr-1"></i> Oscuro
            </button>
            <button class="btn btn-sm btn-outline-secondary js-set-mode" data-mode="theme-light" type="button">
                <i class="fas fa-sun mr-1"></i> Claro
            </button>
        </div>
    </section>

    <section class="settings-card mb-0">
        <div class="settings-card-heading">
            <div><span class="settings-card-kicker">Sesión</span><h6>Cuenta</h6></div>
        </div>
        <div class="settings-action-row">
            <button id="btnRecargar" class="btn btn-sm btn-outline-secondary" onclick="recargarPagina()" type="button">
                <i class="fas fa-sync-alt mr-1"></i> Recargar página
            </button>
            <a href="logout.php" class="btn btn-sm btn-outline-danger">
                <i class="fas fa-sign-out-alt mr-1"></i> Cerrar sesión
            </a>
        </div>
    </section>
</div>
