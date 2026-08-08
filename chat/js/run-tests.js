/**
 * Ejecución de Tests desde el Chat
 */
(function() {
    'use strict';

    const messagesContainer = document.getElementById('chat2Messages');
    if (messagesContainer) {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType === 1 && node.classList && node.classList.contains('chat-assistant')) {
                        const testCmdMatch = node.innerHTML.match(/data-test-command="([^"]+)"/);
                        if (testCmdMatch && !node.querySelector('.btn-run-tests')) {
                            injectTestButton(node, testCmdMatch[1]);
                        }
                    }
                });
            });
        });
        observer.observe(messagesContainer, { childList: true, subtree: true });
    }

    function injectTestButton(messageNode, testCommand) {
        const btnContainer = document.createElement('div');
        btnContainer.className = 'mt-2 d-flex align-items-center gap-2';
        btnContainer.innerHTML = `
            <button class="btn btn-sm btn-outline-success btn-run-tests" data-command="${escapeHtml(testCommand)}">
                <i class="fas fa-vial"></i> 🧪 Correr Tests
            </button>
            <small class="text-muted ml-2">
                <code style="font-size:0.7rem;">${escapeHtml(testCommand)}</code>
            </small>
        `;
        messageNode.appendChild(btnContainer);

        const btn = btnContainer.querySelector('.btn-run-tests');
        btn.addEventListener('click', () => executeTests(btn, testCommand));
    }

    async function executeTests(button, testCommand) {
        const originalHtml = button.innerHTML || '<i class="fas fa-vial"></i>';
        const sessionId = getCurrentSessionId();
        const projectId = getCurrentProjectId();

        if (!projectId) {
            showToast('⚠️ Atención', 'Debes seleccionar un proyecto primero.', 'warning');
            if (button.id === 'btnRunTestsManual') {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
            return;
        }

        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Ejecutando...';
        button.classList.remove('btn-outline-success');
        button.classList.add('btn-outline-warning');

        try {
            const formData = new FormData();
            formData.append('session_id', sessionId || 0);
            formData.append('project_id', projectId);
            formData.append('test_command', testCommand);

            const response = await fetch('chat/run_tests.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            const result = await response.json();

            if (result.ok) {
                button.innerHTML = '<i class="fas fa-check"></i> Completado';
                button.classList.remove('btn-outline-warning');
                button.classList.add('btn-outline-info');
                
                appendTestResultToChat(result, testCommand);
                
                const statusIcon = result.status === 'ok' ? '✅' : '⚠️';
                showToast(
                    `${statusIcon} Tests ${result.status === 'ok' ? 'Exitosos' : 'Fallaron'}`,
                    `${result.files_processed} archivos en ${(result.duration_ms / 1000).toFixed(2)}s`,
                    result.status === 'ok' ? 'success' : 'warning'
                );
            } else {
                throw new Error(result.error || 'Error desconocido');
            }
        } catch (error) {
            button.innerHTML = '<i class="fas fa-times"></i> Error';
            button.classList.remove('btn-outline-warning');
            button.classList.add('btn-outline-danger');
            showToast('❌ Error ejecutando tests', error.message, 'danger');
        } finally {
            button.disabled = false;
            setTimeout(() => {
                button.innerHTML = originalHtml;
                button.classList.remove('btn-outline-info', 'btn-outline-danger');
                button.classList.add('btn-outline-success');
            }, 5000);
        }
    }

    function appendTestResultToChat(result, command) {
        if (!messagesContainer) return;

        const msgDiv = document.createElement('div');
        msgDiv.className = 'chat-msg assistant';
        
        const formattedOutput = escapeHtml(result.output)
            .replace(/\n/g, '<br>')
            .replace(/(✅|OK|PASS)/gi, '<span style="color:#00ff66; font-weight:bold;">$1</span>')
            .replace(/(❌|FAIL|ERROR)/gi, '<span style="color:#ff5a5a; font-weight:bold;">$1</span>');

        const statusBadge = result.status === 'ok' 
            ? '<span class="badge badge-success">✅ PASSED</span>'
            : result.status === 'timeout'
            ? '<span class="badge badge-warning">⏱️ TIMEOUT</span>'
            : '<span class="badge badge-danger">❌ FAILED</span>';

        msgDiv.innerHTML = `
            <div class="chat-md">
                <div class="d-flex align-items-center mb-2">
                    <i class="fas fa-vial text-info mr-2"></i>
                    <strong>Resultado de Ejecución de Tests</strong>
                    ${statusBadge}
                    <small class="text-muted ml-auto">${(result.duration_ms / 1000).toFixed(2)}s</small>
                </div>
                <div class="small text-muted mb-2">
                    <code>${escapeHtml(command)}</code>
                </div>
                <pre style="background:#050505; color:#dbe4ee; padding:0.75rem; border-radius:6px; max-height:300px; overflow-y:auto; font-size:0.75rem; border:1px solid rgba(0,255,102,0.2);">${formattedOutput}</pre>
                <div class="mt-2 small text-muted">
                    📁 ${result.files_processed} archivos procesados desde S3
                </div>
            </div>
        `;
        
        messagesContainer.appendChild(msgDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function injectManualTestButton() {
        const toolGroup = document.querySelector('.card-footer .btn-group[role="group"]');
        if (!toolGroup) return;

        if (document.getElementById('btnRunTestsManual')) return;

        const btn = document.createElement('button');
        btn.id = 'btnRunTestsManual';
        btn.className = 'btn btn-sm btn-outline-success';
        btn.title = 'Ejecutar Tests del Proyecto';
        btn.innerHTML = '<i class="fas fa-vial"></i>';
        
        btn.addEventListener('click', () => {
            const projectId = getCurrentProjectId();
            if (!projectId) {
                showToast('⚠️ Atención', 'Debes seleccionar un proyecto primero.', 'warning');
                return;
            }

            const defaultCmd = 'vendor/bin/phpunit';
            const testCommand = prompt("Ingresa el comando de tests a ejecutar:", defaultCmd);
            
            if (!testCommand || testCommand.trim() === '') return;

            const fakeBtn = document.createElement('button');
            executeTests(fakeBtn, testCommand.trim());
        });

        toolGroup.appendChild(btn);
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

    function getCurrentSessionId() {
        if (typeof window.currentSessionId !== 'undefined') return window.currentSessionId;
        return null;
    }

    function getCurrentProjectId() {
        const projectSelect = document.getElementById('chat2Project');
        if (projectSelect && projectSelect.value) return parseInt(projectSelect.value);
        if (typeof window.currentProjectId !== 'undefined') return parseInt(window.currentProjectId);
        return 0;
    }

    injectManualTestButton();

})();
