(function () {
    'use strict';

    const THEME_KEY = 'cursology-theme';
    const root = document.documentElement;

    /* ---------- Cliente de la API (fetch con promesas) ---------- */
    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    // Resuelve con { ok, status, body } para cualquier respuesta HTTP (incluidos 4xx/5xx)
    // y solo rechaza si no hubo respuesta (red caída, servidor apagado).
    function api(url, options) {
        const settings = Object.assign({ method: 'GET' }, options || {});
        const headers = new Headers(settings.headers || {});
        headers.set('Accept', 'application/json');
        if (settings.method !== 'GET') {
            headers.set('X-CSRF-Token', csrfToken());
        }
        settings.headers = headers;
        settings.credentials = 'same-origin';

        return fetch(url, settings).then(function (response) {
            if (response.status === 204) {
                return { ok: response.ok, status: response.status, body: null };
            }
            return response.json()
                .catch(function () { return null; })
                .then(function (body) {
                    return { ok: response.ok, status: response.status, body: body };
                });
        });
    }

    /* ---------- Formularios: errores por campo, alertas y estado de envío ---------- */

    // form.elements[name] es un RadioNodeList para radios o casillas con el mismo nombre;
    // en ese caso el error se muestra en el fieldset que los agrupa.
    function fieldAnchor(form, name) {
        const field = form.elements[name];
        if (!field) {
            return null;
        }
        const isGroup = !field.tagName && typeof field.length === 'number';
        return isGroup ? (field[0] ? field[0].closest('fieldset') : null) : field;
    }

    function showError(form, name, message) {
        const anchor = fieldAnchor(form, name);
        const wrapper = anchor ? anchor.closest('.form-field') : null;
        if (!wrapper) {
            return false;
        }
        wrapper.classList.toggle('has-error', Boolean(message));
        const error = wrapper.querySelector('.field-error');
        if (error) {
            error.textContent = message || '';
        }
        return true;
    }

    function clearErrors(form) {
        form.querySelectorAll('.form-field.has-error').forEach(function (wrapper) {
            wrapper.classList.remove('has-error');
            const error = wrapper.querySelector('.field-error');
            if (error) {
                error.textContent = '';
            }
        });
    }

    function showAlert(alert, message, kind) {
        if (!alert) {
            return;
        }
        alert.textContent = message;
        alert.classList.toggle('is-success', kind === 'success');
        alert.hidden = false;
    }

    function hideAlert(alert) {
        if (alert) {
            alert.hidden = true;
            alert.textContent = '';
        }
    }

    function setBusy(form, busy, busyLabel) {
        form.setAttribute('aria-busy', String(busy));
        form.querySelectorAll('[type="submit"]').forEach(function (button) {
            if (!button.dataset.label) {
                button.dataset.label = button.textContent;
            }
            button.disabled = busy;
            button.textContent = busy ? (busyLabel || 'Guardando…') : button.dataset.label;
        });
    }

    // Muestra un error de la API: mensajes por campo y el mensaje general en la alerta.
    function showApiError(form, alert, result) {
        const error = result && result.body && result.body.error ? result.body.error : null;
        if (error && error.fields) {
            Object.keys(error.fields).forEach(function (name) {
                showError(form, name, error.fields[name]);
            });
        }
        showAlert(alert, error && error.message ? error.message : 'Ocurrió un error inesperado. Inténtalo de nuevo.');
    }

    const NETWORK_ERROR = 'No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.';

    /* ---------- Mensaje que sobrevive a una recarga (p. ej. "Categoría creada") ---------- */
    const FLASH_KEY = 'cursology-flash';

    function goWithMessage(url, message) {
        try {
            sessionStorage.setItem(FLASH_KEY, message);
        } catch (e) {
            /* sin sessionStorage solo se pierde el aviso */
        }
        if (url === null) {
            window.location.reload();
        } else {
            window.location.assign(url);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        let flash = null;
        try {
            flash = sessionStorage.getItem(FLASH_KEY);
            sessionStorage.removeItem(FLASH_KEY);
        } catch (e) {
            flash = null;
        }
        const target = document.querySelector('[data-page-alert]');
        if (flash && target) {
            showAlert(target, flash, 'success');
        }
    });

    window.Cursology = {
        api: api,
        goWithMessage: goWithMessage,
        form: {
            showError: showError,
            clearErrors: clearErrors,
            showAlert: showAlert,
            hideAlert: hideAlert,
            setBusy: setBusy,
            showApiError: showApiError,
            NETWORK_ERROR: NETWORK_ERROR,
        },
    };

    /* ---------- Pestañas genéricas: [data-tabs] con role="tab" y aria-controls ---------- */
    document.querySelectorAll('[data-tabs]').forEach(function (group) {
        const tabs = Array.prototype.slice.call(group.querySelectorAll('[role="tab"]'));
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (other) {
                    const selected = other === tab;
                    other.setAttribute('aria-selected', String(selected));
                    other.tabIndex = selected ? 0 : -1;
                    const panel = document.getElementById(other.getAttribute('aria-controls'));
                    if (panel) {
                        panel.hidden = !selected;
                    }
                });
            });
        });
    });

    /* ---------- Cerrar sesión ---------- */
    document.querySelectorAll('[data-logout]').forEach(function (button) {
        button.addEventListener('click', function () {
            button.disabled = true;
            api('api/auth/logout', { method: 'POST' })
                .catch(function () { return null; })
                .then(function () {
                    window.location.assign('?view=home');
                });
        });
    });

    /* ---------- Tema (light / dark) ---------- */
    const themeToggle = document.querySelector('[data-theme-toggle]');
    const themeLabel = document.querySelector('[data-theme-label]');

    function applyTheme(theme) {
        root.dataset.theme = theme;
        if (themeToggle) {
            themeToggle.setAttribute('aria-checked', String(theme === 'dark'));
        }
        if (themeLabel) {
            themeLabel.textContent = theme === 'dark' ? 'Oscuro' : 'Claro';
        }
    }

    applyTheme(root.dataset.theme === 'dark' ? 'dark' : 'light');

    if (themeToggle) {
        themeToggle.addEventListener('click', function () {
            const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
            applyTheme(next);
            try {
                localStorage.setItem(THEME_KEY, next);
            } catch (e) {
                /* localStorage no disponible (modo privado): el tema dura solo esta página. */
            }
        });
    }

    /* ---------- Sidebar en pantallas chicas ---------- */
    const sidebar = document.getElementById('app-sidebar');
    const backdrop = document.querySelector('[data-sidebar-backdrop]');
    const openers = document.querySelectorAll('[data-sidebar-open]');
    const closers = document.querySelectorAll('[data-sidebar-close]');
    const desktop = window.matchMedia('(min-width: 1024px)');

    if (!sidebar) {
        return;
    }

    function setSidebar(open) {
        sidebar.classList.toggle('is-open', open);
        if (backdrop) {
            backdrop.classList.toggle('is-visible', open);
        }
        document.body.classList.toggle('sidebar-open', open);
        openers.forEach(function (button) {
            button.setAttribute('aria-expanded', String(open));
        });
    }

    openers.forEach(function (button) {
        button.addEventListener('click', function () {
            setSidebar(true);
            const firstLink = sidebar.querySelector('.sidebar-link');
            if (firstLink) {
                firstLink.focus();
            }
        });
    });

    closers.forEach(function (element) {
        element.addEventListener('click', function () {
            setSidebar(false);
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && sidebar.classList.contains('is-open')) {
            setSidebar(false);
            if (openers[0]) {
                openers[0].focus();
            }
        }
    });

    desktop.addEventListener('change', function (event) {
        if (event.matches) {
            setSidebar(false);
        }
    });
})();
