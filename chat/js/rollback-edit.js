/**
 * Rollback / Deshacer Última Edición de Archivo
 */
(function() {
    'use strict';

    const btnRollback = document.getElementById('btnRollbackEdit');
    if (!btnRollback) return;

    btnRollback.addEventListener('click', async () => {
        const projectId = getCurrentProjectId();
        
        if (!projectId) {
            showToast('⚠️ Atención', 'Debes seleccionar un proyecto primero.', 'warning');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('project_id', projectId);
            formData.append('action', 'get_recent_edits');

            const res = await fetch('chat/rollback_edit.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            const data = await res.json();

            if (!data.ok || !data.recent_files || data.recent_files.length === 0) {
                showToast('ℹ️ Sin historial', 'No hay ediciones recientes para deshacer en este proyecto.', 'info');
                return;
            }

            showRollbackModal(data.recent_files, projectId);

        } catch (error) {
            console.error('Error obteniendo historial:', error);
            showToast('❌ Error', 'No se pudo cargar el historial de ediciones.', 'danger');
        }
    });

    function showRollbackModal(files, projectId) {
        let modal = document.getElementById('rollbackModal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'rollbackModal';
            modal.className = 'modal fade';
            modal.tabIndex = -1;
            modal.innerHTML = `
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-undo-alt mr-2"></i> Deshacer Edición</h5>
                            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted mb-3">
                                Selecciona el archivo que deseas revertir a su versión anterior. 
                                Esto restaurará el contenido desde S3 y marcará la versión actual como obsoleta.
                            </p>
                            <div id="rollbackFileList" class="list-group" style="max-height: 300px; overflow-y: auto;">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Cancelar</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        const listContainer = modal.querySelector('#rollbackFileList');
        listContainer.innerHTML = '';

        files.forEach(file => {
            const item = document.createElement('a');
            item.href = '#';
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            item.innerHTML = `
                <div>
                    <strong class="text-info"><i class="fas fa-file-code mr-1"></i> ${escapeHtml(file.filename)}</strong>
                    <small class="text-muted d-block">Versión actual: ${escapeHtml(file.current_version)}</small>
                </div>
                <span class="badge badge-warning badge-pill">${escapeHtml(file.edit_count)} ediciones</span>
            `;
            item.addEventListener('click', (e) => {
                e.preventDefault();
                executeRollback(file.filename, projectId, modal);
            });
            listContainer.appendChild(item);
        });

        $(modal).modal('show');
    }

    async function executeRollback(filename, projectId, modal) {
        $(modal).modal('hide');

        showToast('⏳ Revertiendo...', `Restaurando ${filename} a su versión anterior...`, 'info');

        try {
            const formData = new FormData();
            formData.append('project_id', projectId);
            formData.append('target_filename', filename);

            const res = await fetch('chat/rollback_edit.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const result = await res.json();

            if (result.ok) {
                showToast(
                    '✅ Rollback Exitoso', 
                    `${filename} revertido a la versión ${result.restored_version}.`, 
                    'success'
                );

                appendRollbackMessageToChat(filename, result);

                if (typeof loadProjectSources === 'function') {
                    loadProjectSources();
                }
            } else {
                throw new Error(result.error || 'Error desconocido en el rollback');
            }
        } catch (error) {
            showToast('❌ Error en Rollback', error.message, 'danger');
        }
    }

    function appendRollbackMessageToChat(filename, result) {
        const messagesContainer = document.getElementById('chat2Messages');
        if (!messagesContainer) return;

        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-msg assistant';
        msgDiv.innerHTML = `
            <div class="chat-md">
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-undo-alt text-warning mr-2"></i>
                    <strong>Rollback Ejecutado</strong>
                    <span class="badge badge-success ml-2">✅ REVERTIDO</span>
                </div>
                <div class="small text-muted mb-2">
                    <i class="fas fa-file-code mr-1"></i> <code>${escapeHtml(filename)}</code>
                </div>
                <ul class="small mb-0">
                    <li>Versión restaurada: <strong class="text-success">${escapeHtml(result.restored_version)}</strong></li>
                    <li>Versión descartada: <span class="text-danger text-decoration-line-through">${escapeHtml(result.previous_version)}</span></li>
                </ul>
                <div class="mt-2 small text-muted">
                    <i class="fas fa-info-circle mr-1"></i> El archivo ha sido restaurado desde S3. La versión anterior fue marcada como obsoleta.
                </div>
            </div>
        `;
        
        messagesContainer.appendChild(msgDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(title, message, type = 'info') {
        const container = document.getElementById('chatToasts') || document.getElementById('incomingToasts');
        if (!container) {
            alert(`${title}: ${message}`);
            return;
        }

        const toast = document.createElement('div');
        toast.className = 'chat-toast';
        toast.innerHTML = `
            <div class="ct-title">${title}</div>
            <div class="small">${message}</div>
            <div class="ct-actions">
                <button class="ct-close" onclick="this.closest('.chat-toast').remove()">✕</button>
            </div>
        `;
        
        if (type === 'success') toast.style.borderLeftColor = '#00ff66';
        if (type === 'warning') toast.style.borderLeftColor = '#ffd861';
        if (type === 'danger') toast.style.borderLeftColor = '#ff5a5a';
        if (type === 'info') toast.style.borderLeftColor = '#17a2b8';
        
        container.appendChild(toast);
        setTimeout(() => { if (toast.parentNode) toast.remove(); }, 8000);
    }

    function getCurrentProjectId() {
        const projectSelect = document.getElementById('chat2Project');
        if (projectSelect && projectSelect.value) return parseInt(projectSelect.value);
        if (typeof window.currentProjectId !== 'undefined') return parseInt(window.currentProjectId);
        return 0;
    }

})();
