// chat2-enhancements.js
(function () {
  'use strict';

  function $(selector, root = document) {
    return root.querySelector(selector);
  }

  function $$(selector, root = document) {
    return Array.from(root.querySelectorAll(selector));
  }

  function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text);
    }

    return new Promise((resolve, reject) => {
      try {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.top = '-9999px';
        ta.style.left = '-9999px';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand('copy');
        document.body.removeChild(ta);
        if (!ok) throw new Error('No se pudo copiar');
        resolve();
      } catch (err) {
        reject(err);
      }
    });
  }

  function getCodeLanguage(codeEl, preEl) {
    const fromPre = (preEl?.getAttribute('data-lang') || '').trim();
    if (fromPre) return fromPre;

    const className = codeEl?.className || '';
    const match = className.match(/\blanguage\-([a-z0-9_+-]+)\b/i);
    if (match) return match[1];

    return '';
  }

  function buildToolbar(preEl, codeEl) {
    const wrap = document.createElement('div');
    wrap.className = 'chat-code-shell';

    const toolbar = document.createElement('div');
    toolbar.className = 'chat-code-toolbar';

    const lang = document.createElement('span');
    lang.className = 'chat-code-lang';
    lang.textContent = getCodeLanguage(codeEl, preEl) || 'code';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'chat-code-copy-btn';
    btn.innerHTML = '<span class="label-default">Copiar</span><span class="label-done">Copiado ✔</span>';

    btn.addEventListener('click', async () => {
      const raw = codeEl.innerText || codeEl.textContent || '';
      try {
        await copyText(raw);
        btn.classList.add('is-copied');
        window.clearTimeout(btn._copiedTimer);
        btn._copiedTimer = window.setTimeout(() => {
          btn.classList.remove('is-copied');
        }, 1600);
      } catch (err) {
        btn.classList.remove('is-copied');
        console.error('Error copiando código:', err);
      }
    });

    toolbar.appendChild(lang);
    toolbar.appendChild(btn);

    preEl.parentNode.insertBefore(wrap, preEl);
    wrap.appendChild(toolbar);
    wrap.appendChild(preEl);
  }

  function enhanceCodeBlocks(scope) {
    const root = scope || document;
    const blocks = $$('.chat-md pre', root);

    blocks.forEach((preEl) => {
      if (preEl.dataset.enhanced === '1') return;

      let codeEl = $('code', preEl);
      if (!codeEl) {
        codeEl = document.createElement('code');
        codeEl.textContent = preEl.textContent || '';
        preEl.textContent = '';
        preEl.appendChild(codeEl);
      }

      preEl.dataset.enhanced = '1';
      buildToolbar(preEl, codeEl);
    });
  }

  function enhanceTables(scope) {
    const root = scope || document;
    const tables = $$('.chat-md table', root);

    tables.forEach((table) => {
      if (table.closest('.chat-table-wrap')) return;

      const wrap = document.createElement('div');
      wrap.className = 'chat-table-wrap';
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
    });
  }

  function enhanceMessageNode(node) {
    if (!(node instanceof HTMLElement)) return;
    if (node.matches('.chat-msg, .chat-md')) {
      enhanceCodeBlocks(node);
      enhanceTables(node);
      return;
    }
    enhanceCodeBlocks(node);
    enhanceTables(node);
  }

  function initChat2Enhancements() {
    const messages = document.getElementById('chat2Messages');
    if (!messages) return;

    enhanceCodeBlocks(messages);
    enhanceTables(messages);

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => enhanceMessageNode(node));
      });
    });

    observer.observe(messages, {
      childList: true,
      subtree: true
    });
  }

  window.addEventListener('DOMContentLoaded', initChat2Enhancements);
})();