(function () {
    'use strict';

    const section = document.getElementById('course-levels');
    if (!section) {
        return;
    }

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const MIN_ANSWERS = 2;
    const MAX_ANSWERS = 8;
    let formCounter = 0;

    function send(method, url, body) {
        return api(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
    }

    function reloadAt(anchor, message) {
        history.replaceState(null, '', '#' + anchor);
        window.Cursology.goWithMessage(null, message);
    }

    function element(tag, attributes, text) {
        const node = document.createElement(tag);
        Object.keys(attributes || {}).forEach(function (name) {
            node.setAttribute(name, attributes[name]);
        });
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    /* ---------- Formulario de pregunta (agregar y editar) ---------- */
    function buildQuestionForm(form) {
        const id = 'q' + (++formCounter);
        const editing = Boolean(form.dataset.questionId);
        const initial = form.dataset.question
            ? JSON.parse(form.dataset.question)
            : { text: '', mode: 'unica', answers: [{ text: '', correct: true, weight: '1.00' }, { text: '', correct: false, weight: '0.00' }] };

        form.innerHTML = '';
        form.append(element('div', { class: 'form-alert', role: 'alert', 'data-form-alert': '', hidden: '' }));
        if (!editing) {
            form.append(element('p', { class: 'question-form-title' }, 'Nueva pregunta'));
        }

        const textField = element('div', { class: 'form-field' });
        textField.append(element('label', { class: 'form-label', for: id + '-pregunta' }, 'Pregunta'));
        const textarea = element('textarea', { class: 'form-control', id: id + '-pregunta', name: 'pregunta', rows: '2', maxlength: '2000' });
        textarea.value = initial.text;
        textField.append(textarea, element('p', { class: 'field-error', 'aria-live': 'polite' }));

        const modeField = element('fieldset', { class: 'form-field fieldset-reset' });
        modeField.append(element('legend', { class: 'form-label' }, 'Tipo de pregunta'));
        const modes = element('div', { class: 'question-modes' });
        [['unica', 'Opción única'], ['multiple', 'Opción múltiple']].forEach(function (mode) {
            const label = element('label', { class: 'check-option' });
            const radio = element('input', { type: 'radio', name: 'modo', value: mode[0] });
            radio.checked = initial.mode === mode[0];
            label.append(radio, document.createTextNode(' ' + mode[1]));
            modes.append(label);
        });
        const modeHint = element('p', { class: 'form-hint' });
        modeField.append(modes, modeHint, element('p', { class: 'field-error', 'aria-live': 'polite' }));

        const answersField = element('fieldset', { class: 'form-field fieldset-reset' });
        answersField.append(element('legend', { class: 'form-label' }, 'Opciones'));
        answersField.append(element('input', { type: 'hidden', name: 'respuestas' }));
        const rows = element('div', { class: 'answer-rows' });
        const addButton = element('button', { type: 'button', class: 'btn btn-ghost btn-sm' }, 'Agregar opción');
        answersField.append(rows, addButton, element('p', { class: 'field-error', 'aria-live': 'polite' }));

        const actions = element('div', { class: 'form-actions' });
        if (editing) {
            actions.append(element('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'data-toggle-close': '' }, 'Cancelar'));
        }
        actions.append(element('button', { type: 'submit', class: 'btn btn-primary btn-sm' }, editing ? 'Guardar pregunta' : 'Agregar pregunta'));

        form.append(textField, modeField, answersField, actions);

        function mode() {
            const checked = form.querySelector('input[name="modo"]:checked');
            return checked ? checked.value : 'unica';
        }

        function syncRows() {
            const single = mode() === 'unica';
            modeHint.textContent = single
                ? 'Marca la opción correcta. Vale lo que diga su peso.'
                : 'Marca todas las correctas; cada una suma su peso. Si el alumno marca una incorrecta, la pregunta vale 0.';
            const list = rows.querySelectorAll('.answer-row');
            list.forEach(function (row, index) {
                const correct = row.querySelector('[data-correct]');
                correct.type = single ? 'radio' : 'checkbox';
                correct.name = id + '-correcta';
                row.querySelector('[data-weight-wrap]').hidden = !correct.checked;
                row.querySelector('[data-answer-text]').placeholder = 'Opción ' + (index + 1);
                row.querySelector('[data-remove-answer]').disabled = list.length <= MIN_ANSWERS;
            });
            addButton.disabled = list.length >= MAX_ANSWERS;
        }

        function addRow(answer) {
            const row = element('div', { class: 'answer-row' });
            const correctLabel = element('label', { class: 'answer-correct', title: 'Correcta' });
            const correct = element('input', { type: 'radio', 'data-correct': '' });
            correct.checked = Boolean(answer.correct);
            correctLabel.append(correct, element('span', { class: 'answer-correct-text' }, 'Correcta'));
            const text = element('input', { class: 'form-control', type: 'text', maxlength: '1000', 'data-answer-text': '', 'aria-label': 'Texto de la opción' });
            text.value = answer.text;
            const weightWrap = element('label', { class: 'answer-weight', 'data-weight-wrap': '' });
            const weight = element('input', { class: 'form-control', type: 'text', inputmode: 'decimal', 'data-answer-weight': '', 'aria-label': 'Peso de la opción' });
            weight.value = answer.correct && answer.weight !== '0.00' ? answer.weight : '1';
            weightWrap.append(document.createTextNode('Peso '), weight);
            const remove = element('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'data-remove-answer': '', 'aria-label': 'Quitar opción' }, 'Quitar');
            row.append(correctLabel, text, weightWrap, remove);
            rows.append(row);

            correct.addEventListener('change', syncRows);
            remove.addEventListener('click', function () {
                row.remove();
                syncRows();
            });
        }

        initial.answers.forEach(addRow);
        addButton.addEventListener('click', function () {
            addRow({ text: '', correct: false, weight: '0.00' });
            syncRows();
            rows.lastElementChild.querySelector('[data-answer-text]').focus();
        });
        modes.querySelectorAll('input').forEach(function (radio) {
            radio.addEventListener('change', function () {
                // Al pasar a opción única solo queda marcada la primera correcta.
                if (mode() === 'unica') {
                    let seen = false;
                    rows.querySelectorAll('[data-correct]').forEach(function (input) {
                        input.checked = input.checked && !seen;
                        seen = seen || input.checked;
                    });
                }
                syncRows();
            });
        });
        const close = form.querySelector('[data-toggle-close]');
        if (close) {
            close.addEventListener('click', function () {
                form.hidden = true;
            });
        }
        syncRows();

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const alert = form.querySelector('[data-form-alert]');
            ui.clearErrors(form);
            ui.hideAlert(alert);

            const answers = Array.prototype.map.call(rows.querySelectorAll('.answer-row'), function (row) {
                const correct = row.querySelector('[data-correct]').checked;
                return {
                    texto: row.querySelector('[data-answer-text]').value,
                    correcta: correct,
                    peso: correct ? row.querySelector('[data-answer-weight]').value : '0',
                };
            });
            const body = { pregunta: textarea.value, modo: mode(), respuestas: answers };

            const errors = {};
            if (body.pregunta.trim() === '') {
                errors.pregunta = 'Escribe la pregunta.';
            }
            const correctCount = answers.filter(function (answer) { return answer.correcta; }).length;
            if (answers.some(function (answer) { return answer.texto.trim() === ''; })) {
                errors.respuestas = 'Todas las opciones necesitan texto.';
            } else if (correctCount === 0) {
                errors.respuestas = 'Marca al menos una opción correcta.';
            } else if (answers.some(function (answer) { return answer.correcta && !(Number(answer.peso) > 0); })) {
                errors.respuestas = 'El peso de las correctas debe ser mayor a 0.';
            }
            if (Object.keys(errors).length > 0) {
                Object.keys(errors).forEach(function (name) {
                    ui.showError(form, name, errors[name]);
                });
                return;
            }

            ui.setBusy(form, true);
            const request = editing
                ? send('PATCH', 'api/instructor/questions/' + form.dataset.questionId, body)
                : send('POST', 'api/instructor/chapters/' + form.dataset.chapterId + '/questions', body);
            request
                .then(function (result) {
                    if (result.ok) {
                        reloadAt('chapter-' + form.dataset.chapterId, editing ? 'Pregunta guardada.' : 'Pregunta agregada.');
                        return;
                    }
                    ui.setBusy(form, false);
                    ui.showApiError(form, alert, result);
                })
                .catch(function () {
                    ui.setBusy(form, false);
                    ui.showAlert(alert, ui.NETWORK_ERROR);
                });
        });
    }

    section.querySelectorAll('[data-question-form]').forEach(buildQuestionForm);

    /* ---------- Probar examen ---------- */
    section.querySelectorAll('[data-preview-form]').forEach(function (form) {
        const alert = form.querySelector('[data-form-alert]');
        const summary = form.querySelector('[data-preview-result]');

        function clearFeedback() {
            summary.hidden = true;
            form.querySelectorAll('[data-preview-feedback]').forEach(function (node) {
                node.hidden = true;
            });
        }

        form.addEventListener('reset', clearFeedback);
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.hideAlert(alert);
            clearFeedback();

            const answers = {};
            form.querySelectorAll('[data-preview-question]').forEach(function (fieldset) {
                answers[fieldset.dataset.previewQuestion] = Array.prototype.map.call(
                    fieldset.querySelectorAll('input:checked'),
                    function (input) { return input.value; }
                );
            });

            ui.setBusy(form, true, 'Calificando…');
            send('POST', 'api/instructor/chapters/' + form.dataset.chapterId + '/preview-grade', { respuestas: answers })
                .then(function (response) {
                    ui.setBusy(form, false);
                    if (!response.ok) {
                        ui.showApiError(form, alert, response);
                        return;
                    }
                    const result = response.body.data.result;
                    result.questions.forEach(function (question) {
                        const fieldset = form.querySelector('[data-preview-question="' + question.id + '"]');
                        const feedback = fieldset ? fieldset.querySelector('[data-preview-feedback]') : null;
                        if (!feedback) {
                            return;
                        }
                        feedback.textContent = question.earnedPoints + ' de ' + question.maxPoints + ' puntos'
                            + (question.markedWrong ? ' · marcaste una opción incorrecta, así que vale 0.' : '.');
                        feedback.className = 'preview-feedback' + (question.earnedPoints === question.maxPoints ? ' is-ok' : '');
                        feedback.hidden = false;
                    });
                    summary.textContent = 'Calificación: ' + result.grade + ' (' + result.earnedPoints + ' de ' + result.maxPoints
                        + ' puntos). Mínimo para aprobar: ' + result.minimumPassingGrade + '. '
                        + (result.passed ? 'Aprobado.' : 'No aprobado.');
                    summary.className = 'preview-result ' + (result.passed ? 'is-passed' : 'is-failed');
                    summary.hidden = false;
                })
                .catch(function () {
                    ui.setBusy(form, false);
                    ui.showAlert(alert, ui.NETWORK_ERROR);
                });
        });
    });
})();
