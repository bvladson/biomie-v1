(function () {
  var storageKey = 'biomie:theme';
  var docEl = document.documentElement;
  var preference = 'system';

  function normalizeThemePreference(value) {
    return value === 'light' || value === 'dark' || value === 'system' ? value : 'system';
  }

  function prefersDark() {
    try {
      return !!(window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    } catch (error) {
      return false;
    }
  }

  function resolveTheme(value) {
    return value === 'system' ? (prefersDark() ? 'dark' : 'light') : value;
  }

  try {
    preference = normalizeThemePreference(window.localStorage.getItem(storageKey) || 'system');
  } catch (error) {
    preference = 'system';
  }

  var resolvedTheme = resolveTheme(preference);
  docEl.dataset.themePreference = preference;
  docEl.dataset.theme = resolvedTheme;
  docEl.style.colorScheme = resolvedTheme;

  window.BIOMIE_THEME_KEY = storageKey;
})();
