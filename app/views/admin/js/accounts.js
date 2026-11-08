(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const pageAlert = document.querySelector('[data-page-alert]');

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
        return api(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) })
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

    /* La fila extra debajo de cada cuenta: el formulario para suspender o el historial. */
    function extraFor(id) {
        const row = document.querySelector('[data-account-extra="' + id + '"]');
        return { row: row, cell: row.querySelector('[data-account-extra-cell]') };
    }

    function showExtra(id, content, kind) {
        const extra = extraFor(id);
        extra.cell.replaceChildren(content);
        extra.row.hidden = false;
        extra.row.dataset.kind = kind;
    }

    function hideExtra(id) {
        const extra = extraFor(id);
        extra.row.hidden = true;
        extra.cell.replaceChildren();
        extra.row.dataset.kind = '';
        const history = document.querySelector('[data-account-history="' + id + '"]');
        if (history) {
            history.setAttribute('aria-expanded', 'false');
        }
    }

    function openBan(button) {
        const id = button.dataset.accountBan;
        const form = el('form', 'account-ban-form');
        form.noValidate = true;
        const field = el('div', 'form-field');
        const label = el('label', 'form-label', 'Motivo de la suspensión de ' + button.dataset.name + ' (queda en su historial)');
        const input = el('input', 'form-control');
        input.type = 'text';
        input.id = 'account-ban-motivo-' + id;
        input.name = 'motivo';
        input.maxLength = 500;
        label.htmlFor = input.id;
        const error = el('p', 'field-error');
        error.setAttribute('aria-live', 'polite');
        field.append(label, input, error);
        const hint = el('p', 'form-hint', 'No podrá iniciar sesión y sus sesiones abiertas se cierran. Si es instructor, sus cursos salen del catálogo.');
        const actions = el('div', 'form-actions');
        const submit = el('button', 'btn btn-danger btn-sm', 'Suspender');
        submit.type = 'submit';
        const cancel = el('button', 'btn btn-ghost btn-sm', 'Cancelar');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { hideExtra(id); });
        actions.append(submit, cancel);
        form.append(field, hint, actions);

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
            post('api/admin/users/' + encodeURIComponent(id) + '/ban', { motivo: reason }, submit,
                'Cuenta de ' + button.dataset.name + ' suspendida.', 'No se pudo suspender la cuenta.');
        });

        showExtra(id, form, 'ban');
        input.focus();
    }

    function toggleHistory(button) {
        const id = button.dataset.accountHistory;
        const extra = extraFor(id);
        if (!extra.row.hidden && extra.row.dataset.kind === 'history') {
            hideExtra(id);
            return;
        }
        button.disabled = true;
        api('api/admin/users/' + encodeURIComponent(id) + '/events')
            .then(function (result) {
                button.disabled = false;
                if (!result.ok) {
                    ui.showAlert(pageAlert, errorMessage(result, 'No se pudo cargar el historial.'));
                    return;
                }
                const events = result.body.data.events;
                const content = el('div', 'account-history');
                content.append(el('p', 'account-history-title', 'Historial'));
                if (events.length === 0) {
                    content.append(el('p', 'text-muted', 'Sin eventos: la cuenta nunca se ha bloqueado, suspendido ni dado de baja.'));
                } else {
                    const list = el('ol', 'account-history-list');
                    events.forEach(function (item) {
                        const entry = el('li');
                        entry.append(el('strong', '', item.label), document.createTextNode(' · ' + item.createdAtLabel + ' · ' + item.actor), el('span', 'table-sub', item.reason));
                        list.append(entry);
                    });
                    content.append(list);
                }
                showExtra(id, content, 'history');
                button.setAttribute('aria-expanded', 'true');
            })
            .catch(function () {
                button.disabled = false;
                ui.showAlert(pageAlert, ui.NETWORK_ERROR);
            });
    }

    document.addEventListener('click', function (event) {
        const ban = event.target.closest('[data-account-ban]');
        if (ban) {
            openBan(ban);
            return;
        }
        const unban = event.target.closest('[data-account-unban]');
        if (unban) {
            if (window.confirm('¿Quitar la suspensión de ' + unban.dataset.name + '?')) {
                post('api/admin/users/' + encodeURIComponent(unban.dataset.accountUnban) + '/unban', {}, unban,
                    'Se quitó la suspensión de ' + unban.dataset.name + '.', 'No se pudo quitar la suspensión.');
            }
            return;
        }
        const reactivate = event.target.closest('[data-account-reactivate]');
        if (reactivate) {
            if (window.confirm('¿Reactivar la cuenta de ' + reactivate.dataset.name + '? Podrá iniciar sesión con su contraseña de siempre.')) {
                post('api/admin/users/' + encodeURIComponent(reactivate.dataset.accountReactivate) + '/reactivate', {}, reactivate,
                    'Cuenta de ' + reactivate.dataset.name + ' reactivada.', 'No se pudo reactivar la cuenta.');
            }
            return;
        }
        const history = event.target.closest('[data-account-history]');
        if (history) {
            toggleHistory(history);
        }
    });
})();
