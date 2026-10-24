(function () {
    'use strict';

    const root = document.querySelector('[data-checkout]');
    if (!root) {
        return;
    }

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const alert = root.querySelector('[data-checkout-alert]');
    const target = { curso: root.dataset.courseId };
    if (root.dataset.levelId) {
        target.nivel = root.dataset.levelId;
    }
    const learnUrl = '?view=learn&course=' + encodeURIComponent(root.dataset.courseId);

    function post(url, body) {
        return api(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
        });
    }

    function apiError(result) {
        const error = result && result.body && result.body.error ? result.body.error : null;
        return new Error(error && error.message ? error.message : 'No se pudo procesar el pago. Inténtalo de nuevo.');
    }

    /* ---------- PayPal Sandbox (SDK de JS + órdenes creadas y capturadas en el servidor) ---------- */
    const paypalBox = root.querySelector('[data-paypal]');
    let purchaseId = null;

    function renderPayPal() {
        const container = paypalBox.querySelector('[data-paypal-buttons]');
        container.textContent = '';
        window.paypal.Buttons({
            style: { layout: 'vertical', shape: 'rect', label: 'pay' },
            createOrder: function () {
                ui.hideAlert(alert);
                return post('api/checkout/paypal/orders', target).then(function (result) {
                    if (!result.ok) {
                        throw apiError(result);
                    }
                    purchaseId = result.body.data.purchase.id;
                    return result.body.data.orderId;
                });
            },
            onApprove: function () {
                return post('api/checkout/paypal/purchases/' + purchaseId + '/capture').then(function (result) {
                    if (result.status === 200) {
                        window.Cursology.goWithMessage(learnUrl, 'Pago completado con PayPal Sandbox. ¡Ya tienes acceso!');
                        return;
                    }
                    if (result.status === 202) {
                        ui.showAlert(alert, 'PayPal dejó el pago pendiente de revisión. Te daremos acceso en cuanto se complete.');
                        return;
                    }
                    throw apiError(result);
                });
            },
            onCancel: function () {
                if (purchaseId) {
                    post('api/checkout/purchases/' + purchaseId + '/cancel');
                }
                ui.showAlert(alert, 'Cancelaste el pago con PayPal. No se cobró nada.');
            },
            onError: function (error) {
                ui.showAlert(alert, error && error.message ? error.message : 'PayPal no pudo completar el pago.');
            },
        }).render(container);
    }

    if (paypalBox) {
        const script = document.createElement('script');
        script.src = 'https://www.paypal.com/sdk/js?client-id=' + encodeURIComponent(paypalBox.dataset.clientId)
            + '&currency=' + encodeURIComponent(paypalBox.dataset.currency) + '&intent=capture&components=buttons';
        script.addEventListener('load', renderPayPal);
        script.addEventListener('error', function () {
            paypalBox.querySelector('[data-paypal-buttons]').textContent = 'No se pudo cargar PayPal. Revisa tu conexión o usa la tarjeta simulada.';
        });
        document.head.append(script);
    }

    /* ---------- Tarjeta simulada ---------- */
    const cardForm = root.querySelector('[data-card-form]');
    if (cardForm) {
        const number = cardForm.elements.numero;
        const expiry = cardForm.elements.vencimiento;
        const cardAlert = cardForm.querySelector('[data-form-alert]');

        number.addEventListener('input', function () {
            number.value = number.value.replace(/\D/g, '').slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 ');
        });
        expiry.addEventListener('input', function () {
            const digits = expiry.value.replace(/\D/g, '').slice(0, 4);
            expiry.value = digits.length > 2 ? digits.slice(0, 2) + '/' + digits.slice(2) : digits;
        });

        cardForm.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.clearErrors(cardForm);
            ui.hideAlert(cardAlert);
            const body = Object.assign({}, target, {
                titular: cardForm.elements.titular.value,
                numero: number.value,
                vencimiento: expiry.value,
                cvv: cardForm.elements.cvv.value,
            });

            ui.setBusy(cardForm, true, 'Procesando…');
            post('api/checkout/card', body)
                .then(function (result) {
                    if (result.ok) {
                        window.Cursology.goWithMessage(learnUrl, 'Pago con la tarjeta simulada aprobado. ¡Ya tienes acceso!');
                        return;
                    }
                    ui.setBusy(cardForm, false);
                    ui.showApiError(cardForm, cardAlert, result);
                })
                .catch(function () {
                    ui.setBusy(cardForm, false);
                    ui.showAlert(cardAlert, ui.NETWORK_ERROR);
                });
        });
    }
})();
