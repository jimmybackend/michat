/**
 * sidebar-responsive.js
 * Comportamiento responsive del panel lateral (#chat-sidebar):
 * - Escritorio: colapsar/expandir con el botón #sidebar-toggle (persistido en localStorage).
 * - Móvil/tablet: drawer off-canvas abierto con #sidebar-toggle-mobile, con backdrop y cierre por Escape/click fuera.
 * Portado del diseño premium de chat (jimmybackend/chat), incluyendo el fix de z-index/collapsed-open
 * que evitaba que el sidebar quedara mal posicionado o bloqueado en móvil/tablet.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('chat-sidebar');
    if (!sidebar) return;

    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebarToggleMobile = document.getElementById('sidebar-toggle-mobile');
    const sidebarBackdrop = document.getElementById('sidebar-backdrop');
    const mobileMediaQuery = window.matchMedia('(max-width: 900px)');

    let collapsed = localStorage.getItem('chat2-sidebar-collapsed') === 'true';

    function isMobileViewport() {
      return mobileMediaQuery.matches;
    }

    function applyCollapsedState() {
      // "collapsed" es exclusivo de escritorio; en móvil el sidebar usa "open" (drawer)
      if (collapsed && !isMobileViewport()) {
        sidebar.classList.add('collapsed');
      } else {
        sidebar.classList.remove('collapsed');
      }
    }

    function syncResponsiveState() {
      if (!isMobileViewport()) {
        // Forzar cierre del drawer al volver a escritorio
        sidebar.classList.remove('open');
        sidebarBackdrop?.classList.remove('visible');
      }
      applyCollapsedState();
    }

    function toggleSidebar() {
      collapsed = !collapsed;
      localStorage.setItem('chat2-sidebar-collapsed', collapsed);
      applyCollapsedState();
    }

    function openSidebarMobile() {
      if (!isMobileViewport()) return;
      sidebar.classList.remove('collapsed');
      sidebar.classList.add('open');
      sidebarBackdrop?.classList.add('visible');
    }

    function closeSidebarMobile() {
      sidebar.classList.remove('open');
      sidebarBackdrop?.classList.remove('visible');
    }

    function toggleSidebarMobile() {
      if (!isMobileViewport()) return;
      if (sidebar.classList.contains('open')) {
        closeSidebarMobile();
      } else {
        openSidebarMobile();
      }
    }

    sidebarToggle?.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleSidebar();
    });

    sidebarToggleMobile?.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleSidebarMobile();
    });

    sidebarBackdrop?.addEventListener('click', closeSidebarMobile);

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isMobileViewport() && sidebar.classList.contains('open')) {
        closeSidebarMobile();
      }
    });

    // Cerrar el drawer al navegar/seleccionar un chat o proyecto en móvil
    sidebar.addEventListener('click', function (e) {
      if (!isMobileViewport()) return;
      const item = e.target.closest('.sb-item, .accordion-header');
      if (item) closeSidebarMobile();
    });

    mobileMediaQuery.addEventListener('change', syncResponsiveState);
    window.addEventListener('resize', syncResponsiveState, { passive: true });

    syncResponsiveState();
  });
})();
