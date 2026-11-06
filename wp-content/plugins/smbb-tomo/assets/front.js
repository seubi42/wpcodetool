/**
 * Tomo – fiche produit d'un atelier.
 * - le choix de la date borne le nombre de places ;
 * - une carte par participant (pas de quantité à saisir) : « C'est moi », « Ajouter un participant »,
 *   « Ajouter mes participants favoris » (fenêtre de choix) et « Mémoriser ce participant ».
 */
(function () {
    'use strict';

    var cfg = window.tomoParticipants || { me: null, favorites: [], max: 10, ajax: null, texts: {} };
    var FIELDS = ['first_name', 'last_name', 'email', 'phone', 'level', 'age', 'allergies'];

    function t(key, value) {
        var text = cfg.texts && cfg.texts[key] ? cfg.texts[key] : key;

        return value === undefined ? text : text.replace(/%d|%s/, String(value));
    }

    function esc(value) {
        return String(value || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function nameKey(row) {
        return ((row.first_name || '') + ' ' + (row.last_name || '')).trim().replace(/\s+/g, ' ').toLowerCase()
            .normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function init(form) {
        var dates = form.querySelector('[data-tomo-dates]');
        var box = form.querySelector('[data-tomo-participants]');
        var list = form.querySelector('[data-tomo-list]');
        var template = form.querySelector('template[data-tomo-participant-template]');
        var qty = form.querySelector('input.qty, input[name="quantity"]');
        var addBtn = form.querySelector('[data-tomo-add]');
        var favBtn = form.querySelector('[data-tomo-fav-open]');
        var warning = form.querySelector('[data-tomo-seats-warning]');
        var modal = form.querySelector('[data-tomo-fav-modal]');

        if (!dates || !box || !list || !template) {
            return;
        }

        form.classList.add('tomo-cart');

        function cards() {
            return Array.prototype.slice.call(list.querySelectorAll('[data-tomo-participant]'));
        }

        function seats() {
            var radio = dates.querySelector('input[name="tomo_creneau_id"]:checked');

            return Math.min(cfg.max || 10, radio ? parseInt(radio.getAttribute('data-seats'), 10) || 1 : 1);
        }

        function field(card, name) {
            return card.querySelector('[data-field="' + name + '"]');
        }

        function values(card) {
            var out = {};

            FIELDS.forEach(function (name) {
                var input = field(card, name);
                out[name] = input ? input.value.trim() : '';
            });

            return out;
        }

        function isEmpty(card) {
            return !card.classList.contains('is-self') && FIELDS.every(function (name) {
                var input = field(card, name);

                return !input || input.tagName === 'SELECT' || input.value.trim() === '';
            });
        }

        function fill(card, data) {
            FIELDS.forEach(function (name) {
                var input = field(card, name);

                if (input && data[name] !== undefined) {
                    input.value = data[name] || '';
                }
            });
        }

        // Numérotation, noms des champs, boutons visibles, quantité WooCommerce et message de places.
        function refresh() {
            var all = cards();
            var self = all.filter(function (card) { return card.classList.contains('is-self'); })[0];
            var max = seats();

            all.forEach(function (card, i) {
                card.querySelectorAll('[name^="tomo_participants["]').forEach(function (input) {
                    input.name = input.name.replace(/^tomo_participants\[[^\]]*\]/, 'tomo_participants[' + i + ']');
                });
                card.querySelector('[data-tomo-no]').textContent = (i + 1 < 10 ? '0' : '') + (i + 1);
                card.querySelector('[data-tomo-title]').textContent = t('participant', i + 1);
                card.querySelector('[data-tomo-remove]').hidden = all.length === 1;

                var me = card.querySelector('[data-tomo-me]');

                if (me) {
                    me.hidden = !!self && self !== card;
                }

                var remember = card.querySelector('[data-tomo-remember]');

                if (remember) {
                    remember.hidden = card.classList.contains('is-self');
                }
            });

            if (qty) {
                qty.max = String(Math.max(all.length, max));
                qty.value = String(all.length);
            }

            if (addBtn) {
                addBtn.disabled = all.length >= max;
            }

            if (warning) {
                warning.hidden = all.length <= max;
                warning.textContent = all.length > max ? t('tooMany', max) : '';
            }

            if (favBtn) {
                favBtn.hidden = !(cfg.favorites || []).length;
                var count = favBtn.querySelector('[data-tomo-fav-count]');

                if (count) {
                    count.textContent = String((cfg.favorites || []).length);
                }
            }
        }

        function addCard(data, focus) {
            if (cards().length >= seats()) {
                window.alert(t('full'));

                return null;
            }

            var wrap = document.createElement('div');
            wrap.innerHTML = template.innerHTML.replace(/__i__/g, String(cards().length)).replace(/__n__/g, String(cards().length + 1)).trim();
            var card = wrap.firstChild;
            list.appendChild(card);

            if (data) {
                fill(card, data);
            }

            refresh();

            if (focus) {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                window.setTimeout(function () {
                    var first = field(card, 'first_name');

                    if (first) {
                        first.focus({ preventScroll: true });
                    }
                }, 300);
            }

            return card;
        }

        function setSelf(card, on) {
            card.classList.toggle('is-self', on);
            card.querySelector('[data-tomo-self]').value = on ? '1' : '0';

            var me = card.querySelector('[data-tomo-me]');
            me.classList.toggle('is-on', on);
            me.setAttribute('aria-pressed', on ? 'true' : 'false');

            ['first_name', 'last_name', 'email', 'phone'].forEach(function (name) {
                var input = field(card, name);

                if (!input) {
                    return;
                }

                var value = on && cfg.me ? (cfg.me[name] || '') : '';
                input.value = value;
                // Repris du compte : non modifiable, sauf s'il manque quelque chose.
                input.readOnly = on && value !== '';
            });

            var remember = card.querySelector('[data-tomo-remember] input');

            if (remember && on) {
                remember.checked = false;
            }

            if (on && cfg.me && (!cfg.me.first_name || !cfg.me.last_name)) {
                window.alert(t('meMissing'));
            }

            refresh();
        }

        // Favoris : remplit d'abord une carte vide, sinon en ajoute une.
        function addFavorite(row) {
            var empty = cards().filter(isEmpty)[0];

            if (empty) {
                fill(empty, row);
                refresh();

                return true;
            }

            return !!addCard(row, false);
        }

        list.addEventListener('click', function (event) {
            var card = event.target.closest('[data-tomo-participant]');

            if (!card) {
                return;
            }

            if (event.target.closest('[data-tomo-remove]')) {
                card.parentNode.removeChild(card);
                refresh();
            } else if (event.target.closest('[data-tomo-me]')) {
                setSelf(card, !card.classList.contains('is-self'));
            }
        });

        if (addBtn) {
            addBtn.addEventListener('click', function () {
                addCard(null, true);
            });
        }

        dates.addEventListener('change', refresh);

        // Fenêtre « Mes participants favoris ».
        if (modal && favBtn) {
            var search = modal.querySelector('[data-tomo-fav-search]');
            var pick = modal.querySelector('[data-tomo-fav-list]');
            var confirmBtn = modal.querySelector('[data-tomo-fav-add]');
            var picked = {};
            var deleting = '';

            var used = function () {
                var keys = {};

                cards().forEach(function (card) {
                    var key = nameKey(values(card));

                    if (key) {
                        keys[key] = true;
                    }
                });

                return keys;
            };

            var renderPick = function () {
                var rows = cfg.favorites || [];
                var q = (search.value || '').trim().toLowerCase();
                var inOrder = used();
                var shown = rows.filter(function (row) {
                    return !q || (row.first_name + ' ' + row.last_name + ' ' + (row.email || '')).toLowerCase().indexOf(q) !== -1;
                });
                var n = Object.keys(picked).length;

                if (!rows.length) {
                    pick.innerHTML = '<p class="tomo-fav-empty">' + esc(t('empty')) + '</p>';
                } else if (!shown.length) {
                    pick.innerHTML = '<p class="tomo-fav-empty">' + esc(t('noResult')) + '</p>';
                } else {
                    pick.innerHTML = shown.map(function (row) {
                        var key = row.key || nameKey(row);
                        var name = row.first_name + ' ' + row.last_name;

                        if (deleting === key) {
                            return '<div class="tomo-fav-row is-confirm"><span>' + esc(t('remove', name)) + '</span>'
                                + '<span class="tomo-fav-confirm"><button type="button" class="tomo-link" data-nodel>' + esc(t('cancel')) + '</button> '
                                + '<button type="button" class="tomo-fav-del-ok" data-delok="' + esc(key) + '">' + esc(t('delete')) + '</button></span></div>';
                        }

                        var isUsed = !!inOrder[nameKey(row)];
                        var on = !!picked[key];

                        return '<div class="tomo-fav-row' + (on ? ' is-on' : '') + (isUsed ? ' is-used' : '') + '" data-row="' + esc(key) + '" role="checkbox" tabindex="0" aria-checked="' + (on || isUsed ? 'true' : 'false') + '">'
                            + '<span class="tomo-fav-check" aria-hidden="true">' + (on || isUsed ? '✓' : '') + '</span>'
                            + '<span class="tomo-fav-avatar" aria-hidden="true">' + esc(row.initials || '') + '</span>'
                            + '<span class="tomo-fav-id"><strong>' + esc(name) + '</strong><small>' + esc(isUsed ? t('inOrder') : (row.email || row.phone || '')) + '</small></span>'
                            + (cfg.ajax ? '<button type="button" class="tomo-fav-trash" data-del="' + esc(key) + '" aria-label="' + esc(t('delete')) + '">×</button>' : '')
                            + '</div>';
                    }).join('');
                }

                confirmBtn.disabled = !n;
                confirmBtn.textContent = n ? t('addN', n) : t('add');
            };

            var open = function () {
                picked = {};
                deleting = '';
                search.value = '';
                renderPick();
                modal.hidden = false;
                document.body.classList.add('tomo-modal-open');
                window.setTimeout(function () { search.focus(); }, 50);
            };

            var close = function () {
                modal.hidden = true;
                document.body.classList.remove('tomo-modal-open');
                favBtn.focus();
            };

            var toggleRow = function (row) {
                if (!row || row.classList.contains('is-used')) {
                    return;
                }

                var key = row.getAttribute('data-row');

                if (picked[key]) {
                    delete picked[key];
                } else {
                    picked[key] = true;
                }

                renderPick();
            };

            favBtn.addEventListener('click', open);
            search.addEventListener('input', renderPick);

            modal.addEventListener('click', function (event) {
                if (event.target === modal || event.target.closest('[data-tomo-fav-close]')) {
                    close();

                    return;
                }

                var del = event.target.closest('[data-del]');

                if (del) {
                    event.stopPropagation();
                    deleting = del.getAttribute('data-del');
                    renderPick();

                    return;
                }

                if (event.target.closest('[data-nodel]')) {
                    deleting = '';
                    renderPick();

                    return;
                }

                var ok = event.target.closest('[data-delok]');

                if (ok && cfg.ajax) {
                    var key = ok.getAttribute('data-delok');
                    var body = new URLSearchParams();
                    body.append('action', cfg.ajax.action);
                    body.append('nonce', cfg.ajax.nonce);
                    body.append('key', key);
                    ok.disabled = true;

                    fetch(cfg.ajax.url, { method: 'POST', credentials: 'same-origin', body: body })
                        .then(function (response) { return response.json(); })
                        .then(function (json) {
                            if (json && json.success) {
                                cfg.favorites = json.data.favorites || [];
                                delete picked[key];
                            }
                        })
                        .catch(function () {})
                        .then(function () {
                            deleting = '';
                            refresh();
                            renderPick();

                            if (!(cfg.favorites || []).length) {
                                close();
                            }
                        });

                    return;
                }

                toggleRow(event.target.closest('[data-row]'));
            });

            // La fenêtre est dans le formulaire du panier : Entrée ne doit pas l'envoyer.
            modal.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    close();
                } else if (event.key === 'Enter' && event.target === search) {
                    event.preventDefault();
                } else if ((event.key === ' ' || event.key === 'Enter') && event.target.matches('[data-row]')) {
                    event.preventDefault();
                    toggleRow(event.target);
                }
            });

            confirmBtn.addEventListener('click', function () {
                var keys = Object.keys(picked);

                close();

                (cfg.favorites || []).forEach(function (row) {
                    if (keys.indexOf(row.key || nameKey(row)) !== -1) {
                        addFavorite(row);
                    }
                });
            });
        }

        // Carte « C'est moi » renvoyée par le serveur après une erreur : on garde les champs verrouillés.
        cards().forEach(function (card) {
            if (card.classList.contains('is-self')) {
                ['first_name', 'last_name', 'email', 'phone'].forEach(function (name) {
                    var input = field(card, name);

                    if (input && input.value !== '') {
                        input.readOnly = true;
                    }
                });
            }
        });

        refresh();
    }

    function ready() {
        var forms = document.querySelectorAll('form.cart');
        var waitlist = document.getElementById('tomo-waitlist');

        // Lien « Liste d'attente » d'un shortcode : on ouvre le formulaire.
        if (waitlist && window.location.hash === '#tomo-waitlist') {
            waitlist.open = true;
        }

        for (var i = 0; i < forms.length; i++) {
            init(forms[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    } else {
        ready();
    }
})();
