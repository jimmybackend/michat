/**
 * estilo.js
 * Gestiona preferencias visuales locales que NO forman parte de UserPreferences.
 *
 * IMPORTANTE:
 * - theme-dark/theme-light pertenece a user_preferences.js + MySQL.
 * - Este archivo ya no lee ni escribe el modo para evitar que localStorage
 *   sobrescriba el valor cargado desde UserPreferences.
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  const body = document.body;
  const STORAGE_KEY = 'ui-theme-state';

  const themeClasses = ['theme-neon-green', 'theme-neon-blue', 'theme-neon-red', 'theme-neon-yellow'];
  const visionClasses = ['vision-normal', 'vision-myopia', 'vision-protanopia', 'vision-deuteranopia', 'vision-tritanopia'];

  const defaultState = {
    theme: 'theme-neon-green',
    vision: 'vision-normal',
    ascii: true
  };

  function removeClasses(list) {
    list.forEach((c) => body.classList.remove(c));
  }

  function applyState(state) {
    body.classList.add('ui-theme');

    // No tocar theme-dark/theme-light: lo administra user_preferences.js.
    removeClasses(themeClasses);
    removeClasses(visionClasses);

    body.classList.add(state.theme || defaultState.theme);
    body.classList.add(state.vision || defaultState.vision);
    body.classList.toggle('ascii-on', !!state.ascii);
  }

  function getStateFromBody() {
    return {
      theme: themeClasses.find((c) => body.classList.contains(c)) || defaultState.theme,
      vision: visionClasses.find((c) => body.classList.contains(c)) || defaultState.vision,
      ascii: body.classList.contains('ascii-on')
    };
  }

  function savePrefs() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(getStateFromBody()));
  }

  function loadPrefs() {
    const saved = localStorage.getItem(STORAGE_KEY);

    if (!saved) {
      applyState(defaultState);
      return;
    }

    try {
      const parsed = JSON.parse(saved);
      // Compatibilidad con el formato anterior: ignoramos parsed.mode a propósito.
      applyState({ ...defaultState, ...parsed, mode: undefined });
    } catch (e) {
      applyState(defaultState);
    }
  }

  function setTheme(theme) {
    applyState({ ...getStateFromBody(), theme });
    savePrefs();
  }

  function setVision(vision) {
    applyState({ ...getStateFromBody(), vision });
    savePrefs();
  }

  function toggleAscii() {
    applyState({ ...getStateFromBody(), ascii: !body.classList.contains('ascii-on') });
    savePrefs();
  }

  document.addEventListener('click', function (e) {
    const btnTheme = e.target.closest('.js-set-theme');
    if (btnTheme) {
      e.preventDefault();
      setTheme(btnTheme.dataset.theme);
      return;
    }

    // .js-set-mode se deja exclusivamente a user_preferences.js.

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

  loadPrefs();
});
