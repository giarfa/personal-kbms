/**
 * Mockup-only theme toggle. Mirrors the Starter Kit appearance setting
 * (light / dark / system) that Alex wires with Flux + localStorage.
 */
(function () {
  var KEY = 'kbms-mockup-theme';
  var root = document.documentElement;

  function apply(mode) {
    var dark = mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    root.classList.toggle('dark', dark);
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('aria-pressed', String(dark));
      btn.querySelector('[data-theme-label]').textContent = dark ? 'Light' : 'Dark';
    });
  }

  var stored = null;
  try { stored = localStorage.getItem(KEY); } catch (e) { /* file:// */ }
  apply(stored || (root.classList.contains('dark') ? 'dark' : 'light'));

  document.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-theme-toggle]');
    if (!btn) { return; }
    var next = root.classList.contains('dark') ? 'light' : 'dark';
    try { localStorage.setItem(KEY, next); } catch (e) { /* file:// */ }
    apply(next);
  });
})();
