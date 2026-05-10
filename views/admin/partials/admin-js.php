<script>
function filterMigrations(status, btn) {
  var rows = document.querySelectorAll('#adminMigrationsTable tr[data-mig-status]');
  rows.forEach(function (row) {
    if (status === 'all') { row.style.display = ''; return; }
    row.style.display = row.getAttribute('data-mig-status') === status ? '' : 'none';
  });
  var btns = document.querySelectorAll('[data-mig-filter]');
  btns.forEach(function (b) { b.classList.toggle('active', b === btn); });
}
(function () {
  function showAdminTab(tab) {
    document.querySelectorAll('[data-admin-tab-trigger]').forEach(function (button) {
      button.classList.toggle('active', button.getAttribute('data-admin-tab-trigger') === tab);
    });
    document.querySelectorAll('[data-admin-tab-panel]').forEach(function (panel) {
      panel.classList.toggle('active', panel.getAttribute('data-admin-tab-panel') === tab);
    });
  }

  document.querySelectorAll('[data-admin-tab-trigger]').forEach(function (button) {
    button.addEventListener('click', function () {
      var tab = button.getAttribute('data-admin-tab-trigger') || 'users';
      showAdminTab(tab);
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', 'admin.php?tab=' + encodeURIComponent(tab));
      }
    });
  });

  function adminModalOpen(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) {
      modal.hidden = false;
      var firstInput = modal.querySelector('input:not([type=hidden]),select,textarea');
      if (firstInput) { firstInput.focus(); }
    }
  }
  function adminModalClose(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) { modal.hidden = true; }
  }

  document.addEventListener('click', function (e) {
    var openBtn = e.target.closest('[data-admin-modal-open]');
    if (openBtn) {
      var modalId = openBtn.getAttribute('data-admin-modal-open');

      if (modalId === 'modal-edit-user') {
        var uid    = document.getElementById('edit-user-id');
        var fname  = document.getElementById('edit-full-name');
        var email  = document.getElementById('edit-email');
        var role   = document.getElementById('edit-role');
        var active = document.getElementById('edit-active');
        if (uid)    uid.value    = openBtn.dataset.editUserId   || '';
        if (fname)  fname.value  = openBtn.dataset.editFullName || '';
        if (email)  email.value  = openBtn.dataset.editEmail    || '';
        if (role)   role.value   = openBtn.dataset.editRole     || 'viewer';
        if (active) active.checked = openBtn.dataset.editActive === '1';
      }

      if (modalId === 'modal-reset-password') {
        var rpId   = document.getElementById('reset-password-user-id');
        var rpName = document.getElementById('reset-password-user-name');
        var rpPwd  = document.querySelector('#form-reset-password input[name=password]');
        if (rpId)   rpId.value          = openBtn.dataset.resetUserId   || '';
        if (rpName) rpName.textContent  = openBtn.dataset.resetUserName || '';
        if (rpPwd)  rpPwd.value         = '';
      }

      adminModalOpen(modalId);
      return;
    }

    var closeBtn = e.target.closest('[data-admin-modal-close]');
    if (closeBtn) {
      adminModalClose(closeBtn.getAttribute('data-admin-modal-close'));
      return;
    }

    if (e.target.classList.contains('admin-modal')) {
      e.target.hidden = true;
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('.admin-modal:not([hidden])').forEach(function (m) {
        m.hidden = true;
      });
    }
  });
})();
</script>
