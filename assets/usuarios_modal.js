/* Modal de usuarios; sin librerías externas. No registra ni muestra contraseñas. */
(function () {
  'use strict';
  var modal = document.getElementById('modalUsuario');
  var form = document.getElementById('formUsuario');
  if (!modal || !form) return;
  var title = document.getElementById('usuarioModalTitulo');
  var id = document.getElementById('usuarioId');
  var nombre = document.getElementById('usuarioNombre');
  var correo = document.getElementById('usuarioCorreo');
  var login = document.getElementById('usuarioLogin');
  var perfil = document.getElementById('usuarioPerfil');
  var catalogo = document.getElementById('usuarioCatalogo');
  var clave = document.getElementById('usuarioClave');
  var confirmar = document.getElementById('usuarioClaveConfirmar');
  var claveIndicacion = document.getElementById('claveIndicacion');
  var catalogoLabel = document.getElementById('catalogoVendedorLabel');
  var submit = form.querySelector('[type="submit"]');
  var lastFocus = null;
  var defaults = ['registros_ver', 'registros_crear'];

  function setTab(which) {
    ['datos', 'permisos'].forEach(function (tab) {
      var selected = (tab === which);
      var btn = document.querySelector('[data-tab="' + tab + '"]');
      var panel = document.getElementById(tab === 'datos' ? 'panelDatos' : 'panelPermisos');
      if (btn) {
        btn.classList.toggle('is-active', selected);
        btn.setAttribute('aria-selected', selected ? 'true' : 'false');
        btn.tabIndex = selected ? 0 : -1;
      }
      if (panel) panel.hidden = !selected;
    });
  }

  function syncRole() {
    var isSeller = perfil.value === 'VENDEDOR';
    if (catalogoLabel) catalogoLabel.hidden = !isSeller;
    if (!isSeller) catalogo.value = '0';
    document.getElementById('panelPermisos').classList.toggle('permissions-admin', !isSeller);
  }

  function fill(info) {
    form.reset();
    var edit = !!(info && Number(info.id) > 0);
    title.textContent = edit ? 'Editar usuario #' + info.id : 'Nuevo usuario';
    id.value = edit ? String(info.id) : '0';
    nombre.value = edit ? String(info.nombre || '') : '';
    correo.value = edit ? String(info.correo || '') : '';
    login.value = edit ? String(info.usuario || '') : '';
    perfil.value = edit ? (info.perfil || 'VENDEDOR') : 'VENDEDOR';
    catalogo.value = edit ? String(info.vendedor_catalogo_id || 0) : '0';
    var selected = edit && Array.isArray(info.permisos) ? info.permisos : defaults;
    Array.prototype.forEach.call(form.querySelectorAll('[data-permission]'), function (cb) {
      cb.checked = selected.indexOf(cb.value) !== -1;
    });
    clave.value = '';
    confirmar.value = '';
    clave.required = !edit;
    claveIndicacion.textContent = edit ? '(opcional)' : '*';
    clave.placeholder = edit ? 'Dejar vacío para conservar la actual' : 'Mínimo 10 caracteres';
    syncRole();
    setTab('datos');
  }

  function open(info) {
    lastFocus = document.activeElement;
    fill(info || null);
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('user-modal-open');
    nombre.focus();
  }

  function close() {
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('user-modal-open');
    if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
  }

  document.addEventListener('click', function (e) {
    var button = e.target.closest('[data-action]');
    if (!button) return;
    var action = button.getAttribute('data-action');
    if (action === 'nuevo-usuario') {
      e.preventDefault();
      open(null);
    } else if (action === 'editar-usuario') {
      e.preventDefault();
      try {
        open(JSON.parse(button.getAttribute('data-usuario') || '{}'));
      } catch (err) {
        alert('No se pudo cargar el usuario. Actualiza la página.');
      }
    } else if (action === 'cerrar-usuario') {
      e.preventDefault();
      close();
    }
  });

  Array.prototype.forEach.call(document.querySelectorAll('[data-tab]'), function (btn) {
    btn.addEventListener('click', function () { setTab(btn.getAttribute('data-tab')); });
  });
  perfil.addEventListener('change', syncRole);

  document.addEventListener('keydown', function (e) {
    if (modal.hidden) return;
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'Tab') {
      var items = Array.prototype.slice.call(modal.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled])'))
        .filter(function (el) { return el.getClientRects().length > 0; });
      if (!items.length) return;
      var first = items[0], last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) {e.preventDefault();last.focus();}
      else if (!e.shiftKey && document.activeElement === last) {e.preventDefault();first.focus();}
    }
  });
  form.noValidate = true;
  form.addEventListener('submit', function (e) {
    if (!form.checkValidity()) {
      e.preventDefault();
      setTab('datos');
      form.reportValidity();
      return;
    }
    if (clave.value !== confirmar.value) {
      e.preventDefault();
      setTab('datos');
      confirmar.setCustomValidity('Las contraseñas no coinciden');
      confirmar.reportValidity();
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Guardando…';
  });
  confirmar.addEventListener('input', function () { confirmar.setCustomValidity(''); });
  clave.addEventListener('input', function () { confirmar.setCustomValidity(''); });

  if (document.documentElement.dataset.abrirModalUsuarios === '1') {
    var initial = document.getElementById('datosModalIniciales');
    var info = null;
    if (initial) {
      try {info = JSON.parse(initial.textContent);} catch (err) { info = null; }
    }
    open(info);
  }
})();
