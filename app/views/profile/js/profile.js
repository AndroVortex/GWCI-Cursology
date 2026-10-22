(function () {
    'use strict';

    const api = window.Cursology.api;
    const F = window.Cursology.form;

    const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const MAX_PHOTO_BYTES = 5 * 1024 * 1024;
    const DEFAULT_AVATAR = 'img/avatar-default.svg';
    const PASSWORD_RULES = {
        length: function (value) { return value.length >= 8; },
        upper: function (value) { return /\p{Lu}/u.test(value); },
        number: function (value) { return /\d/.test(value); },
        special: function (value) { return /[^\p{L}\p{N}\s]/u.test(value); },
    };

    function today() {
        const now = new Date();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        return now.getFullYear() + '-' + month + '-' + day;
    }

    function setAvatar(url) {
        document.querySelectorAll('[data-avatar], .avatar img').forEach(function (img) {
            img.src = url;
        });
        const remove = document.querySelector('[data-avatar-remove]');
        if (remove) {
            remove.hidden = url === DEFAULT_AVATAR;
        }
    }

    /* ---------- Información personal ---------- */
    const infoForm = document.getElementById('profile-info-form');
    if (infoForm) {
        const infoAlert = infoForm.querySelector('[data-form-alert]');
        const emailInput = infoForm.elements.email;
        const passwordField = infoForm.querySelector('[data-email-password]');
        let originalEmail = emailInput.dataset.originalEmail;

        function emailChanged() {
            return emailInput.value.trim().toLowerCase() !== originalEmail;
        }

        function syncEmailPassword() {
            passwordField.hidden = !emailChanged();
            if (passwordField.hidden) {
                infoForm.elements.current_password.value = '';
            }
        }

        emailInput.addEventListener('input', syncEmailPassword);
        infoForm.elements.fecha_nacimiento.max = today();

        function validateInfo() {
            const values = {
                nombre: infoForm.elements.nombre.value.trim(),
                apellido_paterno: infoForm.elements.apellido_paterno.value.trim(),
                email: emailInput.value.trim(),
                fecha_nacimiento: infoForm.elements.fecha_nacimiento.value,
            };
            const errors = {};
            if (!values.nombre) {
                errors.nombre = 'Ingresa tu nombre.';
            }
            if (!values.apellido_paterno) {
                errors.apellido_paterno = 'Ingresa tu apellido paterno.';
            }
            if (!EMAIL_PATTERN.test(values.email)) {
                errors.email = values.email ? 'Ingresa un email válido.' : 'Ingresa tu email.';
            }
            if (!values.fecha_nacimiento) {
                errors.fecha_nacimiento = 'Ingresa tu fecha de nacimiento.';
            } else if (values.fecha_nacimiento > today()) {
                errors.fecha_nacimiento = 'La fecha no puede ser futura.';
            }
            if (emailChanged() && !infoForm.elements.current_password.value) {
                errors.current_password = 'Escribe tu contraseña actual para cambiar el email.';
            }
            return errors;
        }

        infoForm.addEventListener('submit', function (event) {
            event.preventDefault();
            F.clearErrors(infoForm);
            F.hideAlert(infoAlert);

            const errors = validateInfo();
            if (Object.keys(errors).length > 0) {
                Object.keys(errors).forEach(function (name) { F.showError(infoForm, name, errors[name]); });
                return;
            }

            const body = {
                nombre: infoForm.elements.nombre.value,
                apellido_paterno: infoForm.elements.apellido_paterno.value,
                apellido_materno: infoForm.elements.apellido_materno.value,
                genero: infoForm.elements.genero.value,
                fecha_nacimiento: infoForm.elements.fecha_nacimiento.value,
                email: emailInput.value,
                descripcion: infoForm.elements.descripcion.value,
                current_password: infoForm.elements.current_password.value,
            };

            F.setBusy(infoForm, true);
            api('api/users/me', { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
                .then(function (result) {
                    F.setBusy(infoForm, false);
                    if (!result.ok) {
                        F.showApiError(infoForm, infoAlert, result);
                        return;
                    }
                    const user = result.body.data.user;
                    document.querySelector('[data-profile-name]').textContent = user.fullName;
                    document.querySelector('[data-profile-email]').textContent = user.email;
                    originalEmail = user.email;
                    emailInput.value = user.email;
                    syncEmailPassword();
                    F.showAlert(infoAlert, 'Cambios guardados.', 'success');
                })
                .catch(function () {
                    F.setBusy(infoForm, false);
                    F.showAlert(infoAlert, F.NETWORK_ERROR);
                });
        });
    }

    /* ---------- Foto de perfil ---------- */
    const avatarInput = document.querySelector('[data-avatar-input]');
    const avatarAlert = document.querySelector('[data-avatar-alert]');
    const avatarRemove = document.querySelector('[data-avatar-remove]');
    const avatarHint = document.querySelector('[data-avatar-hint]');
    const avatarHintText = avatarHint ? avatarHint.textContent : '';

    function avatarRequest(options, busyText) {
        F.hideAlert(avatarAlert);
        if (avatarHint) {
            avatarHint.textContent = busyText;
        }
        return api('api/users/me/avatar', options)
            .then(function (result) {
                if (!result.ok) {
                    const error = result.body && result.body.error ? result.body.error : null;
                    const fieldMessage = error && error.fields && error.fields.foto ? error.fields.foto : null;
                    F.showAlert(avatarAlert, fieldMessage || (error ? error.message : 'No se pudo actualizar la foto.'));
                    return;
                }
                setAvatar(result.body.data.user.avatar);
                F.showAlert(avatarAlert, 'Foto actualizada.', 'success');
            })
            .catch(function () {
                F.showAlert(avatarAlert, F.NETWORK_ERROR);
            })
            .then(function () {
                if (avatarHint) {
                    avatarHint.textContent = avatarHintText;
                }
                avatarInput.value = '';
            });
    }

    if (avatarInput) {
        avatarInput.addEventListener('change', function () {
            const file = avatarInput.files[0];
            if (!file) {
                return;
            }
            if (PHOTO_TYPES.indexOf(file.type) === -1) {
                F.showAlert(avatarAlert, 'La foto debe ser una imagen JPG, PNG o WEBP.');
                avatarInput.value = '';
                return;
            }
            if (file.size > MAX_PHOTO_BYTES) {
                F.showAlert(avatarAlert, 'La foto no debe exceder 5 MB.');
                avatarInput.value = '';
                return;
            }
            const data = new FormData();
            data.append('foto', file);
            avatarRequest({ method: 'POST', body: data }, 'Subiendo foto…');
        });
    }

    if (avatarRemove) {
        avatarRemove.addEventListener('click', function () {
            avatarRequest({ method: 'DELETE' }, 'Quitando foto…');
        });
    }

    /* ---------- Contraseña ---------- */
    const passwordForm = document.getElementById('profile-password-form');
    if (passwordForm) {
        const passwordAlert = passwordForm.querySelector('[data-form-alert]');
        const newPassword = passwordForm.elements.new_password;
        const ruleItems = passwordForm.querySelectorAll('[data-password-rules] [data-rule]');

        function refreshRules() {
            ruleItems.forEach(function (item) {
                item.classList.toggle('is-valid', PASSWORD_RULES[item.dataset.rule](newPassword.value));
            });
        }

        newPassword.addEventListener('input', refreshRules);

        passwordForm.addEventListener('submit', function (event) {
            event.preventDefault();
            F.clearErrors(passwordForm);
            F.hideAlert(passwordAlert);

            const values = {
                current_password: passwordForm.elements.current_password.value,
                new_password: newPassword.value,
                confirm_password: passwordForm.elements.confirm_password.value,
            };
            const errors = {};
            if (!values.current_password) {
                errors.current_password = 'Escribe tu contraseña actual.';
            }
            const allRules = Object.keys(PASSWORD_RULES).every(function (rule) { return PASSWORD_RULES[rule](values.new_password); });
            if (!allRules) {
                errors.new_password = 'La contraseña no cumple con todos los requisitos.';
            }
            if (values.confirm_password !== values.new_password) {
                errors.confirm_password = 'Las contraseñas no coinciden.';
            }
            if (Object.keys(errors).length > 0) {
                Object.keys(errors).forEach(function (name) { F.showError(passwordForm, name, errors[name]); });
                return;
            }

            F.setBusy(passwordForm, true);
            api('api/users/me/password', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(values) })
                .then(function (result) {
                    F.setBusy(passwordForm, false);
                    if (!result.ok) {
                        F.showApiError(passwordForm, passwordAlert, result);
                        return;
                    }
                    passwordForm.reset();
                    refreshRules();
                    F.showAlert(passwordAlert, 'Contraseña actualizada.', 'success');
                })
                .catch(function () {
                    F.setBusy(passwordForm, false);
                    F.showAlert(passwordAlert, F.NETWORK_ERROR);
                });
        });
    }
})();
