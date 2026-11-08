(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const POLL_MS = 10000;
    const MAX_CHARS = 2000;

    const thread = document.querySelector('[data-chat]');
    if (!thread) {
        return;
    }

    const chatId = thread.dataset.chat;
    const list = thread.querySelector('[data-chat-messages]');
    const empty = thread.querySelector('[data-chat-empty]');
    const form = thread.querySelector('[data-chat-composer]');
    const input = form.querySelector('[data-chat-input]');
    const alertBox = form.querySelector('[data-chat-alert]');
    const counter = form.querySelector('[data-chat-count]');
    const preview = form.querySelector('[data-reply-preview]');
    const previewAuthor = preview.querySelector('[data-reply-author]');
    const previewText = preview.querySelector('[data-reply-text]');
    const messagesUrl = 'api/chats/' + encodeURIComponent(chatId) + '/messages';

    let lastId = thread.dataset.lastMessage || '';
    let replyTo = null;
    let polling = false;
    let sending = false;
    let stopped = false;

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

    function excerpt(text, maxChars) {
        const chars = Array.from(text.replace(/\s+/g, ' ').trim());
        return chars.length <= maxChars ? chars.join('') : chars.slice(0, maxChars - 1).join('').trimEnd() + '…';
    }

    // Los ids son BIGINT en texto: se comparan por longitud y luego como cadena.
    function isNewer(id, than) {
        return than === '' || id.length > than.length || (id.length === than.length && id > than);
    }

    function nearBottom() {
        return list.scrollHeight - list.scrollTop - list.clientHeight < 80;
    }

    function scrollToBottom() {
        list.scrollTop = list.scrollHeight;
    }

    /* Mismo marcado que chat-message.html. El texto va siempre con textContent, nunca como HTML. */
    function messageNode(message) {
        const item = el('li', 'chat-message' + (message.mine ? ' is-mine' : ''));
        item.id = 'mensaje-' + message.id;
        item.dataset.messageId = message.id;
        item.dataset.author = message.author;

        if (!message.mine) {
            const avatar = el('img', 'account-avatar chat-message-avatar');
            avatar.src = message.avatar;
            avatar.alt = '';
            avatar.loading = 'lazy';
            item.append(avatar);
        }

        const bubble = el('div', 'chat-bubble');
        if (message.replyTo) {
            const quote = el('a', 'chat-quote');
            quote.href = '#mensaje-' + message.replyTo;
            quote.append(el('strong', '', message.replyAuthor || ''), el('span', '', message.replyExcerpt || 'Mensaje no disponible'));
            bubble.append(quote);
        }
        bubble.append(el('p', 'chat-text', message.text));

        const meta = el('p', 'chat-meta');
        if (!message.mine) {
            meta.append(el('span', 'chat-author', message.author), document.createTextNode(' · '));
        }
        const time = el('time', '', message.sentAtLabel);
        time.dateTime = message.sentAt;
        const reply = el('button', 'chat-reply-btn', 'Responder');
        reply.type = 'button';
        reply.dataset.reply = '';
        meta.append(time, document.createTextNode(' · '), reply);
        bubble.append(meta);
        item.append(bubble);

        return item;
    }

    function appendMessages(messages, forceScroll) {
        const stick = forceScroll || nearBottom();
        let added = false;
        messages.forEach(function (message) {
            if (list.querySelector('[data-message-id="' + message.id + '"]')) {
                return;
            }
            list.insertBefore(messageNode(message), empty);
            added = true;
        });
        if (added) {
            empty.hidden = true;
            if (stick) {
                scrollToBottom();
            }
        }
    }

    /* El contador del menú y la conversación abierta en la lista de la izquierda. */
    function updateUnreadTotal(total) {
        document.querySelectorAll('[data-unread-total]').forEach(function (badge) {
            const visible = badge.querySelector('[aria-hidden="true"]');
            const label = badge.querySelector('.visually-hidden');
            if (visible) {
                visible.textContent = total > 99 ? '99+' : String(total);
            }
            if (label) {
                label.textContent = total + ' sin leer';
            }
            badge.hidden = total === 0;
        });
    }

    function updateActiveItem(message) {
        const item = document.querySelector('[data-chat-item="' + chatId + '"]');
        if (!item) {
            return;
        }
        const text = item.querySelector('[data-chat-excerpt]');
        const date = item.querySelector('[data-chat-date]');
        const unread = item.querySelector('[data-chat-unread]');
        if (text) {
            text.textContent = (message.mine ? 'Tú: ' : '') + excerpt(message.text, 90);
        }
        if (date) {
            date.textContent = message.sentAtLabel;
            date.dateTime = message.sentAt;
        }
        if (unread) {
            unread.remove();
        }
        item.classList.remove('has-unread');
        const row = item.closest('li');
        if (row && row.parentElement && row.parentElement.firstElementChild !== row) {
            row.parentElement.prepend(row);
        }
    }

    /* Pide los mensajes posteriores al último que se ve; la API marca la conversación leída. */
    function poll() {
        if (polling || stopped) {
            return Promise.resolve();
        }
        polling = true;

        return api(messagesUrl + (lastId === '' ? '' : '?after=' + encodeURIComponent(lastId)))
            .then(function (result) {
                if (result.status === 401 || result.status === 403 || result.status === 404) {
                    stopped = true;
                    return;
                }
                if (!result.ok || !result.body || !result.body.data) {
                    return;
                }
                const data = result.body.data;
                appendMessages(data.messages, false);
                if (data.messages.length > 0) {
                    updateActiveItem(data.messages[data.messages.length - 1]);
                }
                if (data.lastMessageId && isNewer(data.lastMessageId, lastId)) {
                    lastId = data.lastMessageId;
                }
                updateUnreadTotal(data.unreadTotal);
            })
            .catch(function () {
                /* sin conexión: se vuelve a intentar en el siguiente ciclo */
            })
            .finally(function () {
                polling = false;
            });
    }

    function setReply(item) {
        replyTo = item.dataset.messageId;
        previewAuthor.textContent = item.dataset.author;
        const text = item.querySelector('.chat-text');
        previewText.textContent = text ? excerpt(text.textContent, 120) : '';
        preview.hidden = false;
        input.focus();
    }

    function clearReply() {
        replyTo = null;
        preview.hidden = true;
        previewAuthor.textContent = '';
        previewText.textContent = '';
    }

    function updateCounter() {
        counter.textContent = String(Array.from(input.value).length);
    }

    list.addEventListener('click', function (event) {
        const button = event.target.closest('[data-reply]');
        if (button) {
            setReply(button.closest('[data-message-id]'));
        }
    });

    preview.querySelector('[data-reply-cancel]').addEventListener('click', function () {
        clearReply();
        input.focus();
    });

    input.addEventListener('input', updateCounter);

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            form.requestSubmit();
        } else if (event.key === 'Escape' && replyTo !== null) {
            clearReply();
        }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (sending) {
            return;
        }
        ui.clearErrors(form);
        ui.hideAlert(alertBox);

        const text = input.value.trim();
        if (text === '') {
            ui.showError(form, 'texto', 'Escribe tu mensaje.');
            input.focus();
            return;
        }
        if (Array.from(text).length > MAX_CHARS) {
            ui.showError(form, 'texto', 'No puede exceder ' + MAX_CHARS + ' caracteres.');
            input.focus();
            return;
        }

        const body = { texto: text };
        if (replyTo !== null) {
            body.responde_a = replyTo;
        }
        sending = true;
        ui.setBusy(form, true, 'Enviando…');

        api(messagesUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
            .then(function (result) {
                if (!result.ok) {
                    ui.showApiError(form, alertBox, result);
                    return null;
                }
                const message = result.body.data.message;
                appendMessages([message], true);
                updateActiveItem(message);
                input.value = '';
                updateCounter();
                clearReply();
                // No se adelanta lastId: el sondeo trae lo que el otro haya enviado antes de este mensaje.
                return poll();
            })
            .catch(function () {
                ui.showAlert(alertBox, ui.NETWORK_ERROR);
            })
            .finally(function () {
                sending = false;
                ui.setBusy(form, false);
                input.focus();
            });
    });

    if (!window.location.hash) {
        scrollToBottom();
    }
    updateCounter();

    window.setInterval(function () {
        if (document.visibilityState === 'visible') {
            poll();
        }
    }, POLL_MS);

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            poll();
        }
    });
})();
