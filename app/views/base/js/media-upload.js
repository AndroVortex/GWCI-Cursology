(function () {
    'use strict';

    const api = window.Cursology.api;
    const MB = 1024 * 1024;

    function apiError(result, fallback) {
        const error = result && result.body && result.body.error ? result.body.error : null;
        return error && error.message ? error.message : fallback;
    }

    function requestTicket(purpose) {
        return api('api/media/upload-ticket', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ purpose: purpose }),
        }).then(function (result) {
            if (!result.ok) {
                throw new Error(apiError(result, 'No se pudo preparar la subida.'));
            }
            return result.body.data;
        });
    }

    /**
     * Sube un archivo directo a Cloudinary con un ticket firmado por el servidor.
     * Resuelve con lo que el servidor necesita para verificar la subida.
     */
    function upload(file, purpose, onProgress) {
        return requestTicket(purpose).then(function (ticket) {
            const extension = (file.name.split('.').pop() || '').toLowerCase();
            if (ticket.formats.indexOf(extension) === -1) {
                throw new Error('Formato no permitido. Usa: ' + ticket.formats.join(', ') + '.');
            }
            if (file.size > ticket.max_bytes) {
                throw new Error('El archivo excede ' + Math.round(ticket.max_bytes / MB) + ' MB.');
            }

            return new Promise(function (resolve, reject) {
                const data = new FormData();
                Object.keys(ticket.fields).forEach(function (name) {
                    data.append(name, ticket.fields[name]);
                });
                data.append('file', file);

                const xhr = new XMLHttpRequest();
                xhr.open('POST', ticket.upload_url);
                xhr.upload.addEventListener('progress', function (event) {
                    if (event.lengthComputable && onProgress) {
                        onProgress(event.loaded / event.total);
                    }
                });
                xhr.addEventListener('load', function () {
                    let body = null;
                    try {
                        body = JSON.parse(xhr.responseText);
                    } catch (e) {
                        body = null;
                    }
                    if (xhr.status >= 200 && xhr.status < 300 && body && body.public_id) {
                        resolve({
                            public_id: body.public_id,
                            version: String(body.version),
                            signature: body.signature,
                            original_filename: file.name,
                        });
                        return;
                    }
                    const detail = body && body.error && body.error.message ? ': ' + body.error.message : '.';
                    reject(new Error('Cloudinary rechazó el archivo' + detail));
                });
                xhr.addEventListener('error', function () {
                    reject(new Error('No se pudo subir el archivo. Revisa tu conexión.'));
                });
                xhr.send(data);
            });
        });
    }

    function showPreview(preview, file) {
        if (!preview) {
            return;
        }
        preview.textContent = '';
        if (file.type.indexOf('image/') === 0 || file.type.indexOf('video/') === 0) {
            const media = document.createElement(file.type.indexOf('image/') === 0 ? 'img' : 'video');
            media.src = URL.createObjectURL(file);
            if (media.tagName === 'VIDEO') {
                media.muted = true;
            }
            media.alt = '';
            preview.append(media);
        } else {
            preview.textContent = (file.name.split('.').pop() || '').toUpperCase();
        }
    }

    /**
     * Campo de subida: [data-upload][data-purpose] con un input file, [data-upload-preview],
     * [data-upload-progress] y [data-upload-status]. El resultado queda en field.cursologyUpload.
     */
    function bind(field) {
        const input = field.querySelector('input[type="file"]');
        const preview = field.querySelector('[data-upload-preview]');
        const progress = field.querySelector('[data-upload-progress]');
        const bar = progress ? progress.querySelector('span') : null;
        const status = field.querySelector('[data-upload-status]');
        if (!input) {
            return;
        }

        input.addEventListener('change', function () {
            const file = input.files[0];
            if (!file) {
                return;
            }
            field.cursologyUpload = null;
            field.dataset.state = 'uploading';
            if (progress) {
                progress.hidden = false;
                bar.style.width = '0%';
            }
            if (status) {
                status.textContent = 'Subiendo ' + file.name + '…';
            }

            upload(file, field.dataset.purpose, function (ratio) {
                if (bar) {
                    bar.style.width = Math.round(ratio * 100) + '%';
                }
            })
                .then(function (result) {
                    field.cursologyUpload = result;
                    field.dataset.state = 'done';
                    if (status) {
                        status.textContent = file.name + ' · listo';
                    }
                    showPreview(preview, file);
                    field.dispatchEvent(new CustomEvent('upload:done', { detail: result }));
                })
                .catch(function (error) {
                    field.dataset.state = 'error';
                    if (progress) {
                        progress.hidden = true;
                    }
                    if (status) {
                        status.textContent = error.message;
                    }
                })
                .then(function () {
                    input.value = '';
                });
        });
    }

    document.querySelectorAll('[data-upload]').forEach(bind);

    window.Cursology.media = {
        upload: upload,
        bind: bind,
        result: function (field) {
            return field && field.cursologyUpload ? field.cursologyUpload : null;
        },
        isUploading: function (container) {
            return Boolean(container && container.querySelector('[data-upload][data-state="uploading"]'));
        },
    };
})();
