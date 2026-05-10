(function () {
    'use strict';

    document.querySelectorAll('details.install-details').forEach(function (details) {
        details.addEventListener('toggle', function () {
            details.setAttribute('aria-expanded', details.open ? 'true' : 'false');
        });
        details.setAttribute('aria-expanded', details.open ? 'true' : 'false');
    });
}());
