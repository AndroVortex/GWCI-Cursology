(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;

    /*
     * Botones [data-certificate="<id del curso>"]: emiten (o recuperan) el certificado del curso
     * terminado, se cambian por el enlace "Descargar" y empiezan la descarga del PDF.
     */
    document.querySelectorAll('[data-certificate]').forEach(function (button) {
        button.addEventListener('click', function () {
            const pageAlert = document.querySelector('[data-page-alert]');
            const label = button.textContent;
            button.disabled = true;
            button.textContent = 'Generando…';
            ui.hideAlert(pageAlert);

            api('api/student/courses/' + encodeURIComponent(button.dataset.certificate) + '/certificate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            })
                .then(function (result) {
                    const certificate = result.ok ? result.body.data.certificate : null;
                    if (certificate && certificate.downloadUrl) {
                        const link = document.createElement('a');
                        link.className = button.className;
                        link.href = certificate.downloadUrl;
                        link.textContent = 'Descargar certificado';
                        button.replaceWith(link);
                        window.location.href = certificate.downloadUrl;
                        return;
                    }
                    button.disabled = false;
                    button.textContent = label;
                    const error = result.body && result.body.error ? result.body.error.message : null;
                    ui.showAlert(pageAlert, error || 'No se pudo generar el certificado. Inténtalo de nuevo.');
                })
                .catch(function () {
                    button.disabled = false;
                    button.textContent = label;
                    ui.showAlert(pageAlert, ui.NETWORK_ERROR);
                });
        });
    });
})();
