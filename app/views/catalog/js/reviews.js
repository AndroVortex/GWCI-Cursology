(function () {
    'use strict';

    const section = document.querySelector('[data-reviews]');
    if (!section) {
        return;
    }

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const courseId = section.dataset.courseId;
    const canModerate = section.dataset.canModerate === '1';
    const list = section.querySelector('[data-review-list]');
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
        return error && error.message ? error.message : fallback;
    }

    /* ---------- Tu calificación ---------- */
    const form = section.querySelector('[data-review-form]');
    if (form) {
        const alert = form.querySelector('[data-form-alert]');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            ui.clearErrors(form);
            ui.hideAlert(alert);
            const checked = form.querySelector('input[name="calificacion"]:checked');
            if (!checked) {
                ui.showError(form, 'calificacion', 'Elige de 1 a 5 estrellas.');
                return;
            }

            ui.setBusy(form, true, 'Guardando…');
            api('api/student/courses/' + encodeURIComponent(courseId) + '/review', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ calificacion: Number(checked.value), opinion: form.elements.opinion.value }),
            })
                .then(function (result) {
                    if (result.ok) {
                        window.Cursology.goWithMessage(null, 'Tu calificación se guardó. ¡Gracias!');
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

    /* ---------- Ocultar (instructor del curso o admin) ---------- */
    function hideOpinion(button) {
        const reason = window.prompt('¿Por qué ocultas esta opinión? (de 3 a 500 caracteres)');
        if (reason === null) {
            return;
        }
        button.disabled = true;
        api('api/opinions/' + encodeURIComponent(button.dataset.hideOpinion) + '/hide', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ motivo: reason }),
        })
            .then(function (result) {
                if (result.ok) {
                    window.Cursology.goWithMessage(null, 'Opinión ocultada. Sus estrellas ya no cuentan en el promedio.');
                    return;
                }
                button.disabled = false;
                ui.showAlert(pageAlert, errorMessage(result, 'No se pudo ocultar la opinión.'));
            })
            .catch(function () {
                button.disabled = false;
                ui.showAlert(pageAlert, ui.NETWORK_ERROR);
            });
    }

    list.addEventListener('click', function (event) {
        const button = event.target.closest('[data-hide-opinion]');
        if (button) {
            hideOpinion(button);
        }
    });

    /* ---------- Ver más opiniones ---------- */
    function stars(rate) {
        const value = Math.max(0, Math.min(5, rate || 0));
        return '★★★★★'.slice(0, value) + '☆☆☆☆☆'.slice(0, 5 - value);
    }

    function renderReview(review) {
        const item = el('li', 'review');
        item.dataset.opinionId = review.id;
        const avatar = el('img', 'review-avatar');
        avatar.src = review.avatar;
        avatar.alt = '';
        const body = el('div', 'review-body');
        const head = el('p', 'review-head');
        const date = new Date(review.commentedAt).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
        const starsLabel = el('span', 'review-stars', stars(review.rate));
        starsLabel.setAttribute('aria-label', (review.rate || 0) + ' de 5 estrellas');
        head.append(el('strong', '', review.author), starsLabel, el('span', 'review-date', date + (review.edited ? ' · editada' : '')));
        body.append(head, el('p', 'review-text', review.opinion));
        if (canModerate) {
            const hide = el('button', 'btn btn-ghost btn-sm', 'Ocultar');
            hide.type = 'button';
            hide.dataset.hideOpinion = review.id;
            body.append(hide);
        }
        item.append(avatar, body);
        return item;
    }

    const more = section.querySelector('[data-reviews-more]');
    if (more) {
        more.addEventListener('click', function () {
            more.disabled = true;
            api('api/catalog/courses/' + encodeURIComponent(courseId) + '/reviews?pagina=' + more.dataset.nextPage)
                .then(function (result) {
                    more.disabled = false;
                    if (!result.ok) {
                        ui.showAlert(pageAlert, errorMessage(result, 'No se pudieron cargar más opiniones.'));
                        return;
                    }
                    const data = result.body.data;
                    data.reviews.forEach(function (review) {
                        list.append(renderReview(review));
                    });
                    if (data.page >= data.pages) {
                        more.remove();
                    } else {
                        more.dataset.nextPage = String(data.page + 1);
                    }
                })
                .catch(function () {
                    more.disabled = false;
                    ui.showAlert(pageAlert, ui.NETWORK_ERROR);
                });
        });
    }
})();
