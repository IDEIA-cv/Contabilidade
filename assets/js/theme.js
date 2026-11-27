// Tema da interface: automático (segue o sistema), claro ou escuro. Carregado no <head> para não piscar ao abrir.
(function () {
  var KEY = 'ideia.theme';
  function get() { try { return localStorage.getItem(KEY) || 'auto'; } catch (e) { return 'auto'; } }
  function apply(t) {
    if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
    else document.documentElement.removeAttribute('data-theme');
  }
  apply(get());
  document.addEventListener('DOMContentLoaded', function () {
    var sel = document.getElementById('theme-select');
    if (!sel) return;
    sel.value = get();
    sel.addEventListener('change', function () {
      try { localStorage.setItem(KEY, sel.value); } catch (e) { /* sem armazenamento: vale só nesta sessão */ }
      apply(sel.value);
    });
  });
})();
