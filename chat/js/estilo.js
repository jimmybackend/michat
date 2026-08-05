document.addEventListener('DOMContentLoaded', function () {
  const body = document.body;

  const themeClasses = ['theme-neon-green','theme-neon-blue','theme-neon-red','theme-neon-yellow'];
  const modeClasses = ['theme-dark','theme-light'];
  const visionClasses = ['vision-normal','vision-myopia','vision-protanopia','vision-deuteranopia','vision-tritanopia'];

  const defaultState = {
    theme: 'theme-neon-green',   // oficial por default
    mode: 'theme-dark',
    vision: 'vision-normal',
    ascii: true
  };

  function removeClasses(list) {
    list.forEach(c => body.classList.remove(c));
  }

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

  function getStateFromBody() {
    return {
      theme: themeClasses.find(c => body.classList.contains(c)) || defaultState.theme,
      mode: modeClasses.find(c => body.classList.contains(c)) || defaultState.mode,
      vision: visionClasses.find(c => body.classList.contains(c)) || defaultState.vision,
      ascii: body.classList.contains('ascii-on')
    };
  }

  function savePrefs() {
    localStorage.setItem('ui-theme-state', JSON.stringify(getStateFromBody()));
  }

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

  function setTheme(theme) {
    applyState({ ...getStateFromBody(), theme });
    savePrefs();
  }

  function setMode(mode) {
    applyState({ ...getStateFromBody(), mode });
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

  loadPrefs();
});
