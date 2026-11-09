(function () {
    'use strict';

    const api = window.Cursology.api;
    const NETWORK_ERROR = window.Cursology.form.NETWORK_ERROR;

    /* El catálogo activo viene en la página (#reaction-catalog); sin él no se puede reaccionar. */
    let catalog = [];
    try {
        const source = document.getElementById('reaction-catalog');
        catalog = source ? JSON.parse(source.textContent) : [];
    } catch (e) {
        catalog = [];
    }

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

    function chip(item, canReact) {
        const button = el('button', 'reaction-chip' + (item.mine ? ' is-mine' : ''));
        button.type = 'button';
        button.dataset.reaction = item.id;
        button.title = item.name;
        button.setAttribute('aria-pressed', item.mine ? 'true' : 'false');
        button.disabled = !canReact;
        const emoji = el('span', 'reaction-emoji', item.emoji);
        emoji.setAttribute('aria-hidden', 'true');
        button.append(emoji, el('span', 'reaction-count', String(item.total)), el('span', 'visually-hidden', item.name));
        return button;
    }

    function addButton() {
        const button = el('button', 'reaction-add');
        button.type = 'button';
        button.dataset.reactionAdd = '';
        button.title = 'Reaccionar';
        button.setAttribute('aria-haspopup', 'true');
        button.setAttribute('aria-expanded', 'false');
        const svgNs = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(svgNs, 'svg');
        svg.setAttribute('width', '16');
        svg.setAttribute('height', '16');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('aria-hidden', 'true');
        [['circle', { cx: '12', cy: '12', r: '9' }], ['path', { d: 'M8.5 14.5a4 4 0 0 0 7 0' }], ['path', { d: 'M9 9.5h.01M15 9.5h.01' }]].forEach(function (shape) {
            const node = document.createElementNS(svgNs, shape[0]);
            Object.keys(shape[1]).forEach(function (name) { node.setAttribute(name, shape[1][name]); });
            svg.append(node);
        });
        button.append(svg, el('span', 'visually-hidden', 'Reaccionar'));
        return button;
    }

    /* Vuelve a dibujar las reacciones de un contenedor [data-reactions] con el resumen de la API. */
    function render(container, summary) {
        const canReact = Boolean(container.dataset.url);
        container.querySelectorAll('.reaction-chip').forEach(function (node) { node.remove(); });
        const anchor = container.querySelector('[data-reaction-add], .reaction-picker, .reaction-error');
        (summary.items || []).forEach(function (item) {
            container.insertBefore(chip(item, canReact), anchor);
        });
        container.dataset.mine = summary.mine || '';
    }

    /* Un contenedor nuevo, para los comentarios, opiniones y mensajes que se agregan sin recargar. */
    function node(summary, url) {
        const container = el('div', 'reactions');
        container.dataset.reactions = '';
        if (url) {
            container.dataset.url = url;
        }
        if (url && catalog.length > 0) {
            container.append(addButton());
        }
        render(container, summary || { items: [], mine: null });
        return container;
    }

    function mineOf(container) {
        if (container.dataset.mine !== undefined) {
            return container.dataset.mine || null;
        }
        const pressed = container.querySelector('.reaction-chip[aria-pressed="true"]');
        return pressed ? pressed.dataset.reaction : null;
    }

    function showError(container, message) {
        let error = container.querySelector('.reaction-error');
        if (!error) {
            error = el('span', 'reaction-error');
            error.setAttribute('role', 'alert');
            container.append(error);
        }
        error.textContent = message;
    }

    function closePickers(except) {
        document.querySelectorAll('.reaction-picker').forEach(function (picker) {
            if (picker !== except) {
                const add = picker.parentElement.querySelector('[data-reaction-add]');
                if (add) {
                    add.setAttribute('aria-expanded', 'false');
                }
                picker.remove();
            }
        });
    }

    function send(container, reactionId) {
        if (container.dataset.busy === '1') {
            return;
        }
        container.dataset.busy = '1';
        const error = container.querySelector('.reaction-error');
        if (error) {
            error.remove();
        }
        api(container.dataset.url, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ reaccion: reactionId }),
        })
            .then(function (result) {
                if (result.ok && result.body && result.body.data) {
                    render(container, result.body.data.reactions);
                    return;
                }
                const apiError = result.body && result.body.error ? result.body.error.message : null;
                showError(container, apiError || 'No se pudo guardar tu reacción.');
            })
            .catch(function () {
                showError(container, NETWORK_ERROR);
            })
            .finally(function () {
                container.dataset.busy = '0';
            });
    }

    function openPicker(container, add) {
        const mine = mineOf(container);
        const picker = el('div', 'reaction-picker');
        picker.setAttribute('role', 'menu');
        catalog.forEach(function (item) {
            const option = el('button', 'reaction-option', item.emoji);
            option.type = 'button';
            option.dataset.reactionOption = item.id;
            option.title = item.name;
            option.setAttribute('role', 'menuitemradio');
            option.setAttribute('aria-checked', item.id === mine ? 'true' : 'false');
            option.setAttribute('aria-label', item.name);
            picker.append(option);
        });
        add.after(picker);
        add.setAttribute('aria-expanded', 'true');
        const first = picker.querySelector('.reaction-option');
        if (first) {
            first.focus();
        }
    }

    document.addEventListener('click', function (event) {
        const container = event.target.closest('[data-reactions]');
        if (!container || !container.dataset.url) {
            closePickers(null);
            return;
        }

        const add = event.target.closest('[data-reaction-add]');
        if (add) {
            const open = container.querySelector('.reaction-picker');
            closePickers(null);
            if (!open) {
                openPicker(container, add);
            }
            return;
        }

        const option = event.target.closest('[data-reaction-option]');
        if (option) {
            const id = option.dataset.reactionOption;
            closePickers(null);
            send(container, id === mineOf(container) ? null : id);
            return;
        }

        const current = event.target.closest('.reaction-chip');
        if (current) {
            closePickers(null);
            send(container, current.getAttribute('aria-pressed') === 'true' ? null : current.dataset.reaction);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && document.querySelector('.reaction-picker')) {
            closePickers(null);
        }
    });

    window.Cursology.reactions = { node: node, render: render, catalog: catalog };
})();
