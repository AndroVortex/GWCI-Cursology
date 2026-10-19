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

    /* ---------- Reglas de validación ---------- */
    const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

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

    const validators = {
        'login-form': {
            identity: required('Ingresa tu usuario o email.'),
            password: function (field) {
                return field.value ? '' : 'Ingresa tu contraseña.';
            },
        },
        'signup-form': {
            foto: function (field) {
                const file = field.files[0];
                if (!file) {
                    return '';
                }
                return file.type.startsWith('image/') ? '' : 'El archivo debe ser una imagen.';
            },
            nombre: required('Ingresa tu nombre.'),
            apellido_paterno: required('Ingresa tu apellido paterno.'),
            apellido_materno: required('Ingresa tu apellido materno.'),
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
            email: function (field) {
                const value = field.value.trim();
                if (!value) {
                    return 'Ingresa tu email.';
                }
                return EMAIL_PATTERN.test(value) ? '' : 'Ingresa un email válido.';
            },
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

    function showError(field, message) {
        const error = document.getElementById(field.id + '-error');
        const wrapper = field.closest('.form-field');
        field.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (wrapper) {
            wrapper.classList.toggle('has-error', Boolean(message));
        }
        if (error) {
            error.textContent = message;
        }
    }

    function validateField(rules, field) {
        const rule = rules[field.name];
        if (!rule) {
            return true;
        }
        const message = rule(field);
        showError(field, message);
        return !message;
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
                validateField(rules, field);
            }
        });

        form.addEventListener('change', function (event) {
            validateField(rules, event.target);
        });

        form.addEventListener('focusout', function (event) {
            if (event.target.dataset.touched === 'true') {
                validateField(rules, event.target);
            }
        });

        form.addEventListener('submit', function (event) {
            let firstInvalid = null;
            Object.keys(rules).forEach(function (name) {
                const field = form.elements[name];
                if (field && !validateField(rules, field) && !firstInvalid) {
                    firstInvalid = field;
                }
            });
            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
            }
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
            showError(photoInput, '');
            photoInput.focus();
        });
    }
})();
