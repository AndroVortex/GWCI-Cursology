(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;

    /*
     * Botones [data-start-chat="<id del curso>"]: abren la conversación con el instructor del
     * curso (la crean si no existe). En el detalle de ventas también llevan
     * data-student="<id del alumno>", porque ahí quien escribe es el instructor.
     */
    document.querySelectorAll('[data-start-chat]').forEach(function (button) {
        button.addEventListener('click', function () {
            const pageAlert = document.querySelector('[data-page-alert]');
            const label = button.textContent;
            const body = { curso: button.dataset.startChat };
            if (button.dataset.student) {
                body.alumno = button.dataset.student;
            }
            button.disabled = true;
            button.textContent = 'Abriendo…';
            ui.hideAlert(pageAlert);

            api('api/chats', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            })
                .then(function (result) {
                    const chat = result.ok ? result.body.data.chat : null;
                    if (chat && chat.url) {
                        window.location.assign(chat.url);
                        return;
                    }
                    button.disabled = false;
                    button.textContent = label;
                    const error = result.body && result.body.error ? result.body.error.message : null;
                    ui.showAlert(pageAlert, error || 'No se pudo abrir la conversación. Inténtalo de nuevo.');
                })
                .catch(function () {
                    button.disabled = false;
                    button.textContent = label;
                    ui.showAlert(pageAlert, ui.NETWORK_ERROR);
                });
        });
    });
})();
