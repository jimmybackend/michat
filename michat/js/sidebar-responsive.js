/**
 * sidebar-responsive.js
 * Shell responsive del chat.
 * - Desktop: sidebar colapsable persistente.
 * - Tablet/móvil: drawer accesible, bloquea el fondo y se cierra al navegar.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('chat-sidebar');
    if (!sidebar) return;

    const desktopToggle = document.getElementById('sidebar-toggle');
    const mobileToggle = document.getElementById('sidebar-toggle-mobile');
    const backdrop = document.getElementById('sidebar-backdrop');
    const mobileMedia = window.matchMedia('(max-width: 1023.98px)');
    let collapsed = localStorage.getItem('chat2-sidebar-collapsed') === 'true';

    const isMobile = () => mobileMedia.matches;

    function setDrawerOpen(open) {
      if (!isMobile()) open = false;
      sidebar.classList.toggle('open', !!open);
      backdrop?.classList.toggle('visible', !!open);
      document.body.classList.toggle('sidebar-mobile-open', !!open);
      mobileToggle?.setAttribute('aria-expanded', open ? 'true' : 'false');
      sidebar.setAttribute('aria-hidden', isMobile() && !open ? 'true' : 'false');
    }

    function applyDesktopCollapsed() {
      if (isMobile()) {
        sidebar.classList.remove('collapsed');
        return;
      }
      sidebar.classList.toggle('collapsed', collapsed);
      sidebar.setAttribute('aria-hidden', 'false');
    }

    function sync() {
      if (isMobile()) {
        sidebar.classList.remove('collapsed');
        setDrawerOpen(false);
      } else {
        setDrawerOpen(false);
        applyDesktopCollapsed();
      }
    }

    desktopToggle?.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (isMobile()) return;
      collapsed = !collapsed;
      localStorage.setItem('chat2-sidebar-collapsed', collapsed ? 'true' : 'false');
      applyDesktopCollapsed();
    });

    mobileToggle?.setAttribute('aria-expanded', 'false');
    mobileToggle?.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      if (!isMobile()) return;
      setDrawerOpen(!sidebar.classList.contains('open'));
    });

    backdrop?.addEventListener('click', () => setDrawerOpen(false));

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isMobile() && sidebar.classList.contains('open')) {
        setDrawerOpen(false);
        mobileToggle?.focus();
      }
    });

    sidebar.addEventListener('click', function (event) {
      if (!isMobile()) return;
      const navigates = event.target.closest('.sb-item, .btn-create-project, #sbNewChat, #sbManageProjects');
      if (navigates && !event.target.closest('.js-rename,.js-archive,.js-restore')) {
        window.setTimeout(() => setDrawerOpen(false), 30);
      }
    });

    mobileMedia.addEventListener?.('change', sync);
    window.addEventListener('resize', sync, { passive: true });
    window.addEventListener('pageshow', sync);
    sync();
  });
})();
