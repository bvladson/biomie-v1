(function () {
    'use strict';

    var SVG_EYE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var SVG_EYE_OFF = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    /* ── details a11y ─────────────────────────────────────────── */
    document.querySelectorAll('details.install-details').forEach(function (el) {
        el.addEventListener('toggle', function () {
            el.setAttribute('aria-expanded', el.open ? 'true' : 'false');
        });
        el.setAttribute('aria-expanded', el.open ? 'true' : 'false');
    });

    /* ── loading state on form submit ─────────────────────────── */
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('button[type="submit"]');
            if (btn && !btn.disabled) {
                btn.classList.add('is-loading');
                btn.setAttribute('aria-busy', 'true');
            }
        });
    });

    /* ── password show/hide toggle ────────────────────────────── */
    document.querySelectorAll('.install-field input[type="password"]').forEach(function (input) {
        var wrapper = document.createElement('span');
        wrapper.className = 'install-pw-wrap';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'install-pw-toggle';
        btn.setAttribute('aria-label', 'Mostrar senha');
        btn.innerHTML = SVG_EYE;

        wrapper.appendChild(btn);

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            var isShowing = input.type === 'text';
            input.type = isShowing ? 'password' : 'text';
            btn.innerHTML = isShowing ? SVG_EYE : SVG_EYE_OFF;
            btn.classList.toggle('is-showing', !isShowing);
            btn.setAttribute('aria-label', isShowing ? 'Mostrar senha' : 'Ocultar senha');
            input.focus();
        });
    });

    /* ── password strength meter ──────────────────────────────── */
    var pwInput = document.getElementById('install-pw');
    var pwBar = document.getElementById('install-pw-bar');
    var pwHint = document.getElementById('install-pw-hint');
    var confirmInput = document.getElementById('install-pw-confirm');
    var confirmHint = document.getElementById('install-confirm-hint');

    var barColors = ['#fda4af', '#fbbf24', '#4ade80', '#16803c'];
    var barLabels = ['Muito fraca', 'Fraca', 'Boa', 'Forte'];

    function calcStrength(pw) {
        var s = 0;
        if (pw.length >= 10) { s++; }
        if (/[A-Za-z]/.test(pw)) { s++; }
        if (/\d/.test(pw)) { s++; }
        if (/[^A-Za-z0-9]/.test(pw)) { s++; }
        return s;
    }

    function updateStrengthBar(pw) {
        if (!pwBar) { return; }
        if (pw.length === 0) {
            pwBar.style.width = '0';
            pwBar.style.background = '';
            if (pwHint) { pwHint.textContent = ''; }
            return;
        }
        var s = calcStrength(pw);
        pwBar.style.width = (s * 25) + '%';
        pwBar.style.background = barColors[s - 1] || '#edf0f4';
        if (pwHint) {
            pwHint.textContent = barLabels[s - 1] || '';
            pwHint.style.color = s >= 3 ? 'var(--mag-ok)' : 'var(--mag-warn)';
        }
    }

    function checkConfirm() {
        if (!confirmInput || !confirmHint || !pwInput) { return; }
        if (confirmInput.value === '') { confirmHint.textContent = ''; return; }
        if (pwInput.value === confirmInput.value) {
            confirmHint.textContent = '✓ Senhas conferem';
            confirmHint.style.color = 'var(--mag-ok)';
        } else {
            confirmHint.textContent = '✗ Senhas não conferem';
            confirmHint.style.color = 'var(--mag-error)';
        }
    }

    if (pwInput) {
        pwInput.addEventListener('input', function () {
            updateStrengthBar(pwInput.value);
            if (confirmInput && confirmInput.value) { checkConfirm(); }
        });
    }
    if (confirmInput) {
        confirmInput.addEventListener('input', checkConfirm);
    }

    /* ── session expiry warning (18 min) ──────────────────────── */
    var sessionWarn = document.getElementById('install-session-warn');
    if (sessionWarn) {
        var warnTimer = setTimeout(function () {
            sessionWarn.classList.add('is-visible');
        }, 18 * 60 * 1000);

        var dismissBtn = document.getElementById('install-session-dismiss');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () {
                sessionWarn.classList.remove('is-visible');
                clearTimeout(warnTimer);
            });
        }

        var renewBtn = document.getElementById('install-session-renew');
        if (renewBtn) {
            renewBtn.addEventListener('click', function () {
                sessionWarn.classList.remove('is-visible');
                clearTimeout(warnTimer);
                window.location.reload();
            });
        }
    }

    /* ── auto-focus first empty text/email input ──────────────── */
    var focusInputs = document.querySelectorAll('input[type="text"]:not([disabled]), input[type="email"]:not([disabled])');
    for (var i = 0; i < focusInputs.length; i++) {
        if (!focusInputs[i].value.trim()) {
            focusInputs[i].focus();
            break;
        }
    }

}());
