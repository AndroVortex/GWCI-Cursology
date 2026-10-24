(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const pageAlert = document.querySelector('[data-page-alert]');

    function errorMessage(result) {
        const error = result && result.body && result.body.error ? result.body.error : null;
        return error && error.message ? error.message : 'Ocurrió un error inesperado. Inténtalo de nuevo.';
    }

    /* ---------- Reactivar cuentas bloqueadas ---------- */
    document.querySelectorAll('[data-unlock]').forEach(function (button) {
        button.addEventListener('click', function () {
            const name = button.dataset.name;
            if (!window.confirm('¿Reactivar la cuenta de ' + name + '? Podrá iniciar sesión con su contraseña de siempre.')) {
                return;
            }

            button.disabled = true;
            ui.hideAlert(pageAlert);
            api('api/admin/users/' + encodeURIComponent(button.dataset.unlock) + '/unlock', { method: 'POST' })
                .then(function (result) {
                    if (result.ok) {
                        window.Cursology.goWithMessage(null, 'Cuenta de ' + name + ' reactivada.');
                        return;
                    }
                    button.disabled = false;
                    ui.showAlert(pageAlert, errorMessage(result));
                })
                .catch(function () {
                    button.disabled = false;
                    ui.showAlert(pageAlert, ui.NETWORK_ERROR);
                });
        });
    });
})();
