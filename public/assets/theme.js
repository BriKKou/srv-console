// Applied before first paint to avoid a light/dark flash; dark is the default
try {
  const theme = localStorage.getItem('srv-console-theme');
  if (theme === 'light' || theme === 'system') document.documentElement.dataset.theme = theme;
} catch (e) { /* storage blocked: stay dark */ }
