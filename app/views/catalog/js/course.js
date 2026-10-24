(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const alert = document.querySelector('.course-buy [data-form-alert]');

    /* ---------- Inscripción gratis (curso gratis o niveles de $0) ---------- */
    document.querySelectorAll('[data-enroll-free]').forEach(function (button) {
        button.addEventListener('click', function () {
            const courseId = button.dataset.enrollFree;
            button.disabled = true;
            ui.hideAlert(alert);
            api('api/student/enrollments', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ curso: courseId }),
            })
                .then(function (result) {
                    if (result.ok) {
                        window.Cursology.goWithMessage('?view=learn&course=' + encodeURIComponent(courseId), '¡Listo! Ya estás inscrito.');
                        return;
                    }
                    button.disabled = false;
                    const error = result.body && result.body.error ? result.body.error.message : null;
                    ui.showAlert(alert, error || 'No se pudo completar la inscripción.');
                })
                .catch(function () {
                    button.disabled = false;
                    ui.showAlert(alert, ui.NETWORK_ERROR);
                });
        });
    });
})();
