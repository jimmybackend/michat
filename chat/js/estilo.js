/**
 * estilo.js
 * Gestiona las preferencias de tema, modo y accesibilidad visual del chat.
 * Las preferencias se guardan en localStorage para persistir entre sesiones.
 */

document.addEventListener('DOMContentLoaded', function () {
  const body = document.body;
  
  // Clases disponibles para cada categoría de preferencia
  const themeClasses = ['theme-neon-green','theme-neon-blue','theme-neon-red','theme-neon-yellow'];
  const modeClasses = ['theme-dark','theme-light'];
  const visionClasses = ['vision-normal','vision-myopia','vision-protanopia','vision-deuteranopia','vision-tritanopia'];
  
  // Estado por defecto cuando no hay preferencias guardadas
  const defaultState = {
    theme: 'theme-neon-green',   // tema oficial por defecto
    mode: 'theme-dark',
    vision: 'vision-normal',
    ascii: true
  };

  /**
   * Elimina todas las clases de una lista dada del body.
   * @param {string[]} list - Array de nombres de clases a remover.
   */
  function removeClasses(list) {
    list.forEach(c => body.classList.remove(c));
  }

  /**
   * Aplica un estado de preferencias al body del documento.
   * Añade las clases correspondientes a tema, modo, visión y ASCII.
   * @param {Object} state - Objeto con las preferencias a aplicar.
   */
  function applyState(state) {
    body.classList.add('ui-theme');

    removeClasses(themeClasses);
    removeClasses(modeClasses);
    removeClasses(visionClasses);

    body.classList.add(state.theme || defaultState.theme);
    body.classList.add(state.mode || defaultState.mode);
    body.classList.add(state.vision || defaultState.vision);

    if (state.ascii) {
      body.classList.add('ascii-on');
    } else {
      body.classList.remove('ascii-on');
    }
  }

  /**
   * Obtiene el estado actual leyendo las clases presentes en el body.
   * @returns {Object} Estado actual con theme, mode, vision y ascii.
   */
  function getStateFromBody() {
    return {
      theme: themeClasses.find(c => body.classList.contains(c)) || defaultState.theme,
      mode: modeClasses.find(c => body.classList.contains(c)) || defaultState.mode,
      vision: visionClasses.find(c => body.classList.contains(c)) || defaultState.vision,
      ascii: body.classList.contains('ascii-on')
    };
  }

  /**
   * Guarda las preferencias actuales en localStorage.
   */
  function savePrefs() {
    localStorage.setItem('ui-theme-state', JSON.stringify(getStateFromBody()));
  }

  /**
   * Carga las preferencias desde localStorage o aplica el estado por defecto.
   */
  function loadPrefs() {
    const saved = localStorage.getItem('ui-theme-state');

    if (!saved) {
      applyState(defaultState);   // usa verde neon
      return;
    }

    try {
      const state = JSON.parse(saved);
      applyState({ ...defaultState, ...state });
    } catch(e) {
      applyState(defaultState);
    }
  }

  /**
   * Establece un nuevo tema y guarda la preferencia.
   * @param {string} theme - Nombre de la clase del tema.
   */
  function setTheme(theme) {
    applyState({ ...getStateFromBody(), theme });
    savePrefs();
  }

  /**
   * Establece un nuevo modo (claro/oscuro) y guarda la preferencia.
   * @param {string} mode - Nombre de la clase del modo.
   */
  function setMode(mode) {
    applyState({ ...getStateFromBody(), mode });
    savePrefs();
  }

  /**
   * Establece un nuevo tipo de visión para accesibilidad y guarda la preferencia.
   * @param {string} vision - Nombre de la clase de visión.
   */
  function setVision(vision) {
    applyState({ ...getStateFromBody(), vision });
    savePrefs();
  }

  /**
   * Alterna el estado de renderizado ASCII y guarda la preferencia.
   */
  function toggleAscii() {
    applyState({ ...getStateFromBody(), ascii: !body.classList.contains('ascii-on') });
    savePrefs();
  }

  // Delegación de eventos para botones de configuración de UI
  document.addEventListener('click', function(e) {
    const btnTheme = e.target.closest('.js-set-theme');
    if (btnTheme) {
      e.preventDefault();
      setTheme(btnTheme.dataset.theme);
      return;
    }

    const btnMode = e.target.closest('.js-set-mode');
    if (btnMode) {
      e.preventDefault();
      setMode(btnMode.dataset.mode);
      return;
    }

    const btnVision = e.target.closest('.js-set-vision');
    if (btnVision) {
      e.preventDefault();
      setVision(btnVision.dataset.vision);
      return;
    }

    const btnAscii = e.target.closest('#btnToggleAscii');
    if (btnAscii) {
      e.preventDefault();
      toggleAscii();
    }
  });

  // Cargar preferencias guardadas al iniciar
  loadPrefs();
});
