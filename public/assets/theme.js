(() => {
  const system = window.matchMedia('(prefers-color-scheme: dark)');
  const key = 'unbound-web-theme';
  let preference = 'system';
  try {
    const saved = localStorage.getItem(key);
    if (saved === 'light' || saved === 'dark') preference = saved;
  } catch (_) { /* Use the system preference when storage is unavailable. */ }

  function applyTheme() {
    const theme = preference === 'system' ? (system.matches ? 'dark' : 'light') : preference;
    if (document.documentElement.dataset.theme !== theme) {
      document.documentElement.dataset.theme = theme;
      document.dispatchEvent(new Event('themechange'));
    }
  }

  applyTheme();
  system.addEventListener('change', applyTheme);
  document.addEventListener('DOMContentLoaded', () => {
    const picker = document.querySelector('[data-theme-picker]');
    if (!picker) return;
    picker.value = preference;
    picker.addEventListener('change', () => {
      preference = picker.value;
      try { localStorage.setItem(key, preference); } catch (_) { /* Theme still applies to this page. */ }
      applyTheme();
    });
  });
})();
