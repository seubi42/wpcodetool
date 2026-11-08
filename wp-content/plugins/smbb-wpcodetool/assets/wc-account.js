/*
 * CodeTool – « Mon compte » WooCommerce, onglet Mes ateliers (Tomo) en deux niveaux.
 * 1. Une ligne épurée par réservation : date en pastille, atelier, horaire, participant, statut.
 * 2. Un clic ouvre une fiche (dialog) avec le billet, les Wallets, l'agenda et les documents.
 * Les liens d'origine de Tomo sont déplacés dans la fiche, pas recopiés : leurs scripts suivent.
 * Sans JS, le tableau garde la présentation en cartes de wc-account.css.
 */
(function () {
    'use strict';

    var i18n = window.smbbWcAccount || {};

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }

        return node;
    }

    function clean(node) {
        return node ? node.textContent.replace(/\s+/g, ' ').trim() : '';
    }

    function icon(name) {
        var span = el('span', 'smbb-bk-i dashicons dashicons-' + name);
        span.setAttribute('aria-hidden', 'true');

        return span;
    }

    // « mer 11 Nov 2026, 19h30-21h00 » → { day: '11', month: 'Nov', weekday: 'mer', year: '2026', time: '19h30-21h00' }
    function parseWhen(text) {
        var m = /^(\S+)\.?\s+(\d{1,2})\s+([^\s\d,]+)\.?\s+(\d{4})(?:\s*,\s*(.+))?$/.exec(text);

        return m ? { weekday: m[1], day: m[2], month: m[3].replace(/\.$/, ''), year: m[4], time: m[5] || '' } : null;
    }

    function dateTile(when) {
        var tile = el('span', 'smbb-bk-date');
        tile.setAttribute('aria-hidden', 'true');

        if (when) {
            tile.appendChild(el('span', 'smbb-bk-month', when.month));
            tile.appendChild(el('span', 'smbb-bk-day', when.day));
        } else {
            tile.classList.add('is-icon');
            tile.appendChild(icon('calendar-alt'));
        }

        return tile;
    }

    function row(iconName, label, value) {
        var line = el('div', 'smbb-bk-row');
        line.appendChild(icon(iconName));
        line.appendChild(el('span', 'smbb-bk-row-label', label));
        var v = el('span', 'smbb-bk-row-value');

        if (typeof value === 'string') {
            v.textContent = value;
        } else if (value) {
            v.appendChild(value);
        }
        line.appendChild(v);

        return line;
    }

    var dialog = null;

    function sheet() {
        if (dialog) {
            return dialog;
        }

        dialog = el('dialog', 'smbb-bk-dialog');
        dialog.setAttribute('aria-labelledby', 'smbb-bk-title');
        dialog.addEventListener('click', function (e) {
            // Clic sur le fond (hors de la fiche) : on ferme.
            if (e.target === dialog) {
                dialog.close();
            }
        });
        document.body.appendChild(dialog);

        return dialog;
    }

    function openSheet(booking) {
        var d = sheet();
        var inner = el('div', 'smbb-bk-sheet');
        var head = el('header', 'smbb-bk-head');
        var titles = el('div', 'smbb-bk-titles');
        var title = el('h2', '', booking.atelier);
        var close = el('button', 'smbb-bk-close');

        title.id = 'smbb-bk-title';
        titles.appendChild(title);
        titles.appendChild(el('p', 'smbb-bk-when', booking.whenText));
        close.type = 'button';
        close.setAttribute('aria-label', t('close', 'Fermer'));
        close.appendChild(icon('no-alt'));
        close.addEventListener('click', function () {
            d.close();
        });
        head.appendChild(dateTile(booking.when));
        head.appendChild(titles);
        head.appendChild(close);
        inner.appendChild(head);

        var info = el('div', 'smbb-bk-group');
        info.appendChild(row('admin-users', t('participant', 'Participant'), booking.who));
        if (booking.status) {
            info.appendChild(row('yes-alt', t('status', 'Statut'), booking.status.cloneNode(true)));
        }
        if (booking.order) {
            info.appendChild(row('cart', t('order', 'Commande'), booking.order));
        }
        inner.appendChild(info);

        // Billet : PDF et agenda en lignes d'action, Wallets en dessous.
        if (booking.tickets.length || booking.wallets || booking.waiting) {
            inner.appendChild(el('h3', 'smbb-bk-label', t('ticket', 'Mon billet')));

            if (booking.waiting) {
                inner.appendChild(el('p', 'smbb-bk-note', clean(booking.waiting)));
            }

            if (booking.tickets.length) {
                var actions = el('div', 'smbb-bk-group is-actions');
                booking.tickets.forEach(function (a) {
                    actions.appendChild(a);
                });
                inner.appendChild(actions);
            }

            if (booking.wallets) {
                inner.appendChild(booking.wallets);
            }
        }

        if (booking.docs) {
            inner.appendChild(el('h3', 'smbb-bk-label', t('documents', 'Documents')));
            inner.appendChild(booking.docs);
        }

        // Les liens reviennent dans leur ligne à la fermeture (rien n'est perdu d'une ouverture à l'autre).
        d.textContent = '';
        d.appendChild(inner);
        d.onclose = function () {
            booking.restore();
            booking.trigger.focus();
        };

        if (typeof d.showModal === 'function') {
            d.showModal();
        } else {
            d.setAttribute('open', '');
        }
        close.focus();
    }

    function enhance(table) {
        var rows = Array.prototype.slice.call(table.querySelectorAll('tbody > tr'));
        var list = el('ul', 'smbb-bk-list');

        if (!rows.length) {
            return;
        }

        rows.forEach(function (tr) {
            var td = tr.children;

            if (td.length < 6) {
                return;
            }

            var whenText = clean(td[1]);
            var ticketCell = td[5];
            var docsCell = td[6] || null;
            var docsLink = docsCell ? docsCell.querySelector('a') : null;
            var orderLink = td[4].querySelector('a');
            var booking = {
                atelier: clean(td[0]),
                whenText: whenText,
                when: parseWhen(whenText),
                who: clean(td[2]),
                status: td[3].querySelector('.passflow-status'),
                order: orderLink,
                tickets: Array.prototype.slice.call(ticketCell.querySelectorAll('a.passflow-ticket-link')),
                wallets: ticketCell.querySelector('.passflow-wallets'),
                waiting: ticketCell.querySelector('.passflow-order-waiting'),
                docs: null
            };

            // Documents : le bouton « N documents à remplir » ou « Complets ».
            if (docsCell && (docsLink || (clean(docsCell) !== '' && clean(docsCell) !== '—'))) {
                var docsBox = el('div', 'smbb-bk-docs');
                if (docsLink) {
                    docsBox.appendChild(docsLink);
                    docsBox.classList.toggle('is-todo', docsLink.classList.contains('passflow-docs-link'));
                } else {
                    docsBox.appendChild(el('span', '', clean(docsCell)));
                }
                booking.docs = docsBox;
            }

            booking.tickets.forEach(function (a) {
                var pdf = /format=pdf|pdf/i.test(a.getAttribute('href') || '') && !/ics/i.test(a.getAttribute('href') || '');
                a.textContent = '';
                a.classList.add('smbb-bk-action');
                a.appendChild(icon(pdf ? 'media-document' : 'calendar-alt'));
                a.appendChild(el('span', '', pdf ? t('pdf', 'Télécharger le billet (PDF)') : t('ics', 'Ajouter à mon agenda')));
                a.appendChild(icon('arrow-right-alt2'));
            });

            // Ce qu'on déplace dans la fiche, et où le remettre.
            var moved = [booking.order, booking.wallets, docsLink].concat(booking.tickets).filter(Boolean).map(function (node) {
                return { node: node, parent: node.parentNode, next: node.nextSibling };
            });
            booking.restore = function () {
                moved.forEach(function (m) {
                    m.parent.insertBefore(m.node, m.next && m.next.parentNode === m.parent ? m.next : null);
                });
            };

            var li = el('li', 'smbb-bk');
            var button = el('button', 'smbb-bk-open');
            var main = el('span', 'smbb-bk-main');
            var top = el('span', 'smbb-bk-top');
            var meta = el('span', 'smbb-bk-meta');

            button.type = 'button';
            button.setAttribute('aria-haspopup', 'dialog');
            top.appendChild(el('span', 'smbb-bk-name', booking.atelier));
            if (booking.status) {
                top.appendChild(booking.status.cloneNode(true));
            }
            meta.appendChild(el('span', '', booking.when ? (booking.when.weekday + ' ' + booking.when.day + ' ' + booking.when.month + (booking.when.time ? ' · ' + booking.when.time : '')) : whenText));
            meta.appendChild(el('span', 'smbb-bk-who', booking.who));
            main.appendChild(top);
            main.appendChild(meta);

            if (docsLink && docsLink.classList.contains('passflow-docs-link')) {
                main.appendChild(el('span', 'smbb-bk-flag', clean(docsLink)));
            }

            button.appendChild(dateTile(booking.when));
            button.appendChild(main);
            button.appendChild(icon('arrow-right-alt2'));
            button.addEventListener('click', function () {
                openSheet(booking);
            });
            booking.trigger = button;
            li.appendChild(button);
            list.appendChild(li);
        });

        if (list.children.length) {
            table.parentNode.insertBefore(list, table);
            table.classList.add('smbb-bk-source');
        }
    }

    function start() {
        Array.prototype.forEach.call(document.querySelectorAll('.woocommerce-MyAccount-content table.passflow-account-table'), enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
