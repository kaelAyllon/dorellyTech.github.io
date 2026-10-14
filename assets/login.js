(function () {
    'use strict';
    var password = document.getElementById('auth-pass');
    var toggle = document.getElementById('auth-password-toggle');
    var capsNotice = document.getElementById('auth-caps-msg');
    var form = document.getElementById('auth-login-form');
    if (password && toggle) {
        toggle.addEventListener('click', function () {
            var show = password.type === 'password';
            password.type = show ? 'text' : 'password';
            toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
            toggle.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
            password.focus({ preventScroll: true });
        });
        password.addEventListener('keyup', function (event) {
            if (capsNotice && typeof event.getModifierState === 'function') {
                capsNotice.hidden = !event.getModifierState('CapsLock');
            }
        });
    }
    if (form) {
        form.addEventListener('submit', function () {
            var submit = document.getElementById('auth-submit');
            if (submit) {
                submit.disabled = true;
                submit.textContent = 'Ingresando…';
            }
        });
    }
}());
