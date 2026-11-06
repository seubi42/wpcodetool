/* Espace intervenant : « Dispo » / « Pas dispo » enregistré tout de suite (liste et calendrier). */
(function () {
    'use strict';

    var cfg = window.tomoDispos;

    if (!cfg) {
        return;
    }

    function paint(box, state) {
        box.setAttribute('data-dispo-state', state);
        box.querySelectorAll('.tomo-dispo-btn').forEach(function (btn) {
            var on = btn.getAttribute('data-answer') === state;
            btn.classList.toggle('is-on', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    document.addEventListener('click', function (event) {
        var btn = event.target.closest('.tomo-dispo-btn');
        var box = btn && btn.closest('[data-dispo-creneau]');

        if (!box || box.classList.contains('is-busy')) {
            return;
        }

        event.preventDefault();

        var before = box.getAttribute('data-dispo-state');
        var body = new URLSearchParams();
        body.append('action', cfg.action);
        body.append('nonce', cfg.nonce);
        body.append('creneau', box.getAttribute('data-dispo-creneau'));
        body.append('answer', btn.getAttribute('data-answer'));

        paint(box, btn.getAttribute('data-answer'));
        box.classList.add('is-busy');

        fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) {
                return response.json().catch(function () { return null; });
            })
            .then(function (json) {
                if (json && json.success) {
                    // La même date peut figurer dans plusieurs demandes : toutes ses copies suivent.
                    document.querySelectorAll('[data-dispo-creneau="' + box.getAttribute('data-dispo-creneau') + '"]').forEach(function (other) {
                        paint(other, json.data.state);
                    });
                    box.classList.add('is-saved');
                    document.querySelectorAll('.tomo-camp-request').forEach(function (request) {
                        var left = Array.prototype.filter.call(request.querySelectorAll('[data-dispo-creneau]'), function (item) {
                            return ['yes', 'no'].indexOf(item.getAttribute('data-dispo-state')) === -1;
                        });
                        var done = request.querySelector('.tomo-camp-done');

                        if (!left.length && !done && cfg.texts.done) {
                            done = document.createElement('p');
                            done.className = 'tomo-camp-done';
                            done.textContent = cfg.texts.done;
                            request.querySelector('h2').insertAdjacentElement('afterend', done);
                        }
                    });
                    setTimeout(function () { box.classList.remove('is-saved'); }, 1200);
                } else {
                    paint(box, before);
                    window.alert(json && json.data && json.data.message ? json.data.message : cfg.texts.error);
                }
            })
            .catch(function () {
                paint(box, before);
                window.alert(cfg.texts.network);
            })
            .then(function () {
                box.classList.remove('is-busy');
            });
    });

    // Message libre en réponse à une demande groupée.
    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-camp-reply]');

        if (!form) {
            return;
        }

        event.preventDefault();

        var button = form.querySelector('button[type=submit]');
        var state = form.querySelector('[data-camp-reply-state]');
        var body = new URLSearchParams();
        body.append('action', cfg.replyAction);
        body.append('nonce', cfg.replyNonce);
        body.append('campagne', form.getAttribute('data-camp-reply'));
        body.append('reply', form.querySelector('textarea').value);

        button.disabled = true;
        state.textContent = cfg.texts.sending;

        fetch(cfg.url, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) {
                return response.json().catch(function () { return null; });
            })
            .then(function (json) {
                state.textContent = json && json.data && json.data.message ? json.data.message : cfg.texts.error;
            })
            .catch(function () {
                state.textContent = cfg.texts.network;
            })
            .then(function () {
                button.disabled = false;
            });
    });
})();
