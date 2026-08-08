/**
 * sidebar-responsive.js
 * Comportamiento responsive del panel lateral (#chat-sidebar):
 * - Escritorio: colapsar/expandir con el botón #sidebar-toggle (persistido en localStorage).
 * - Móvil/tablet: drawer off-canvas abierto con #sidebar-toggle-mobile, con backdrop y cierre por Escape/click fuera.
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

    // Estado de colapso persistido en localStorage
    let collapsed = localStorage.getItem('chat2-sidebar-collapsed') === 'true';

    /**
     * Verifica si estamos en viewport móvil (≤900px).
     * @returns {boolean} True si es viewport móvil.
     */
    function isMobileViewport() {
      return mobileMediaQuery.matches;
    }

    /**
     * Aplica el estado de colapso al sidebar (solo en escritorio).
     */
    function applyCollapsedState() {
      if (collapsed && !isMobileViewport()) {
        sidebar.classList.add('collapsed');
      } else {
        sidebar.classList.remove('collapsed');
      }
    }

    /**
     * Sincroniza el estado responsive según el viewport actual.
     * En móvil cierra el drawer, en escritorio aplica el estado de colapso.
     */
    function syncResponsiveState() {
      if (!isMobileViewport()) {
        // Forzar cierre del drawer al volver a escritorio
        sidebar.classList.remove('open');
        sidebarBackdrop?.classList.remove('visible');
      }
      applyCollapsedState();
    }

    /**
     * Alterna el estado de colapso y lo guarda en localStorage.
     */
    function toggleSidebar() {
      collapsed = !collapsed;
      localStorage.setItem('chat2-sidebar-collapsed', collapsed);
      applyCollapsedState();
    }

    /**
     * Abre el sidebar en modo móvil (drawer).
     */
    function openSidebarMobile() {
      if (!isMobileViewport()) return;
      sidebar.classList.remove('collapsed');
      sidebar.classList.add('open');
      sidebarBackdrop?.classList.add('visible');
    }

    /**
     * Cierra el sidebar en modo móvil (drawer).
     */
    function closeSidebarMobile() {
      sidebar.classList.remove('open');
      sidebarBackdrop?.classList.remove('visible');
    }

    /**
     * Alterna la visibilidad del sidebar en móvil.
     */
    function toggleSidebarMobile() {
      if (!isMobileViewport()) return;
      if (sidebar.classList.contains('open')) {
        closeSidebarMobile();
      } else {
        openSidebarMobile();
      }
    }

    // Event listener para botón de colapso en escritorio
    sidebarToggle?.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleSidebar();
    });

    // Event listener para botón de abrir/cerrar en móvil
    sidebarToggleMobile?.addEventListener('click', function (e) {
      e.stopPropagation();
      toggleSidebarMobile();
    });

    // Cerrar al hacer click en el backdrop
    sidebarBackdrop?.addEventListener('click', closeSidebarMobile);

    // Cerrar con tecla Escape en móvil
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && isMobileViewport() && sidebar.classList.contains('open')) {
        closeSidebarMobile();
      }
    });

    // Cerrar el drawer al seleccionar un item en móvil
    sidebar.addEventListener('click', function (e) {
      if (!isMobileViewport()) return;
      const item = e.target.closest('.sb-item, .accordion-header');
      if (item) closeSidebarMobile();
    });

    // Escuchar cambios de viewport y resize
    mobileMediaQuery.addEventListener('change', syncResponsiveState);
    window.addEventListener('resize', syncResponsiveState, { passive: true });

    // Inicializar estado
    syncResponsiveState();
  });
})();
