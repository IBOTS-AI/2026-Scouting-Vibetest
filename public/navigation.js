(() => {
  const menu = document.getElementById('navigationMenu');
  if (!menu) return;
  const toggle = menu.querySelector('summary');
  document.addEventListener('click', event => {
    if (menu.open && !menu.contains(event.target)) menu.open = false;
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.open) {
      menu.open = false;
      toggle.focus();
    }
  });
})();
