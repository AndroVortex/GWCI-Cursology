(function () {
    'use strict';

    const api = window.Cursology.api;
    const ui = window.Cursology.form;
    const SAVE_EVERY_MS = 15000;

    /* ---------- Índice: estado de un capítulo y avance del curso ---------- */
    function markChapterDone(chapterId, label) {
        const state = document.querySelector('[data-chapter-link="' + chapterId + '"] [data-chapter-state]');
        if (!state || state.dataset.state === 'done') {
            return;
        }
        state.dataset.state = 'done';
        state.title = label;
        state.querySelector('.visually-hidden').textContent = label;

        const bar = document.querySelector('[data-course-progress]');
        if (bar) {
            const done = Number(bar.dataset.done) + 1;
            const total = Number(bar.dataset.total) || 1;
            const percent = Math.floor((100 * done) / total);
            bar.dataset.done = String(done);
            bar.setAttribute('aria-valuenow', String(percent));
            bar.querySelector('span').style.width = percent + '%';
            document.querySelector('[data-course-progress-label]').textContent = done + ' de ' + total + ' capítulos · ' + percent + '%';
        }
    }

    function announceCompletion(progress) {
        if (progress.courseCompleted) {
            const notice = document.querySelector('[data-course-completed]');
            if (notice) {
                notice.hidden = false;
            }
        } else if (progress.levelCompleted) {
            ui.showAlert(document.querySelector('[data-page-alert]'), '¡Terminaste este nivel!', 'success');
        }
    }

    window.Cursology.learning = { markChapterDone: markChapterDone, announceCompletion: announceCompletion };

    /* ---------- Reproductor de video ---------- */
    const player = document.querySelector('[data-player]');
    if (!player) {
        return;
    }

    const chapterId = player.dataset.chapterId;
    const video = player.querySelector('[data-video]');
    const alert = player.querySelector('[data-player-alert]');
    const markButton = player.querySelector('[data-mark-done]');
    const badge = player.querySelector('[data-watched-badge]');
    const storedPosition = Number(player.dataset.position) || 0;
    let completed = player.dataset.completed === '1';
    let resumed = !video;
    let lastSent = null;

    // Hasta que el video retoma la posición guardada, se reporta esa posición (no el 0 inicial).
    function position() {
        return resumed && video ? Math.floor(video.currentTime || 0) : storedPosition;
    }

    function save(markDone, keepalive) {
        const current = position();
        if (!markDone && current === lastSent) {
            return Promise.resolve(null);
        }
        lastSent = current;

        return api('api/student/chapters/' + chapterId + '/progress', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ posicion: current, terminado: Boolean(markDone) }),
            keepalive: Boolean(keepalive),
        })
            .then(function (result) {
                if (result.ok) {
                    apply(result.body.data.progress);
                } else if (markDone) {
                    const error = result.body && result.body.error ? result.body.error.message : null;
                    ui.showAlert(alert, error || 'No se pudo guardar tu avance.');
                }
                return result;
            })
            .catch(function () {
                if (markDone) {
                    ui.showAlert(alert, ui.NETWORK_ERROR);
                }
                return null;
            });
    }

    function apply(progress) {
        if (!progress.completed || completed) {
            return;
        }
        completed = true;
        badge.hidden = false;
        markButton.hidden = true;
        markChapterDone(chapterId, 'Terminado');
        announceCompletion(progress);
    }

    markButton.addEventListener('click', function () {
        markButton.disabled = true;
        ui.hideAlert(alert);
        save(true).then(function () {
            markButton.disabled = false;
        });
    });

    if (video) {
        video.addEventListener('loadedmetadata', function () {
            if (storedPosition > 0 && (!video.duration || storedPosition < video.duration - 5)) {
                video.currentTime = storedPosition;
            }
            resumed = true;
        }, { once: true });
        video.addEventListener('play', function () {
            resumed = true;
        });
        video.addEventListener('pause', function () {
            save(false);
        });
        video.addEventListener('ended', function () {
            save(false);
        });
        window.setInterval(function () {
            if (!video.paused && !video.ended) {
                save(false);
            }
        }, SAVE_EVERY_MS);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                save(false, true);
            }
        });
    }

    // Registra la visita (último acceso y "En curso") sin mover la posición guardada.
    save(false);
})();
