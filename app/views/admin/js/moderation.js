(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const pageAlert = document.querySelector('[data-page-alert]');
    /* 'comment' -> 'comments', 'opinion' -> 'opinions' en las rutas de la API. */
    const plural = function (type) { return type === 'comment' ? 'comments' : 'opinions'; };

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function errorMessage(result, fallback) {
        const error = result && result.body && result.body.error ? result.body.error : null;
        if (error && error.fields) {
            const first = Object.keys(error.fields)[0];
            if (first) {
                return error.fields[first];
            }
        }
        return error && error.message ? error.message : fallback;
    }

    function post(url, body, button, success, fallback) {
        button.disabled = true;
        ui.hideAlert(pageAlert);
        return api(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
        })
            .then(function (result) {
                if (result.ok) {
                    window.Cursology.goWithMessage(null, success);
                    return result;
                }
                button.disabled = false;
                ui.showAlert(pageAlert, errorMessage(result, fallback));
                return result;
            })
            .catch(function () {
                button.disabled = false;
                ui.showAlert(pageAlert, ui.NETWORK_ERROR);
            });
    }

    /* Ocultar: pide el motivo (lo ve el autor) en un formulario en línea. */
    function openHide(button) {
        const actions = button.closest('[data-moderation-actions]');
        if (actions.nextElementSibling && actions.nextElementSibling.matches('[data-hide-form]')) {
            return;
        }
        const form = el('form', 'moderation-hide-form');
        form.noValidate = true;
        form.dataset.hideForm = '';
        const field = el('div', 'form-field');
        const id = 'moderation-motivo-' + button.dataset.id;
        const label = el('label', 'form-label', 'Motivo (lo ve el autor)');
        label.htmlFor = id;
        const input = el('input', 'form-control');
        input.type = 'text';
        input.id = id;
        input.name = 'motivo';
        input.maxLength = 500;
        input.value = button.dataset.reason || '';
        const error = el('p', 'field-error');
        error.setAttribute('aria-live', 'polite');
        field.append(label, input, error);
        const formActions = el('div', 'form-actions');
        const submit = el('button', 'btn btn-danger btn-sm', 'Ocultar');
        submit.type = 'submit';
        const cancel = el('button', 'btn btn-ghost btn-sm', 'Cancelar');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { form.remove(); });
        formActions.append(submit, cancel);
        form.append(field, formActions);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.clearErrors(form);
            const reason = input.value.trim();
            const length = Array.from(reason).length;
            if (length < 3 || length > 500) {
                ui.showError(form, 'motivo', 'Escribe el motivo (de 3 a 500 caracteres).');
                input.focus();
                return;
            }
            post('api/' + plural(button.dataset.type) + '/' + encodeURIComponent(button.dataset.id) + '/hide', { motivo: reason }, submit,
                'Contenido ocultado. Sus reportes pendientes quedaron resueltos.', 'No se pudo ocultar.');
        });

        actions.after(form);
        input.focus();
        input.select();
    }

    document.addEventListener('click', function (event) {
        const hide = event.target.closest('[data-moderation-hide]');
        if (hide) {
            openHide(hide);
            return;
        }
        const dismiss = event.target.closest('[data-moderation-dismiss]');
        if (dismiss) {
            if (window.confirm('¿Descartar los reportes? El contenido sigue visible.')) {
                post('api/admin/' + plural(dismiss.dataset.type) + '/' + encodeURIComponent(dismiss.dataset.id) + '/dismiss-reports', {}, dismiss,
                    'Reportes descartados: el contenido sigue visible.', 'No se pudieron descartar los reportes.');
            }
            return;
        }
        const restore = event.target.closest('[data-moderation-restore]');
        if (restore && window.confirm('¿Volver a mostrarlo?')) {
            post('api/admin/' + plural(restore.dataset.type) + '/' + encodeURIComponent(restore.dataset.id) + '/restore', {}, restore,
                'Contenido restaurado: ya se vuelve a ver.', 'No se pudo restaurar.');
        }
    });
})();
