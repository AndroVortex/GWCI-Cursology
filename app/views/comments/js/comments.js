(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const reactions = window.Cursology.reactions;
    const MAX_CHARS = 2000;

    /* [data-comments]: el panel del reproductor (con data-chapter-id) o la vista del instructor. */
    const root = document.querySelector('[data-comments]');
    if (!root) {
        return;
    }
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

    function sendJson(url, method, body) {
        const options = { method: method, headers: { 'Content-Type': 'application/json' } };
        if (body !== undefined) {
            options.body = JSON.stringify(body);
        }
        return api(url, options);
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

    function commentUrl(id) {
        return 'api/comments/' + encodeURIComponent(id);
    }

    /* ---------- Marcado: el mismo que comment.html y comment-thread.html ---------- */
    function actionButton(label, key, danger) {
        const button = el('button', 'comment-action' + (danger ? ' comment-action-danger' : ''), label);
        button.type = 'button';
        button.dataset[key] = '';
        return button;
    }

    function removedNode(comment) {
        const article = el('article', 'comment is-removed');
        article.id = 'comentario-' + comment.id;
        article.dataset.comment = comment.id;
        article.dataset.author = comment.author;
        article.append(el('p', 'comment-removed', 'Comentario eliminado'));
        return article;
    }

    function commentNode(comment) {
        if (comment.removed) {
            return removedNode(comment);
        }
        const article = el('article', 'comment');
        article.id = 'comentario-' + comment.id;
        article.dataset.comment = comment.id;
        article.dataset.author = comment.author;

        const avatar = el('img', 'account-avatar comment-avatar');
        avatar.src = comment.avatar;
        avatar.alt = '';
        avatar.loading = 'lazy';

        const head = el('p', 'comment-head');
        head.append(el('strong', 'comment-author', comment.author));
        if (comment.isInstructor) {
            head.append(el('span', 'badge badge-exam', 'Instructor'));
        }
        const time = el('time', 'comment-date', comment.commentedAtLabel);
        time.dateTime = comment.commentedAt;
        const edited = el('span', 'comment-edited', '· editado');
        edited.dataset.commentEdited = '';
        edited.hidden = !comment.edited;
        head.append(time, edited);
        if (comment.answered === false && !comment.isInstructor) {
            const badge = el('span', 'badge badge-warning', 'Sin responder');
            badge.dataset.unanswered = '';
            head.append(badge);
        }

        const text = el('p', 'comment-text', comment.text || '');
        text.dataset.commentText = '';

        const actions = el('div', 'comment-actions');
        actions.dataset.reportHost = '';
        if (comment.can.reply) {
            actions.append(actionButton('Responder', 'commentReply'));
        }
        if (comment.can.edit) {
            actions.append(actionButton('Editar', 'commentEdit'));
        }
        if (comment.can.delete) {
            actions.append(actionButton('Eliminar', 'commentDelete', true));
        }
        if (comment.can.hide) {
            actions.append(actionButton('Ocultar', 'commentHide', true));
        }
        if (comment.can.report && window.Cursology.report) {
            actions.append(window.Cursology.report.button(commentUrl(comment.id) + '/report', comment.reported));
        }

        const body = el('div', 'comment-body');
        body.append(head, text);
        if (reactions) {
            body.append(reactions.node(comment.reactions, commentUrl(comment.id) + '/reaction'));
        }
        body.append(actions);
        article.append(avatar, body);
        return article;
    }

    function replyItem(comment) {
        const item = el('li');
        item.append(commentNode(comment));
        return item;
    }

    function threadNode(comment) {
        const thread = el('li', 'comment-thread');
        thread.dataset.thread = comment.id;
        thread.dataset.chapterId = comment.chapterId;
        const replies = el('ol', 'comment-replies');
        replies.dataset.commentReplies = '';
        (comment.replies || []).forEach(function (reply) {
            replies.append(replyItem(reply));
        });
        thread.append(commentNode(comment), replies);
        return thread;
    }

    /* ---------- Total y estado vacío del panel del reproductor ---------- */
    const totalLabel = root.querySelector('[data-comments-total]');
    const emptyNote = root.querySelector('[data-comments-empty]');

    function adjustTotal(delta) {
        if (totalLabel) {
            totalLabel.textContent = String(Math.max(0, Number(totalLabel.textContent) + delta));
        }
        if (emptyNote) {
            emptyNote.hidden = root.querySelector('.comment-thread') !== null;
        }
    }

    /* ---------- Formularios en línea ---------- */
    function inlineForm(options) {
        const form = el('form', 'comment-inline-form');
        form.noValidate = true;
        form.dataset[options.key] = '';
        const alert = el('div', 'form-alert');
        alert.setAttribute('role', 'alert');
        alert.hidden = true;
        const field = el('div', 'form-field');
        const label = el('label', 'visually-hidden', options.label);
        const input = el(options.multiline ? 'textarea' : 'input', 'form-control');
        const inputId = 'comment-field-' + Math.random().toString(36).slice(2);
        input.id = inputId;
        label.htmlFor = inputId;
        input.name = options.name;
        input.maxLength = options.maxLength;
        if (options.multiline) {
            input.rows = 2;
        } else {
            input.type = 'text';
        }
        input.placeholder = options.placeholder || '';
        input.value = options.value || '';
        const error = el('p', 'field-error');
        error.setAttribute('aria-live', 'polite');
        field.append(label, input, error);
        if (options.hint) {
            form.append(el('p', 'form-hint', options.hint));
        }
        const actions = el('div', 'form-actions');
        const submit = el('button', 'btn btn-primary btn-sm', options.submitLabel);
        submit.type = 'submit';
        const cancel = el('button', 'btn btn-ghost btn-sm', 'Cancelar');
        cancel.type = 'button';
        cancel.addEventListener('click', function () {
            form.remove();
            if (options.onCancel) {
                options.onCancel();
            }
        });
        actions.append(submit, cancel);
        form.append(alert, field, actions);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.clearErrors(form);
            ui.hideAlert(alert);
            const value = input.value.trim();
            const problem = options.validate(value);
            if (problem) {
                ui.showError(form, options.name, problem);
                input.focus();
                return;
            }
            ui.setBusy(form, true, options.busyLabel);
            options.send(value)
                .then(function (result) {
                    if (!result.ok) {
                        ui.setBusy(form, false);
                        ui.showApiError(form, alert, result);
                        return;
                    }
                    form.remove();
                    options.done(result.body.data);
                })
                .catch(function () {
                    ui.setBusy(form, false);
                    ui.showAlert(alert, ui.NETWORK_ERROR);
                });
        });

        return { form: form, input: input };
    }

    function textProblem(value) {
        if (value === '') {
            return 'Escribe tu comentario.';
        }
        return Array.from(value).length > MAX_CHARS ? 'No puede exceder ' + MAX_CHARS + ' caracteres.' : null;
    }

    function openReply(article) {
        const thread = article.closest('.comment-thread');
        const previous = thread.querySelector('[data-reply-form]');
        if (previous) {
            previous.remove();
        }
        const created = inlineForm({
            key: 'replyForm',
            name: 'texto',
            label: 'Tu respuesta',
            multiline: true,
            maxLength: MAX_CHARS,
            placeholder: 'Escribe tu respuesta…',
            hint: 'Respondiendo a ' + article.dataset.author,
            submitLabel: 'Responder',
            busyLabel: 'Enviando…',
            validate: textProblem,
            send: function (value) {
                return sendJson('api/chapters/' + encodeURIComponent(thread.dataset.chapterId) + '/comments', 'POST', {
                    texto: value,
                    responde_a: article.dataset.comment,
                });
            },
            done: function (data) {
                thread.querySelector('[data-comment-replies]').append(replyItem(data.comment));
                if (data.comment.isInstructor) {
                    thread.querySelectorAll('[data-unanswered]').forEach(function (badge) { badge.remove(); });
                }
            },
        });
        thread.querySelector('[data-comment-replies]').after(created.form);
        created.input.focus();
    }

    function openEdit(article) {
        if (article.querySelector('[data-edit-form]')) {
            return;
        }
        const text = article.querySelector('[data-comment-text]');
        const actions = article.querySelector('.comment-actions');
        text.hidden = true;
        actions.hidden = true;
        const created = inlineForm({
            key: 'editForm',
            name: 'texto',
            label: 'Editar comentario',
            multiline: true,
            maxLength: MAX_CHARS,
            value: text.textContent,
            submitLabel: 'Guardar',
            busyLabel: 'Guardando…',
            validate: textProblem,
            send: function (value) {
                return sendJson(commentUrl(article.dataset.comment), 'PATCH', { texto: value });
            },
            done: function (data) {
                text.textContent = data.comment.text || '';
                text.hidden = false;
                actions.hidden = false;
                article.querySelector('[data-comment-edited]').hidden = !data.comment.edited;
            },
            onCancel: function () {
                text.hidden = false;
                actions.hidden = false;
            },
        });
        text.after(created.form);
        created.input.focus();
    }

    function openHide(article) {
        if (article.querySelector('[data-hide-form]')) {
            return;
        }
        const actions = article.querySelector('.comment-actions');
        actions.hidden = true;
        const created = inlineForm({
            key: 'hideForm',
            name: 'motivo',
            label: 'Motivo',
            multiline: false,
            maxLength: 500,
            placeholder: 'Motivo (lo ve el autor), p. ej. "Comparte las respuestas del examen"',
            hint: 'El comentario deja de verse para los alumnos.',
            submitLabel: 'Ocultar',
            busyLabel: 'Ocultando…',
            validate: function (value) {
                const length = Array.from(value).length;
                return length < 3 || length > 500 ? 'Escribe el motivo (de 3 a 500 caracteres).' : null;
            },
            send: function (value) {
                return sendJson(commentUrl(article.dataset.comment) + '/hide', 'POST', { motivo: value });
            },
            done: function (data) {
                applyRemoval(article, data.comment);
            },
            onCancel: function () {
                actions.hidden = false;
            },
        });
        actions.after(created.form);
        created.input.focus();
    }

    /* Un comentario eliminado u oculto: si es principal y tiene respuestas queda "Comentario eliminado". */
    function applyRemoval(article, comment) {
        const thread = article.closest('.comment-thread');
        const isRoot = article.parentElement === thread;
        if (!isRoot) {
            article.closest('li').remove();
        }
        const remainingReplies = thread.querySelectorAll('[data-comment-replies] > li').length;
        const rootArticle = thread.querySelector(':scope > .comment');
        if (isRoot && remainingReplies > 0) {
            rootArticle.replaceWith(removedNode(comment));
            return;
        }
        if ((isRoot || rootArticle.classList.contains('is-removed')) && remainingReplies === 0) {
            thread.remove();
            adjustTotal(-1);
        }
    }

    function remove(article, button) {
        if (!window.confirm('¿Eliminar tu comentario? No se puede deshacer.')) {
            return;
        }
        button.disabled = true;
        ui.hideAlert(pageAlert);
        sendJson(commentUrl(article.dataset.comment), 'DELETE')
            .then(function (result) {
                if (result.ok) {
                    applyRemoval(article, result.body.data.comment);
                    return;
                }
                button.disabled = false;
                ui.showAlert(pageAlert, errorMessage(result, 'No se pudo eliminar el comentario.'));
            })
            .catch(function () {
                button.disabled = false;
                ui.showAlert(pageAlert, ui.NETWORK_ERROR);
            });
    }

    root.addEventListener('click', function (event) {
        const button = event.target.closest('[data-comment-reply], [data-comment-edit], [data-comment-delete], [data-comment-hide]');
        if (!button) {
            return;
        }
        const article = button.closest('[data-comment]');
        if (button.hasAttribute('data-comment-reply')) {
            openReply(article);
        } else if (button.hasAttribute('data-comment-edit')) {
            openEdit(article);
        } else if (button.hasAttribute('data-comment-delete')) {
            remove(article, button);
        } else {
            openHide(article);
        }
    });

    /* ---------- Comentario nuevo (reproductor) ---------- */
    const form = root.querySelector('[data-comment-form]');
    const list = root.querySelector('[data-comment-list]');
    if (form && list) {
        const alert = form.querySelector('[data-form-alert]');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.clearErrors(form);
            ui.hideAlert(alert);
            const value = form.elements.texto.value.trim();
            const problem = textProblem(value);
            if (problem) {
                ui.showError(form, 'texto', problem);
                form.elements.texto.focus();
                return;
            }
            ui.setBusy(form, true, 'Publicando…');
            sendJson('api/chapters/' + encodeURIComponent(root.dataset.chapterId) + '/comments', 'POST', { texto: value })
                .then(function (result) {
                    ui.setBusy(form, false);
                    if (!result.ok) {
                        ui.showApiError(form, alert, result);
                        return;
                    }
                    list.prepend(threadNode(result.body.data.comment));
                    form.elements.texto.value = '';
                    adjustTotal(1);
                })
                .catch(function () {
                    ui.setBusy(form, false);
                    ui.showAlert(alert, ui.NETWORK_ERROR);
                });
        });
    }

    /* ---------- Ver más (reproductor) ---------- */
    const more = root.querySelector('[data-comments-more]');
    if (more && list) {
        more.addEventListener('click', function () {
            const label = more.textContent;
            more.disabled = true;
            more.textContent = 'Cargando…';
            api('api/chapters/' + encodeURIComponent(root.dataset.chapterId) + '/comments?pagina=' + more.dataset.nextPage)
                .then(function (result) {
                    more.disabled = false;
                    more.textContent = label;
                    if (!result.ok) {
                        ui.showAlert(pageAlert, errorMessage(result, 'No se pudieron cargar más comentarios.'));
                        return;
                    }
                    const data = result.body.data;
                    data.comments.forEach(function (comment) {
                        if (!list.querySelector('[data-thread="' + comment.id + '"]')) {
                            list.append(threadNode(comment));
                        }
                    });
                    if (data.page >= data.pages) {
                        more.remove();
                    } else {
                        more.dataset.nextPage = String(data.page + 1);
                    }
                })
                .catch(function () {
                    more.disabled = false;
                    more.textContent = label;
                    ui.showAlert(pageAlert, ui.NETWORK_ERROR);
                });
        });
    }
})();
