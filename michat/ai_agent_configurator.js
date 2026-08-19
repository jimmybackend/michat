'use strict';

(function ($) {
    const cfg = window.AI_AGENT_CONFIG || {};
    const endpoints = cfg.endpoints || {};
    let agentsById = new Map();
    let groups = [];

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value === null || value === undefined ? '' : String(value);
        return div.innerHTML;
    }

    function escapeAttr(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function formatNullable(value) {
        return value === null || value === undefined || value === '' ? '<span class="text-muted">NULL</span>' : escapeHtml(value);
    }

    function nowLabel() {
        return new Date().toLocaleTimeString('es-MX', { hour12: false });
    }

    function logCrud(action, message, data) {
        const safeAction = escapeHtml(action.toUpperCase());
        const safeMessage = escapeHtml(message);
        let suffix = '';
        if (data && typeof data === 'object') {
            const compact = {};
            ['id_', 'agent_key', 'action', 'count', 'filter'].forEach(key => {
                if (Object.prototype.hasOwnProperty.call(data, key)) compact[key] = data[key];
            });
            if (Object.keys(compact).length) suffix = ' · ' + escapeHtml(JSON.stringify(compact));
        }
        $('#crudLog').prepend(
            `<div class="crud-log-entry"><strong>[${escapeHtml(nowLabel())}] ${safeAction}</strong> — ${safeMessage}${suffix}</div>`
        );
        console.info('[AI Agent CRUD]', action, message, data || '');
    }

    function showToast(title, message, type = 'info') {
        const $toast = $(
            `<div class="agent-toast ${escapeHtml(type)}">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <div class="flex-grow-1">
                    <div class="font-weight-bold">${escapeHtml(title)}</div>
                    <div class="small">${escapeHtml(message)}</div>
                </div>
                <button type="button" class="agent-toast-close" aria-label="Cerrar"><i class="fas fa-times"></i></button>
            </div>`
        );
        $toast.find('.agent-toast-close').on('click', () => $toast.remove());
        $('#agentToastContainer').append($toast);
        setTimeout(() => $toast.remove(), 5500);
    }

    function ajaxErrorMessage(xhr, fallback) {
        if (xhr.responseJSON && xhr.responseJSON.message) return xhr.responseJSON.message;
        try {
            const parsed = JSON.parse(xhr.responseText || '{}');
            if (parsed.message) return parsed.message;
        } catch (_) {}
        return fallback || `HTTP ${xhr.status || 0}: ${xhr.statusText || 'Error de conexión'}`;
    }

    function setLoading() {
        $('#agentsList').html(`
            <div class="agents-table-shell d-flex align-items-center justify-content-center">
                <div class="text-center py-5">
                    <i class="fas fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="text-muted mt-3">Cargando configuraciones...</p>
                </div>
            </div>`);
    }

    function rebuildGroupControls(serverGroups) {
        const selected = $('#filterGroup').val() || '';
        groups = Array.isArray(serverGroups) ? serverGroups : [];

        const options = ['<option value="">Todos los grupos</option>'];
        const datalist = [];
        groups.forEach(group => {
            options.push(`<option value="${escapeAttr(group)}">${escapeHtml(group)}</option>`);
            datalist.push(`<option value="${escapeAttr(group)}"></option>`);
        });
        $('#filterGroup').html(options.join(''));
        $('#agentGroupList').html(datalist.join(''));

        if (selected && groups.includes(selected)) {
            $('#filterGroup').val(selected);
        }
    }

    function renderAgents(agents) {
        agentsById = new Map();
        const rows = [];

        // Defensa adicional: aunque el backend ya usa ORDER BY id_ ASC.
        agents.sort((a, b) => Number(a.id_) - Number(b.id_));

        agents.forEach(agent => {
            agentsById.set(Number(agent.id_), agent);
            const actions = [
                `<button type="button" class="btn btn-sm btn-outline-info js-view" data-id="${agent.id_}" title="Ver"><i class="fas fa-eye"></i></button>`
            ];

            if (cfg.isAdmin) {
                actions.push(`<button type="button" class="btn btn-sm btn-outline-primary js-edit" data-id="${agent.id_}" title="Editar"><i class="fas fa-edit"></i></button>`);
                actions.push(`<button type="button" class="btn btn-sm btn-outline-danger js-delete" data-id="${agent.id_}" title="Eliminar"><i class="fas fa-trash"></i></button>`);
            }

            rows.push(`
                <tr>
                    <td><strong>#${agent.id_}</strong></td>
                    <td><span class="badge badge-info">${escapeHtml(agent.agent_group)}</span></td>
                    <td><code class="agent-key-code">${escapeHtml(agent.agent_key)}</code></td>
                    <td class="cell-ellipsis" title="${escapeAttr(agent.display_name)}">${escapeHtml(agent.display_name)}</td>
                    <td class="cell-ellipsis" title="${escapeAttr(agent.model_id)}"><code class="agent-key-code">${escapeHtml(agent.model_id)}</code></td>
                    <td class="cell-ellipsis" title="${escapeAttr(agent.fallback_model_id || '')}">${formatNullable(agent.fallback_model_id)}</td>
                    <td class="text-center">${formatNullable(agent.temperature)}</td>
                    <td class="text-center">${formatNullable(agent.max_tokens_prompt)}</td>
                    <td class="text-center">${formatNullable(agent.max_tokens_output)}</td>
                    <td class="text-center">${formatNullable(agent.top_p)}</td>
                    <td class="text-center">${formatNullable(agent.seed)}</td>
                    <td class="text-center">${formatNullable(agent.max_attempts)}</td>
                    <td>${formatNullable(agent.token_usage_phase)}</td>
                    <td class="text-center">${escapeHtml(agent.sort_order)}</td>
                    <td class="text-center"><span class="badge ${Number(agent.is_active) === 1 ? 'badge-success' : 'badge-secondary'}">${Number(agent.is_active) === 1 ? 'Activo' : 'Inactivo'}</span></td>
                    <td class="text-center"><div class="btn-group" role="group">${actions.join('')}</div></td>
                </tr>`);
        });

        if (!rows.length) {
            $('#agentsList').html(`
                <div class="agents-table-shell d-flex align-items-center justify-content-center">
                    <div class="text-center py-5">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No hay registros para el filtro seleccionado.</p>
                    </div>
                </div>`);
            return;
        }

        $('#agentsList').html(`
            <div class="agents-table-shell">
                <table class="table table-hover table-sm">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Grupo</th>
                            <th>Agent Key</th>
                            <th>Nombre</th>
                            <th>Modelo</th>
                            <th>Fallback</th>
                            <th>Temp</th>
                            <th>Max Prompt</th>
                            <th>Max Output</th>
                            <th>Top P</th>
                            <th>Seed</th>
                            <th>Intentos</th>
                            <th>Fase</th>
                            <th>Sort</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>${rows.join('')}</tbody>
                </table>
            </div>`);
    }

    function loadAgents(options = {}) {
        const group = options.group !== undefined ? options.group : ($('#filterGroup').val() || '');
        setLoading();

        $.ajax({
            url: endpoints.list,
            method: 'GET',
            dataType: 'json',
            cache: false,
            data: group ? { agent_group: group } : {},
            success(response) {
                if (!response || response.success !== true) {
                    const message = response && response.message ? response.message : 'Respuesta inválida del servidor.';
                    showToast('Error', message, 'error');
                    logCrud('read-error', message);
                    return;
                }

                rebuildGroupControls(response.groups || []);
                if (group) $('#filterGroup').val(group);
                renderAgents(Array.isArray(response.agents) ? response.agents : []);
                $('#recordCounter').text(`${response.count || 0} registro(s) mostrado(s)`);
                logCrud('read', `Consulta completada${group ? ` para grupo "${group}"` : ''}.`, response);
            },
            error(xhr) {
                const message = ajaxErrorMessage(xhr, 'No fue posible cargar los agentes.');
                showToast('Error al cargar', message, 'error');
                logCrud('read-error', message);
                $('#recordCounter').text('Error al cargar');
            }
        });
    }

    function numberOrNull(selector, parser) {
        const raw = String($(selector).val() ?? '').trim();
        if (raw === '') return null;
        const value = parser(raw);
        return Number.isFinite(value) ? value : null;
    }

    function validateJsonField(selector, label) {
        const value = String($(selector).val() ?? '').trim();
        if (value === '') return null;
        try {
            JSON.parse(value);
            return value;
        } catch (error) {
            throw new Error(`${label} debe contener JSON válido: ${error.message}`);
        }
    }

    function collectFormData() {
        return {
            id_: numberOrNull('#agentId', value => parseInt(value, 10)),
            agent_key: $('#agentKey').val().trim(),
            agent_group: $('#agentGroup').val().trim(),
            display_name: $('#displayName').val().trim(),
            description: $('#description').val().trim() || null,
            model_id: $('#modelId').val().trim(),
            fallback_model_id: $('#fallbackModelId').val().trim() || null,
            model_ladder_json: validateJsonField('#modelLadderJson', 'Model Ladder JSON'),
            system_instruction: $('#systemInstruction').val().trim() || null,
            user_prompt_template: $('#userPromptTemplate').val().trim() || null,
            temperature: numberOrNull('#temperature', parseFloat),
            max_tokens_prompt: numberOrNull('#maxTokensPrompt', value => parseInt(value, 10)),
            max_tokens_output: numberOrNull('#maxTokensOutput', value => parseInt(value, 10)),
            top_p: numberOrNull('#topP', parseFloat),
            seed: numberOrNull('#seed', value => parseInt(value, 10)) ?? 0,
            max_attempts: numberOrNull('#maxAttempts', value => parseInt(value, 10)) ?? 1,
            extra_config: validateJsonField('#extraConfig', 'Extra Config JSON'),
            token_usage_phase: $('#tokenUsagePhase').val().trim() || null,
            is_active: $('#isActive').is(':checked') ? 1 : 0,
            sort_order: numberOrNull('#sortOrder', value => parseInt(value, 10)) ?? 0
        };
    }

    function resetForm() {
        const form = document.getElementById('agentForm');
        form.reset();
        $('#agentId').val('');
        $('#sortOrder').val('0');
        $('#seed').val('0');
        $('#maxAttempts').val('1');
        $('#isActive').prop('checked', true);
        $('#btnDeleteFromModal').hide();
    }

    function openCreateModal() {
        resetForm();
        $('#agentModalTitle').html('<i class="fas fa-plus mr-2"></i>Nuevo agente IA');
        $('#agentModal').modal('show');
        logCrud('ui', 'Formulario de alta abierto.');
    }

    function fillForm(agent) {
        resetForm();
        $('#agentId').val(agent.id_);
        $('#agentKey').val(agent.agent_key || '');
        $('#agentGroup').val(agent.agent_group || '');
        $('#displayName').val(agent.display_name || '');
        $('#description').val(agent.description || '');
        $('#modelId').val(agent.model_id || '');
        $('#fallbackModelId').val(agent.fallback_model_id || '');
        $('#modelLadderJson').val(agent.model_ladder_json || '');
        $('#systemInstruction').val(agent.system_instruction || '');
        $('#userPromptTemplate').val(agent.user_prompt_template || '');
        $('#temperature').val(agent.temperature === null ? '' : agent.temperature);
        $('#maxTokensPrompt').val(agent.max_tokens_prompt === null ? '' : agent.max_tokens_prompt);
        $('#maxTokensOutput').val(agent.max_tokens_output === null ? '' : agent.max_tokens_output);
        $('#topP').val(agent.top_p === null ? '' : agent.top_p);
        $('#seed').val(agent.seed ?? 0);
        $('#maxAttempts').val(agent.max_attempts ?? 1);
        $('#extraConfig').val(agent.extra_config || '');
        $('#tokenUsagePhase').val(agent.token_usage_phase || '');
        $('#isActive').prop('checked', Number(agent.is_active) === 1);
        $('#sortOrder').val(agent.sort_order ?? 0);
        $('#btnDeleteFromModal').show();
    }

    function openEditModal(id) {
        const agent = agentsById.get(Number(id));
        if (!agent) return;
        fillForm(agent);
        $('#agentModalTitle').html(`<i class="fas fa-edit mr-2"></i>Editar agente #${agent.id_}`);
        $('#agentModal').modal('show');
        logCrud('ui', `Formulario de edición abierto para #${agent.id_}.`, agent);
    }

    function prettyJson(value) {
        if (!value) return '';
        try { return JSON.stringify(JSON.parse(value), null, 2); } catch (_) { return value; }
    }

    function viewDetails(id) {
        const a = agentsById.get(Number(id));
        if (!a) return;

        $('#agentDetailsContent').html(`
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><th>ID</th><td>#${a.id_}</td></tr>
                        <tr><th>User ID</th><td>${a.user_id_}</td></tr>
                        <tr><th>Agent Key</th><td><code>${escapeHtml(a.agent_key)}</code></td></tr>
                        <tr><th>Grupo</th><td>${escapeHtml(a.agent_group)}</td></tr>
                        <tr><th>Nombre</th><td>${escapeHtml(a.display_name)}</td></tr>
                        <tr><th>Descripción</th><td>${formatNullable(a.description)}</td></tr>
                        <tr><th>Modelo</th><td><code>${escapeHtml(a.model_id)}</code></td></tr>
                        <tr><th>Fallback</th><td>${formatNullable(a.fallback_model_id)}</td></tr>
                        <tr><th>Temperature</th><td>${formatNullable(a.temperature)}</td></tr>
                        <tr><th>Max prompt</th><td>${formatNullable(a.max_tokens_prompt)}</td></tr>
                        <tr><th>Max output</th><td>${formatNullable(a.max_tokens_output)}</td></tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr><th>Top P</th><td>${formatNullable(a.top_p)}</td></tr>
                        <tr><th>Seed</th><td>${formatNullable(a.seed)}</td></tr>
                        <tr><th>Max attempts</th><td>${formatNullable(a.max_attempts)}</td></tr>
                        <tr><th>Token phase</th><td>${formatNullable(a.token_usage_phase)}</td></tr>
                        <tr><th>Activo</th><td>${Number(a.is_active) === 1 ? 'Sí' : 'No'}</td></tr>
                        <tr><th>Sort order</th><td>${escapeHtml(a.sort_order)}</td></tr>
                        <tr><th>Creado</th><td>${formatNullable(a.created_at)}</td></tr>
                        <tr><th>Actualizado</th><td>${formatNullable(a.updated_at)}</td></tr>
                    </table>
                </div>
            </div>
            <h6>Model Ladder JSON</h6><pre class="details-pre">${escapeHtml(prettyJson(a.model_ladder_json)) || 'NULL'}</pre>
            <h6>System Instruction</h6><pre class="details-pre">${escapeHtml(a.system_instruction || '') || 'NULL'}</pre>
            <h6>User Prompt Template</h6><pre class="details-pre">${escapeHtml(a.user_prompt_template || '') || 'NULL'}</pre>
            <h6>Extra Config</h6><pre class="details-pre">${escapeHtml(prettyJson(a.extra_config)) || 'NULL'}</pre>
        `);
        $('#detailsModal').modal('show');
        logCrud('read-detail', `Detalle mostrado para #${a.id_}.`, a);
    }

    function saveAgent() {
        const form = document.getElementById('agentForm');
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        let payload;
        try {
            payload = collectFormData();
        } catch (error) {
            showToast('Validación', error.message, 'error');
            return;
        }

        const isUpdate = payload.id_ !== null;
        $('#btnSaveAgent').prop('disabled', true);

        $.ajax({
            url: endpoints.save,
            method: 'POST',
            contentType: 'application/json; charset=UTF-8',
            dataType: 'json',
            headers: { 'X-CSRF-Token': cfg.csrfToken },
            data: JSON.stringify(payload),
            success(response) {
                if (!response || response.success !== true) {
                    const message = response && response.message ? response.message : 'No se pudo guardar.';
                    showToast('Error al guardar', message, 'error');
                    logCrud(isUpdate ? 'update-error' : 'create-error', message, response);
                    return;
                }
                showToast('Guardado', response.message || 'Operación completada.', 'success');
                logCrud(response.action || (isUpdate ? 'update' : 'create'), response.message || 'Guardado.', response);
                $('#agentModal').modal('hide');
                loadAgents({ group: $('#filterGroup').val() || '' });
            },
            error(xhr) {
                const message = ajaxErrorMessage(xhr, 'No fue posible guardar el agente.');
                showToast('Error al guardar', message, 'error');
                logCrud(isUpdate ? 'update-error' : 'create-error', message);
            },
            complete() {
                $('#btnSaveAgent').prop('disabled', false);
            }
        });
    }

    function deleteAgent(id) {
        const agent = agentsById.get(Number(id));
        if (!agent) return;

        if (!window.confirm(`¿Eliminar el agente #${agent.id_} (${agent.agent_key})?\n\nEsta acción no se puede deshacer.`)) {
            return;
        }

        $.ajax({
            url: endpoints.delete,
            method: 'POST',
            contentType: 'application/json; charset=UTF-8',
            dataType: 'json',
            headers: { 'X-CSRF-Token': cfg.csrfToken },
            data: JSON.stringify({ id_: agent.id_ }),
            success(response) {
                if (!response || response.success !== true) {
                    const message = response && response.message ? response.message : 'No se pudo eliminar.';
                    showToast('Error al eliminar', message, 'error');
                    logCrud('delete-error', message, response);
                    return;
                }
                showToast('Eliminado', response.message || 'Agente eliminado.', 'success');
                logCrud('delete', response.message || 'Agente eliminado.', response);
                $('#agentModal').modal('hide');
                loadAgents({ group: $('#filterGroup').val() || '' });
            },
            error(xhr) {
                const message = ajaxErrorMessage(xhr, 'No fue posible eliminar el agente.');
                showToast('Error al eliminar', message, 'error');
                logCrud('delete-error', message);
            }
        });
    }

    $(document).ready(function () {
        $('#filterGroup').on('change', function () {
            loadAgents({ group: $(this).val() || '' });
        });

        $('#btnReloadAgents').on('click', function () {
            loadAgents({ group: $('#filterGroup').val() || '' });
        });

        $('#btnAddAgent').on('click', openCreateModal);
        $('#btnSaveAgent').on('click', saveAgent);
        $('#btnDeleteFromModal').on('click', function () {
            const id = parseInt($('#agentId').val(), 10);
            if (Number.isFinite(id)) deleteAgent(id);
        });
        $('#btnClearCrudLog').on('click', function () {
            $('#crudLog').html('<div class="text-muted">Registro limpiado.</div>');
        });

        $('#agentsList')
            .on('click', '.js-view', function () { viewDetails($(this).data('id')); })
            .on('click', '.js-edit', function () { openEditModal($(this).data('id')); })
            .on('click', '.js-delete', function () { deleteAgent($(this).data('id')); });

        loadAgents();
    });
})(jQuery);
