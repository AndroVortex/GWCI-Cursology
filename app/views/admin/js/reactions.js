(function () {
    'use strict';

    const api = window.Cursology.api;
    const F = window.Cursology.form;
    const pageAlert = document.querySelector('[data-page-alert]');

    function sendJson(url, method, body) {
        return api(url, { method: method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    }

    function submitReaction(form, url, method, successMessage) {
        const alert = form.querySelector('[data-form-alert]');
        F.clearErrors(form);
        F.hideAlert(alert);

        const body = { emoji: form.elements.emoji.value.trim(), nombre: form.elements.nombre.value.trim() };
        let invalid = false;
        if (!body.emoji) {
            F.showError(form, 'emoji', 'Escribe o pega el emoji.');
            invalid = true;
        }
        if (!body.nombre) {
            F.showError(form, 'nombre', 'Escribe el nombre de la reacción.');
            invalid = true;
        }
        if (invalid) {
            return;
        }

        F.setBusy(form, true);
        sendJson(url, method, body)
            .then(function (result) {
                if (result.ok) {
                    window.Cursology.goWithMessage(null, successMessage);
                    return;
                }
                F.setBusy(form, false);
                F.showApiError(form, alert, result);
            })
            .catch(function () {
                F.setBusy(form, false);
                F.showAlert(alert, F.NETWORK_ERROR);
            });
    }

    /* ---------- Crear ---------- */
    const createForm = document.getElementById('reaction-create-form');
    if (createForm) {
        createForm.addEventListener('submit', function (event) {
            event.preventDefault();
            submitReaction(createForm, 'api/admin/reactions', 'POST', 'Reacción creada.');
        });
    }

    /* ---------- Agregar las sugeridas ---------- */
    document.querySelectorAll('[data-reaction-defaults]').forEach(function (button) {
        button.addEventListener('click', function () {
            button.disabled = true;
            F.hideAlert(pageAlert);
            api('api/admin/reactions/defaults', { method: 'POST' })
                .then(function (result) {
                    if (result.ok) {
                        const added = result.body.data.added.length;
                        window.Cursology.goWithMessage(null, added === 0 ? 'Las sugeridas ya estaban en el catálogo.' : 'Se agregaron ' + added + ' reacciones sugeridas.');
                        return;
                    }
                    button.disabled = false;
                    const error = result.body && result.body.error ? result.body.error : null;
                    F.showAlert(pageAlert, error ? error.message : 'No se pudieron agregar las sugeridas.');
                })
                .catch(function () {
                    button.disabled = false;
                    F.showAlert(pageAlert, F.NETWORK_ERROR);
                });
        });
    });

    /* ---------- Editar en la misma fila ---------- */
    function toggleEdit(id, editing) {
        document.querySelector('[data-reaction-row="' + id + '"]').hidden = editing;
        document.querySelector('[data-reaction-edit-row="' + id + '"]').hidden = !editing;
        if (editing) {
            document.querySelector('[data-reaction-edit-form="' + id + '"]').elements.emoji.focus();
        }
    }

    document.querySelectorAll('[data-reaction-edit]').forEach(function (button) {
        button.addEventListener('click', function () { toggleEdit(button.dataset.reactionEdit, true); });
    });

    document.querySelectorAll('[data-reaction-cancel]').forEach(function (button) {
        button.addEventListener('click', function () { toggleEdit(button.dataset.reactionCancel, false); });
    });

    document.querySelectorAll('[data-reaction-edit-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitReaction(form, 'api/admin/reactions/' + form.dataset.reactionEditForm, 'PATCH', 'Reacción actualizada.');
        });
    });

    /* ---------- Activar / desactivar ---------- */
    document.querySelectorAll('[data-reaction-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const activate = button.dataset.active !== '1';
            button.disabled = true;
            F.hideAlert(pageAlert);
            sendJson('api/admin/reactions/' + button.dataset.reactionToggle, 'PATCH', { activa: activate })
                .then(function (result) {
                    if (result.ok) {
                        window.Cursology.goWithMessage(null, activate ? 'Reacción activada.' : 'Reacción desactivada.');
                        return;
                    }
                    button.disabled = false;
                    const error = result.body && result.body.error ? result.body.error : null;
                    F.showAlert(pageAlert, error ? error.message : 'No se pudo cambiar el estado.');
                })
                .catch(function () {
                    button.disabled = false;
                    F.showAlert(pageAlert, F.NETWORK_ERROR);
                });
        });
    });
})();
