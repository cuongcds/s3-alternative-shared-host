(function () {
  var STORAGE_KEY = 'os3_admin_sidebar_collapsed';
  var sidebar = document.getElementById('admin-sidebar');
  var toggle = document.getElementById('sidebar-toggle');
  if (!sidebar || !toggle) {
    return;
  }

  function apply(collapsed) {
    sidebar.classList.toggle('collapsed', collapsed);
  }

  apply(window.localStorage.getItem(STORAGE_KEY) === '1');

  toggle.addEventListener('click', function () {
    var collapsed = !sidebar.classList.contains('collapsed');
    apply(collapsed);
    window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
  });
})();
