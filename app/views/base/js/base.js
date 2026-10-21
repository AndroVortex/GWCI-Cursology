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

    window.Cursology = { api: api };

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
