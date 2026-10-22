(function () {
    'use strict';

    const form = document.getElementById('course-form');
    if (!form) {
        return;
    }

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const media = window.Cursology.media;
    const alert = form.querySelector('[data-form-alert]');
    const courseId = form.dataset.courseId;
    const amount = form.querySelector('[data-price-amount]');

    function priceMode() {
        const checked = form.querySelector('input[name="precio_modo"]:checked');
        return checked ? checked.value : '';
    }

    form.querySelectorAll('input[name="precio_modo"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            amount.hidden = priceMode() !== 'fijo';
            if (!amount.hidden) {
                form.elements.precio.focus();
            }
        });
    });

    function uploadOf(name) {
        return media.result(form.querySelector('[data-upload-name="' + name + '"]'));
    }

    // Mismas reglas básicas que CourseValidator; el servidor vuelve a validar todo.
    function validate(body) {
        const errors = {};
        if (body.titulo.trim() === '') {
            errors.titulo = 'Escribe el título del curso.';
        }
        if (body.descripcion.trim() === '') {
            errors.descripcion = 'Escribe la descripción del curso.';
        }
        if (body.categorias.length === 0) {
            errors.categorias = 'Elige al menos una categoría.';
        } else if (body.categorias.length > 5) {
            errors.categorias = 'Elige como máximo 5 categorías.';
        }
        if (body.precio_modo === 'fijo' && !/^(0|[1-9]\d{0,9})(\.\d{1,2})?$/.test(body.precio.trim())) {
            errors.precio = 'Escribe un precio válido, por ejemplo 499 o 499.90.';
        }
        if (!courseId && !body.portada) {
            errors.portada = 'Sube la portada del curso.';
        }
        if (!courseId && !body.video) {
            errors.video = 'Sube el video promocional.';
        }
        return errors;
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        ui.clearErrors(form);
        ui.hideAlert(alert);

        if (media.isUploading(form)) {
            ui.showAlert(alert, 'Espera a que terminen de subirse los archivos.');
            return;
        }

        const body = {
            titulo: form.elements.titulo.value,
            descripcion: form.elements.descripcion.value,
            certificador: form.elements.certificador.value,
            categorias: Array.prototype.map.call(
                form.querySelectorAll('input[name="categorias"]:checked'),
                function (input) { return input.value; }
            ),
            precio_modo: priceMode(),
            precio: form.elements.precio.value,
            portada: uploadOf('portada'),
            video: uploadOf('video'),
        };

        const errors = validate(body);
        const names = Object.keys(errors);
        if (names.length > 0) {
            names.forEach(function (name) {
                ui.showError(form, name, errors[name]);
            });
            ui.showAlert(alert, 'Revisa los campos marcados.');
            return;
        }

        ui.setBusy(form, true, courseId ? 'Guardando…' : 'Creando…');
        api(courseId ? 'api/instructor/courses/' + courseId : 'api/instructor/courses', {
            method: courseId ? 'PATCH' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
            .then(function (result) {
                if (result.ok) {
                    if (courseId) {
                        window.Cursology.goWithMessage(null, 'Cambios del curso guardados.');
                    } else {
                        window.Cursology.goWithMessage(
                            '?view=course-editor&id=' + encodeURIComponent(result.body.data.course.id),
                            'Borrador creado. Ahora agrega los niveles del curso.'
                        );
                    }
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
})();
