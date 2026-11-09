(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const REASONS = [
        ['spam', 'Spam o publicidad'],
        ['offensive', 'Ofensivo o acoso'],
        ['answers', 'Comparte respuestas del examen'],
        ['other', 'Otro'],
    ];

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

    function field(labelText, control, name) {
        const wrapper = el('div', 'form-field');
        const label = el('label', 'form-label', labelText);
        const id = 'report-' + name + '-' + Math.random().toString(36).slice(2);
        control.id = id;
        control.name = name;
        label.htmlFor = id;
        const error = el('p', 'field-error');
        error.setAttribute('aria-live', 'polite');
        wrapper.append(label, control, error);
        return wrapper;
    }

    /* "Reportado": reemplaza el botón cuando el reporte se envió (o ya existía). */
    function doneNode() {
        const done = el('span', 'report-done', 'Reportado');
        done.title = 'Un administrador lo va a revisar';
        return done;
    }

    function open(button) {
        const host = button.closest('[data-report-host]') || button.parentElement;
        if (host.nextElementSibling && host.nextElementSibling.matches('[data-report-form]')) {
            host.nextElementSibling.querySelector('select').focus();
            return;
        }

        const form = el('form', 'report-form');
        form.noValidate = true;
        form.dataset.reportForm = '';
        const alert = el('div', 'form-alert');
        alert.setAttribute('role', 'alert');
        alert.hidden = true;
        const intro = el('p', 'form-hint', '¿Por qué lo reportas? Un administrador lo va a revisar; la persona no sabrá quién lo reportó.');
        const select = el('select', 'form-control');
        const placeholder = el('option', '', 'Elige un motivo');
        placeholder.value = '';
        select.append(placeholder);
        REASONS.forEach(function (reason) {
            const option = el('option', '', reason[1]);
            option.value = reason[0];
            select.append(option);
        });
        const details = el('input', 'form-control');
        details.type = 'text';
        details.maxLength = 500;
        details.placeholder = 'Detalle (opcional; obligatorio con "Otro")';
        const actions = el('div', 'form-actions');
        const submit = el('button', 'btn btn-danger btn-sm', 'Reportar');
        submit.type = 'submit';
        const cancel = el('button', 'btn btn-ghost btn-sm', 'Cancelar');
        cancel.type = 'button';
        cancel.addEventListener('click', function () { form.remove(); });
        actions.append(submit, cancel);
        form.append(alert, intro, field('Motivo', select, 'motivo'), field('Detalle', details, 'detalle'), actions);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.clearErrors(form);
            ui.hideAlert(alert);
            const body = { motivo: select.value, detalle: details.value.trim() };
            const length = Array.from(body.detalle).length;
            if (!body.motivo) {
                ui.showError(form, 'motivo', 'Elige el motivo del reporte.');
                select.focus();
                return;
            }
            if ((body.motivo === 'other' && length === 0) || (length > 0 && (length < 3 || length > 500))) {
                ui.showError(form, 'detalle', body.motivo === 'other' && length === 0 ? 'Cuéntanos qué pasa (de 3 a 500 caracteres).' : 'Escribe de 3 a 500 caracteres.');
                details.focus();
                return;
            }
            ui.setBusy(form, true, 'Enviando…');
            api(button.dataset.reportUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            })
                .then(function (result) {
                    if (!result.ok) {
                        ui.setBusy(form, false);
                        ui.showApiError(form, alert, result);
                        return;
                    }
                    form.remove();
                    button.replaceWith(doneNode());
                })
                .catch(function () {
                    ui.setBusy(form, false);
                    ui.showAlert(alert, ui.NETWORK_ERROR);
                });
        });

        host.after(form);
        select.focus();
    }

    /* Botones [data-report] con data-report-url="api/comments/N/report" o "api/opinions/N/report". */
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-report]');
        if (button) {
            open(button);
        }
    });

    /* El botón para los elementos que se agregan sin recargar (comments.js, reviews.js). */
    function button(url, reported, className) {
        if (reported) {
            return doneNode();
        }
        const node = el('button', className || 'comment-action report-action', 'Reportar');
        node.type = 'button';
        node.dataset.report = '';
        node.dataset.reportUrl = url;
        return node;
    }

    window.Cursology.report = { button: button };
})();
