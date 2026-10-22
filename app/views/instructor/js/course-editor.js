(function () {
    'use strict';

    const section = document.getElementById('course-levels');
    if (!section) {
        return;
    }

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const media = window.Cursology.media;
    const courseId = section.dataset.courseId;
    const pageAlert = document.querySelector('[data-page-alert]');

    function send(method, url, body) {
        const options = { method: method };
        if (body !== undefined) {
            options.headers = { 'Content-Type': 'application/json' };
            options.body = JSON.stringify(body);
        }
        return api(url, options);
    }

    function errorMessage(result) {
        const error = result && result.body && result.body.error ? result.body.error : null;
        return error && error.message ? error.message : 'Ocurrió un error inesperado. Inténtalo de nuevo.';
    }

    // Recarga la página en la sección afectada y muestra el aviso al volver.
    function reloadAt(anchor, message) {
        if (anchor) {
            history.replaceState(null, '', '#' + anchor);
        }
        window.Cursology.goWithMessage(null, message);
    }

    /**
     * Envía un formulario del editor: valida subidas pendientes, bloquea el botón,
     * muestra errores por campo y, si todo sale bien, recarga en `anchor(result)`.
     */
    function submitForm(form, request, message, anchor) {
        const alert = form.querySelector('[data-form-alert]');
        ui.clearErrors(form);
        ui.hideAlert(alert);
        if (media.isUploading(form)) {
            ui.showAlert(alert, 'Espera a que termine de subirse el archivo.');
            return;
        }

        ui.setBusy(form, true);
        request()
            .then(function (result) {
                if (result.ok) {
                    reloadAt(anchor(result), message);
                    return;
                }
                ui.setBusy(form, false);
                ui.showApiError(form, alert, result);
            })
            .catch(function () {
                ui.setBusy(form, false);
                ui.showAlert(alert, ui.NETWORK_ERROR);
            });
    }

    /* ---------- Mostrar / ocultar formularios de edición ---------- */
    document.querySelectorAll('[data-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const target = document.getElementById(button.dataset.toggle);
            if (!target) {
                return;
            }
            target.hidden = !target.hidden;
            const first = target.querySelector('input:not([type="file"]), textarea');
            if (!target.hidden && first) {
                first.focus();
            }
        });
    });

    /* ---------- Niveles (agregar y editar) ---------- */
    section.querySelectorAll('[data-level-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const levelId = form.dataset.levelId;
            const body = {
                titulo: form.elements.titulo.value,
                descripcion: form.elements.descripcion.value,
                costo: form.elements.costo.value,
                video: media.result(form.querySelector('[data-upload-name="video"]')),
            };
            if (!levelId && !body.video) {
                ui.clearErrors(form);
                ui.showError(form, 'video', 'Sube el video de introducción del nivel.');
                return;
            }

            submitForm(
                form,
                function () {
                    return levelId
                        ? send('PATCH', 'api/instructor/levels/' + levelId, body)
                        : send('POST', 'api/instructor/courses/' + courseId + '/levels', body);
                },
                levelId ? 'Nivel actualizado.' : 'Nivel agregado. Ahora crea sus capítulos.',
                function (result) {
                    return 'level-' + (levelId || result.body.data.level.id);
                }
            );
        });
    });

    /* ---------- Capítulos (agregar y renombrar) ---------- */
    section.querySelectorAll('[data-chapter-add]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (form.elements.titulo.value.trim() === '') {
                ui.showError(form, 'titulo', 'Escribe el título del capítulo.');
                return;
            }
            submitForm(
                form,
                function () {
                    return send('POST', 'api/instructor/levels/' + form.dataset.levelId + '/chapters', {
                        titulo: form.elements.titulo.value,
                    });
                },
                'Capítulo agregado. Sube su video principal.',
                function (result) {
                    return 'chapter-' + result.body.data.chapter.id;
                }
            );
        });
    });

    section.querySelectorAll('[data-chapter-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const chapterId = form.dataset.chapterId;
            submitForm(
                form,
                function () {
                    return send('PATCH', 'api/instructor/chapters/' + chapterId, { titulo: form.elements.titulo.value });
                },
                'Capítulo renombrado.',
                function () {
                    return 'chapter-' + chapterId;
                }
            );
        });
    });

    /* ---------- Video principal: se guarda en cuanto termina la subida ---------- */
    section.querySelectorAll('[data-main-video]').forEach(function (field) {
        const status = field.querySelector('[data-upload-status]');
        field.addEventListener('upload:done', function (event) {
            const chapterId = field.dataset.mainVideo;
            status.textContent = 'Guardando el video…';
            send('POST', 'api/instructor/chapters/' + chapterId + '/contents', {
                tipo: 'video_principal',
                archivo: event.detail,
            })
                .then(function (result) {
                    if (result.ok) {
                        reloadAt('chapter-' + chapterId, 'Video principal guardado.');
                        return;
                    }
                    status.textContent = errorMessage(result);
                })
                .catch(function () {
                    status.textContent = ui.NETWORK_ERROR;
                });
        });
    });

    /* ---------- Recursos del capítulo ---------- */
    section.querySelectorAll('[data-resource-form]').forEach(function (form) {
        const type = form.querySelector('[data-resource-type]');
        const field = form.querySelector('[data-upload]');
        const input = field.querySelector('input[type="file"]');
        const hint = field.querySelector('.form-hint');
        const titleOptional = form.querySelector('[data-title-optional]');

        function syncType() {
            const option = type.options[type.selectedIndex];
            const isFile = Boolean(option.dataset.purpose);
            form.querySelectorAll('[data-resource-section]').forEach(function (part) {
                const name = part.dataset.resourceSection;
                part.hidden = isFile ? name !== 'file' : name !== type.value;
            });
            titleOptional.hidden = !isFile;
            if (isFile && field.dataset.purpose !== option.dataset.purpose) {
                field.dataset.purpose = option.dataset.purpose;
                input.accept = option.dataset.accept;
                hint.textContent = option.dataset.hint;
                field.cursologyUpload = null;
                delete field.dataset.state;
                field.querySelector('[data-upload-status]').textContent = '';
                field.querySelector('[data-upload-progress]').hidden = true;
            }
        }

        type.addEventListener('change', syncType);
        syncType();

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const option = type.options[type.selectedIndex];
            const body = { tipo: type.value, titulo: form.elements.titulo.value };
            if (option.dataset.purpose) {
                body.archivo = media.result(field);
                if (!body.archivo && !media.isUploading(form)) {
                    ui.clearErrors(form);
                    ui.showError(form, 'archivo', 'Elige el archivo y espera a que se suba.');
                    return;
                }
            } else if (type.value === 'enlace') {
                body.url = form.elements.url.value;
            } else {
                body.texto = form.elements.texto.value;
            }

            submitForm(
                form,
                function () {
                    return send('POST', 'api/instructor/chapters/' + form.dataset.chapterId + '/contents', body);
                },
                'Recurso agregado.',
                function () {
                    return 'chapter-' + form.dataset.chapterId;
                }
            );
        });
    });

    /* ---------- Eliminar niveles, capítulos y contenido ---------- */
    const DELETES = {
        level: {
            url: 'api/instructor/levels/',
            confirm: '¿Eliminar el nivel "%s"? Solo se puede si ya no tiene capítulos.',
            done: 'Nivel eliminado.',
        },
        chapter: {
            url: 'api/instructor/chapters/',
            confirm: '¿Eliminar el capítulo "%s" con su video y sus recursos? No se puede deshacer.',
            done: 'Capítulo eliminado.',
        },
        content: {
            url: 'api/instructor/contents/',
            confirm: '¿Quitar "%s" del capítulo? El archivo se borra de forma permanente.',
            done: 'Contenido eliminado.',
        },
    };

    section.querySelectorAll('[data-delete]').forEach(function (button) {
        button.addEventListener('click', function () {
            const kind = DELETES[button.dataset.delete];
            if (!window.confirm(kind.confirm.replace('%s', button.dataset.title))) {
                return;
            }
            button.disabled = true;
            ui.hideAlert(pageAlert);
            send('DELETE', kind.url + button.dataset.id)
                .then(function (result) {
                    if (result.ok) {
                        reloadAt(button.dataset.anchor || 'course-levels', kind.done);
                        return;
                    }
                    button.disabled = false;
                    ui.showAlert(pageAlert, errorMessage(result));
                    pageAlert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                })
                .catch(function () {
                    button.disabled = false;
                    ui.showAlert(pageAlert, ui.NETWORK_ERROR);
                });
        });
    });
})();
