(function () {
    'use strict';

    const root = document.querySelector('[data-exam]');
    if (!root) {
        return;
    }

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const chapterId = root.dataset.chapterId;
    const stage = root.querySelector('[data-exam-stage]');
    const alert = root.querySelector('[data-exam-alert]');
    const actions = root.querySelector('[data-exam-actions]');
    const startButton = root.querySelector('[data-exam-start]');
    const history = document.querySelector('[data-exam-history]');

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

    function post(url, body) {
        return api(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body || {}),
        });
    }

    function errorMessage(result) {
        const error = result && result.body && result.body.error ? result.body.error : null;
        return error && error.message ? error.message : 'Ocurrió un error inesperado. Inténtalo de nuevo.';
    }

    /* ---------- Abrir (o retomar) el intento ---------- */
    function start() {
        startButton.disabled = true;
        ui.hideAlert(alert);
        post('api/student/chapters/' + chapterId + '/attempts')
            .then(function (result) {
                startButton.disabled = false;
                if (!result.ok) {
                    ui.showAlert(alert, errorMessage(result));
                    return;
                }
                renderForm(result.body.data.attempt);
            })
            .catch(function () {
                startButton.disabled = false;
                ui.showAlert(alert, ui.NETWORK_ERROR);
            });
    }

    function renderForm(attempt) {
        actions.hidden = true;
        stage.textContent = '';

        const form = el('form', 'panel-body exam-form');
        form.noValidate = true;
        form.append(el('h2', 'panel-title', 'Intento #' + attempt.number));

        attempt.questions.forEach(function (question, index) {
            const fieldset = el('fieldset', 'exam-question');
            fieldset.append(el('legend', 'exam-question-text', (index + 1) + '. ' + question.text));
            fieldset.append(el('p', 'form-hint', (question.mode === 'single' ? 'Elige una opción' : 'Elige todas las que apliquen') + ' · vale ' + question.maxPoints + ' pts'));
            question.answers.forEach(function (answer) {
                const label = el('label', 'exam-answer');
                const input = el('input');
                input.type = question.mode === 'single' ? 'radio' : 'checkbox';
                input.name = 'q' + question.id;
                input.value = answer.id;
                label.append(input, el('span', '', answer.text));
                fieldset.append(label);
            });
            form.append(fieldset);
        });

        const formAlert = el('div', 'form-alert');
        formAlert.setAttribute('role', 'alert');
        formAlert.hidden = true;
        const submit = el('button', 'btn btn-primary', 'Entregar');
        submit.type = 'submit';
        const formActions = el('div', 'form-actions');
        formActions.append(submit);
        form.append(formAlert, formActions);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const selected = Array.prototype.map.call(form.querySelectorAll('input:checked'), function (input) {
                return input.value;
            });
            const unanswered = attempt.questions.filter(function (question) {
                return !form.querySelector('input[name="q' + question.id + '"]:checked');
            }).length;
            if (unanswered > 0 && !window.confirm('Tienes ' + unanswered + ' pregunta(s) sin responder; valdrán 0. ¿Entregar de todos modos?')) {
                return;
            }

            ui.hideAlert(formAlert);
            ui.setBusy(form, true, 'Entregando…');
            post('api/student/attempts/' + attempt.id + '/submit', { respuestas: selected })
                .then(function (result) {
                    if (!result.ok) {
                        ui.setBusy(form, false);
                        ui.showApiError(form, formAlert, result);
                        return;
                    }
                    renderReview(result.body.data.attempt);
                })
                .catch(function () {
                    ui.setBusy(form, false);
                    ui.showAlert(formAlert, ui.NETWORK_ERROR);
                });
        });

        stage.append(form);
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /* ---------- Resultado: calificación, puntos por pregunta y respuestas correctas ---------- */
    function renderReview(attempt) {
        stage.textContent = '';
        const box = el('div', 'panel-body exam-review');

        const result = el('div', 'exam-result ' + (attempt.passed ? 'is-passed' : 'is-failed'));
        result.setAttribute('role', 'status');
        result.append(
            el('p', 'exam-result-title', attempt.passed ? 'Aprobado' : 'No aprobado'),
            el('p', '', 'Calificación: ' + attempt.grade + ' (mínimo ' + attempt.minimumGrade + ') · ' + attempt.earnedPoints + ' de ' + attempt.maxPoints + ' puntos')
        );
        box.append(result);

        attempt.questions.forEach(function (question, index) {
            const block = el('div', 'exam-question');
            const header = el('div', 'exam-question-header');
            header.append(
                el('p', 'exam-question-text', (index + 1) + '. ' + question.text),
                el('span', 'badge' + (question.earnedPoints === question.maxPoints ? ' badge-success' : ''), question.earnedPoints + ' / ' + question.maxPoints + ' pts')
            );
            block.append(header);

            const list = el('ul', 'exam-answers');
            question.answers.forEach(function (answer) {
                const item = el('li', 'exam-answer-review' + (answer.correct ? ' is-correct' : '') + (answer.selected && !answer.correct ? ' is-wrong' : ''));
                item.append(el('span', '', answer.text));
                if (answer.selected) {
                    item.append(el('span', 'badge', 'Tu respuesta'));
                }
                if (answer.correct) {
                    item.append(el('span', 'badge badge-success', 'Correcta'));
                }
                list.append(item);
            });
            block.append(list);
            box.append(block);
        });

        const retry = el('button', 'btn btn-secondary', 'Intentar de nuevo');
        retry.type = 'button';
        retry.addEventListener('click', start);
        const reviewActions = el('div', 'learn-actions');
        reviewActions.append(retry);
        const next = root.querySelector('[data-exam-next]');
        if (next && (attempt.passed || attempt.chapterCompleted)) {
            const nextCopy = next.cloneNode(true);
            nextCopy.hidden = false;
            nextCopy.classList.replace('btn-secondary', 'btn-primary');
            reviewActions.append(nextCopy);
        }
        box.append(reviewActions);
        stage.append(box);

        addToHistory(attempt);
        if (attempt.passed) {
            root.querySelector('[data-exam-passed]').hidden = false;
            window.Cursology.learning.markChapterDone(chapterId, 'Aprobado');
            window.Cursology.learning.announceCompletion(attempt);
        }
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function addToHistory(attempt) {
        const empty = history.querySelector('[data-exam-history-empty]');
        if (empty) {
            empty.remove();
        }
        Array.prototype.forEach.call(history.querySelectorAll('tr'), function (row) {
            if (row.dataset.attemptNumber === String(attempt.number) || row.cells[0].textContent === '#' + attempt.number) {
                row.remove();
            }
        });
        const row = el('tr');
        row.dataset.attemptNumber = String(attempt.number);
        const status = el('td');
        status.append(el('span', attempt.passed ? 'badge badge-success' : 'badge badge-danger', attempt.passed ? 'Aprobado' : 'No aprobado'));
        const grade = el('td', 'num', attempt.grade);
        row.append(el('td', '', '#' + attempt.number), el('td', '', 'Ahora'), grade, status);
        history.prepend(row);

        const best = root.querySelector('[data-exam-best]');
        if (best.textContent === '—' || Number(attempt.grade) > Number(best.textContent)) {
            best.textContent = attempt.grade;
        }
    }

    startButton.addEventListener('click', start);
})();
