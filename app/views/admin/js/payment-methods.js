(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const pageAlert = document.querySelector('[data-page-alert]');

    document.querySelectorAll('[data-payment-method]').forEach(function (button) {
        button.addEventListener('click', function () {
            const activate = button.dataset.active === '1';
            button.disabled = true;
            ui.hideAlert(pageAlert);
            api('api/admin/payment-methods', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ codigo: button.dataset.paymentMethod, activa: activate }),
            })
                .then(function (result) {
                    if (result.ok) {
                        window.Cursology.goWithMessage(null, activate ? 'Forma de pago activada.' : 'Forma de pago desactivada.');
                        return;
                    }
                    button.disabled = false;
                    const error = result.body && result.body.error ? result.body.error.message : null;
                    ui.showAlert(pageAlert, error || 'No se pudo guardar el cambio.');
                })
                .catch(function () {
                    button.disabled = false;
                    ui.showAlert(pageAlert, ui.NETWORK_ERROR);
                });
        });
    });
})();
