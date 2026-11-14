(() => {
  const toggle = document.getElementById('workspaceMobileMenuToggle');
  const sidebar = document.getElementById('workspaceSidebar');
  if (!toggle || !sidebar) return;

  function setOpen(open, returnFocus = false) {
    sidebar.classList.toggle('is-open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? '关闭工作台菜单' : '打开工作台菜单');
    if (returnFocus) toggle.focus();
  }

  toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('is-open')));
  sidebar.addEventListener('click', (event) => {
    if (event.target.closest('a[href]')) setOpen(false);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && sidebar.classList.contains('is-open')) setOpen(false, true);
  });
  window.addEventListener('resize', () => {
    if (window.innerWidth > 960) setOpen(false);
  });
})();
