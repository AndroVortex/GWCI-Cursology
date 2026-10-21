(function () {
    'use strict';

    /* ---------- Pestañas Login / Sign-up ---------- */
    const tabs = Array.from(document.querySelectorAll('[role="tab"][data-auth-tab]'));
    const panels = document.querySelectorAll('[data-auth-panel]');

    function showPanel(name) {
        tabs.forEach(function (tab) {
            const selected = tab.dataset.authTab === name;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
        });
        panels.forEach(function (panel) {
            panel.hidden = panel.dataset.authPanel !== name;
        });
    }

    function panelFromHash() {
        return window.location.hash === '#signup' ? 'signup' : 'login';
    }

    document.querySelectorAll('[data-auth-tab]').forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            const name = trigger.dataset.authTab;
            showPanel(name);
            const url = window.location.pathname + window.location.search + (name === 'signup' ? '#signup' : '');
            history.replaceState(null, '', url);

            if (trigger.getAttribute('role') !== 'tab') {
                const tab = tabs.find(function (item) { return item.dataset.authTab === name; });
                if (tab) {
                    tab.focus();
                }
            }
        });
    });

    tabs.forEach(function (tab, index) {
        tab.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
                return;
            }
            const step = event.key === 'ArrowRight' ? 1 : -1;
            const next = tabs[(index + step + tabs.length) % tabs.length];
            next.click();
            next.focus();
        });
    });

    window.addEventListener('hashchange', function () {
        showPanel(panelFromHash());
    });

    showPanel(panelFromHash());

    /* ---------- Mostrar / ocultar contraseña ---------- */
    document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.getElementById(button.getAttribute('aria-controls'));
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(show));
            button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });

    /* ---------- Reglas de validación (las mismas que valida el servidor) ---------- */
    const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
    const MAX_NAME_CHARS = 100;

    const PASSWORD_RULES = {
        length: function (value) { return value.length >= 8; },
        upper: function (value) { return /\p{Lu}/u.test(value); },
        number: function (value) { return /\d/.test(value); },
        special: function (value) { return /[^\p{L}\p{N}\s]/u.test(value); },
    };

    function required(message) {
        return function (field) {
            return field.value.trim() ? '' : message;
        };
    }

    function today() {
        const now = new Date();
        return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    }

    function toISODate(date) {
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return date.getFullYear() + '-' + month + '-' + day;
    }

    function email(field) {
        const value = field.value.trim();
        if (!value) {
            return 'Ingresa tu email.';
        }
        return EMAIL_PATTERN.test(value) ? '' : 'Ingresa un email válido.';
    }

    function maxLength(field) {
        return field.value.trim().length > MAX_NAME_CHARS ? 'No puede exceder ' + MAX_NAME_CHARS + ' caracteres.' : '';
    }

    const validators = {
        'login-form': {
            email: email,
            password: function (field) {
                return field.value ? '' : 'Ingresa tu contraseña.';
            },
        },
        'signup-form': {
            rol: required('Elige si quieres aprender o enseñar.'),
            foto: function (field) {
                const file = field.files[0];
                if (!file) {
                    return '';
                }
                if (PHOTO_TYPES.indexOf(file.type) === -1) {
                    return 'La foto debe ser una imagen JPG, PNG o WEBP.';
                }
                return file.size > MAX_PHOTO_BYTES ? 'La foto no debe exceder 5 MB.' : '';
            },
            nombre: required('Ingresa tu nombre.'),
            apellido_paterno: required('Ingresa tu apellido paterno.'),
            apellido_materno: maxLength,
            genero: required('Selecciona tu género.'),
            fecha_nacimiento: function (field) {
                if (!field.value) {
                    return 'Ingresa tu fecha de nacimiento.';
                }
                const date = new Date(field.value + 'T00:00:00');
                if (Number.isNaN(date.getTime())) {
                    return 'Ingresa una fecha válida.';
                }
                return date > today() ? 'La fecha no puede ser futura.' : '';
            },
            email: email,
            password: function (field) {
                if (!field.value) {
                    return 'Crea una contraseña.';
                }
                const valid = Object.keys(PASSWORD_RULES).every(function (rule) {
                    return PASSWORD_RULES[rule](field.value);
                });
                return valid ? '' : 'La contraseña no cumple con todos los requisitos.';
            },
        },
    };

    /* ---------- Errores por campo (también los que devuelve la API) ---------- */

    // form.elements[name] es un RadioNodeList para un grupo de radios (rol): en ese
    // caso el mensaje va en el fieldset que los agrupa.
    function isGroup(field) {
        return !field.tagName && typeof field.length === 'number';
    }

    function showError(form, name, message) {
        const field = form.elements[name];
        if (!field) {
            return;
        }
        const anchor = isGroup(field) ? field[0] && field[0].closest('fieldset') : field;
        if (!anchor) {
            return;
        }
        const inputs = isGroup(field) ? Array.prototype.slice.call(field) : [field];
        inputs.forEach(function (input) {
            input.setAttribute('aria-invalid', message ? 'true' : 'false');
        });
        const wrapper = anchor.closest('.form-field');
        if (wrapper) {
            wrapper.classList.toggle('has-error', Boolean(message));
        }
        const error = document.getElementById(anchor.id + '-error');
        if (error) {
            error.textContent = message || '';
        }
    }

    function focusField(form, name) {
        const field = form.elements[name];
        const target = isGroup(field) ? field[0] : field;
        if (target) {
            target.focus();
        }
    }

    function validateField(form, rules, name) {
        const rule = rules[name];
        if (!rule || !form.elements[name]) {
            return true;
        }
        const message = rule(form.elements[name]);
        showError(form, name, message);
        return !message;
    }

    /* ---------- Alertas generales y estado de envío ---------- */
    function showAlert(form, message) {
        const alert = form.querySelector('[data-form-alert]');
        if (alert) {
            alert.textContent = message;
            alert.hidden = false;
        }
    }

    function hideAlert(form) {
        const alert = form.querySelector('[data-form-alert]');
        if (alert) {
            alert.hidden = true;
            alert.textContent = '';
        }
    }

    function setBusy(form, busy) {
        const button = form.querySelector('[type="submit"]');
        form.setAttribute('aria-busy', String(busy));
        if (!button) {
            return;
        }
        if (!button.dataset.label) {
            button.dataset.label = button.textContent;
        }
        button.disabled = busy;
        button.textContent = busy ? 'Enviando…' : button.dataset.label;
    }

    /* ---------- Envío con fetch ---------- */
    function requestOptions(form) {
        if (form.id === 'login-form') {
            return {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: form.elements.email.value.trim(),
                    password: form.elements.password.value,
                }),
            };
        }
        // multipart/form-data: el navegador pone el Content-Type con su boundary.
        return { method: 'POST', body: new FormData(form) };
    }

    function submitForm(form) {
        hideAlert(form);
        setBusy(form, true);

        window.Cursology.api(form.getAttribute('action'), requestOptions(form))
            .then(function (result) {
                if (result.ok) {
                    window.location.assign('?view=home');
                    return;
                }
                const error = result.body && result.body.error ? result.body.error : null;
                if (error && error.fields) {
                    Object.keys(error.fields).forEach(function (name) {
                        showError(form, name, error.fields[name]);
                    });
                }
                showAlert(form, error && error.message ? error.message : 'Ocurrió un error inesperado. Inténtalo de nuevo.');
                setBusy(form, false);
            })
            .catch(function () {
                showAlert(form, 'No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.');
                setBusy(form, false);
            });
    }

    Object.keys(validators).forEach(function (formId) {
        const form = document.getElementById(formId);
        const rules = validators[formId];
        if (!form) {
            return;
        }

        form.addEventListener('input', function (event) {
            const field = event.target;
            field.dataset.touched = 'true';
            if (field.getAttribute('aria-invalid') === 'true') {
                validateField(form, rules, field.name);
            }
        });

        form.addEventListener('change', function (event) {
            validateField(form, rules, event.target.name);
        });

        form.addEventListener('focusout', function (event) {
            if (event.target.dataset.touched === 'true') {
                validateField(form, rules, event.target.name);
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            let firstInvalid = null;
            Object.keys(rules).forEach(function (name) {
                if (!validateField(form, rules, name) && firstInvalid === null) {
                    firstInvalid = name;
                }
            });
            if (firstInvalid !== null) {
                focusField(form, firstInvalid);
                return;
            }
            submitForm(form);
        });
    });

    /* ---------- Checklist de la contraseña (sign-up) ---------- */
    const signupPassword = document.getElementById('signup-password');
    const ruleItems = document.querySelectorAll('#signup-password-rules [data-rule]');

    if (signupPassword) {
        signupPassword.addEventListener('input', function () {
            ruleItems.forEach(function (item) {
                item.classList.toggle('is-valid', PASSWORD_RULES[item.dataset.rule](signupPassword.value));
            });
        });
    }

    /* ---------- Fecha de nacimiento: no permitir fechas futuras ---------- */
    const birthdate = document.getElementById('signup-fecha-nacimiento');
    if (birthdate) {
        birthdate.max = toISODate(today());
    }

    /* ---------- Vista previa de la foto de perfil ---------- */
    const photoInput = document.getElementById('signup-photo');
    const photoPreview = document.getElementById('signup-photo-preview');
    const photoRemove = document.getElementById('signup-photo-remove');
    let previewUrl = null;

    function resetPreview() {
        if (previewUrl) {
            URL.revokeObjectURL(previewUrl);
            previewUrl = null;
        }
        const img = photoPreview.querySelector('img');
        if (img) {
            img.remove();
        }
        photoPreview.classList.remove('has-image');
        photoRemove.hidden = true;
    }

    if (photoInput && photoPreview && photoRemove) {
        photoInput.addEventListener('change', function () {
            resetPreview();
            const file = photoInput.files[0];
            if (!file || !file.type.startsWith('image/')) {
                return;
            }
            previewUrl = URL.createObjectURL(file);
            const img = document.createElement('img');
            img.src = previewUrl;
            img.alt = 'Vista previa de la foto de perfil';
            photoPreview.append(img);
            photoPreview.classList.add('has-image');
            photoRemove.hidden = false;
        });

        photoRemove.addEventListener('click', function () {
            photoInput.value = '';
            resetPreview();
            showError(photoInput.form, 'foto', '');
            photoInput.focus();
        });
    }
})();
