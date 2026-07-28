(function () {
    'use strict';

    var SVG_EYE = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
    var SVG_EYE_OFF = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12A3 3 0 1 1 9.88 9.88"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

    /* ── Password show/hide toggle ── */
    document.querySelectorAll('.field-wrap input[type="password"]').forEach(function (input) {
        var wrap = input.closest('.field-wrap');
        if (!wrap) { return; }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-toggle';
        btn.setAttribute('aria-label', 'Mostrar senha');
        btn.innerHTML = SVG_EYE;
        wrap.appendChild(btn);

        btn.addEventListener('click', function () {
            var isShowing = input.type === 'text';
            input.type = isShowing ? 'password' : 'text';
            btn.innerHTML = isShowing ? SVG_EYE : SVG_EYE_OFF;
            btn.classList.toggle('is-showing', !isShowing);
            btn.setAttribute('aria-label', isShowing ? 'Mostrar senha' : 'Ocultar senha');
            input.focus();
        });
    });

    /* ── Caps Lock warning ── */
    var pwInput  = document.getElementById('password');
    var capsWarn = document.getElementById('caps-warn');
    if (pwInput && capsWarn) {
        function checkCaps(e) {
            capsWarn.hidden = !e.getModifierState('CapsLock');
        }
        pwInput.addEventListener('keydown', checkCaps);
        pwInput.addEventListener('keyup', checkCaps);
    }

    /* ── Loading state on submit ── */
    var form = document.getElementById('login-form');
    if (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('.btn');
            if (btn && !btn.disabled) {
                btn.classList.add('btn--loading');
                btn.disabled = true;
            }
        });
    }

    /* ── Shake + scroll-into-view on error ── */
    if (document.body.dataset.loginError === '1') {
        var card = document.querySelector('.card');
        if (card) {
            setTimeout(function () {
                card.classList.add('card--shake');
                card.addEventListener('animationend', function () {
                    card.classList.remove('card--shake');
                }, { once: true });
            }, 80);
        }
        // Re-focus the identifier or password field
        var firstField = document.getElementById('identifier') || document.getElementById('password');
        if (firstField) { setTimeout(function () { firstField.focus(); }, 150); }
    }

    /* ── Forgot password ── */
    var forgotLink = document.getElementById('forgot-link');
    if (forgotLink) {
        forgotLink.addEventListener('click', function (e) {
            e.preventDefault();
            var existing = document.getElementById('forgot-tip');
            if (existing) { existing.remove(); return; }
            var tip = document.createElement('p');
            tip.id = 'forgot-tip';
            tip.className = 'forgot-tip';
            tip.textContent = 'Entre em contato com o administrador do sistema para redefinir sua senha.';
            forgotLink.insertAdjacentElement('afterend', tip);
            setTimeout(function () { if (tip.parentNode) { tip.remove(); } }, 5000);
        });
    }

}());
