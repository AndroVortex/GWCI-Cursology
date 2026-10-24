(function () {
    'use strict';

    const api = window.Cursology.api;
    const F = window.Cursology.form;
    const pageAlert = document.querySelector('[data-page-alert]');

    function sendJson(url, method, body) {
        return api(url, { method: method, headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    }

    function submitCategory(form, url, method, successMessage) {
        const alert = form.querySelector('[data-form-alert]');
        F.clearErrors(form);
        F.hideAlert(alert);

        const body = { nombre: form.elements.nombre.value, descripcion: form.elements.descripcion.value };
        if (!body.nombre.trim()) {
            F.showError(form, 'nombre', 'Escribe el nombre de la categoría.');
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
    const createForm = document.getElementById('category-create-form');
    if (createForm) {
        createForm.addEventListener('submit', function (event) {
            event.preventDefault();
            submitCategory(createForm, 'api/admin/categories', 'POST', 'Categoría creada.');
        });
    }

    /* ---------- Editar en la misma fila ---------- */
    function toggleEdit(id, editing) {
        document.querySelector('[data-category-row="' + id + '"]').hidden = editing;
        document.querySelector('[data-category-edit-row="' + id + '"]').hidden = !editing;
        if (editing) {
            document.querySelector('[data-category-edit-form="' + id + '"]').elements.nombre.focus();
        }
    }

    document.querySelectorAll('[data-category-edit]').forEach(function (button) {
        button.addEventListener('click', function () { toggleEdit(button.dataset.categoryEdit, true); });
    });

    document.querySelectorAll('[data-category-cancel]').forEach(function (button) {
        button.addEventListener('click', function () { toggleEdit(button.dataset.categoryCancel, false); });
    });

    document.querySelectorAll('[data-category-edit-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            submitCategory(form, 'api/admin/categories/' + form.dataset.categoryEditForm, 'PATCH', 'Categoría actualizada.');
        });
    });

    /* ---------- Activar / desactivar ---------- */
    document.querySelectorAll('[data-category-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const activate = button.dataset.active !== '1';
            button.disabled = true;
            F.hideAlert(pageAlert);
            sendJson('api/admin/categories/' + button.dataset.categoryToggle, 'PATCH', { activa: activate })
                .then(function (result) {
                    if (result.ok) {
                        window.Cursology.goWithMessage(null, activate ? 'Categoría activada.' : 'Categoría desactivada.');
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
