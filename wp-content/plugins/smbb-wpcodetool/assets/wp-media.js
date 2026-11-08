/*
 * CodeTool – fenêtre « Détails du fichier joint » (Apparence › Médiathèque et éditeur).
 * - « Copier l'URL dans le presse-papier » devient un bouton icône dans le champ URL ;
 * - les actions du bas deviennent des boutons de l'admin, avec icône et libellé court
 *   (y compris « Remplacer le fichier », ajouté par assets/media-replace.js).
 * Les vues Backbone de la médiathèque sont redessinées souvent : on repasse à chaque ajout.
 */
(function () {
    'use strict';

    var i18n = (window.smbbWpMedia && window.smbbWpMedia.i18n) || {};

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function icon(name) {
        var span = document.createElement('span');
        span.className = 'dashicons dashicons-' + name;
        span.setAttribute('aria-hidden', 'true');

        return span;
    }

    function relabel(el, iconName, label, extraClass) {
        if (el.getAttribute('data-smbb-done')) {
            return;
        }

        var full = el.textContent.replace(/\s+/g, ' ').trim();
        el.setAttribute('data-smbb-done', '1');
        el.setAttribute('title', full);
        el.textContent = '';
        el.appendChild(icon(iconName));

        var text = document.createElement('span');
        text.textContent = label;
        el.appendChild(text);
        el.classList.add('button', 'smbb-media-action');

        if (extraClass) {
            el.classList.add(extraClass);
        }
    }

    function actions(root) {
        Array.prototype.forEach.call(root.querySelectorAll('.attachment-info .actions'), function (bar) {
            Array.prototype.forEach.call(bar.querySelectorAll('a, button'), function (el) {
                if (el.classList.contains('delete-attachment')) {
                    relabel(el, 'trash', t('delete', 'Supprimer'), 'smbb-media-danger');
                } else if (el.classList.contains('view-attachment')) {
                    relabel(el, 'external', t('view', 'Voir'));
                } else if (el.hasAttribute('download')) {
                    relabel(el, 'download', t('download', 'Télécharger'));
                } else if (el.classList.contains('smbb-media-replace-link')) {
                    relabel(el, 'update', t('replace', 'Remplacer'));
                } else if (/action=edit/.test(el.getAttribute('href') || '')) {
                    relabel(el, 'edit', t('edit', 'Modifier'));
                } else if (el.tagName === 'A' && !el.getAttribute('data-smbb-done')) {
                    el.classList.add('button', 'smbb-media-action');
                }
            });
        });
    }

    function copyButtons(root) {
        Array.prototype.forEach.call(root.querySelectorAll('.copy-attachment-url:not([data-smbb-done])'), function (button) {
            var box = button.closest('.copy-to-clipboard-container');
            var details = button.closest('.attachment-details, .media-sidebar, .attachment-info');
            var input = details && details.querySelector('.setting[data-setting="url"] input, input#attachment-details-two-column-copy-link, input#attachment-details-copy-link');

            if (!input) {
                return;
            }

            var label = button.textContent.replace(/\s+/g, ' ').trim();
            button.setAttribute('data-smbb-done', '1');
            button.setAttribute('aria-label', label);
            button.setAttribute('title', label);
            button.textContent = '';
            button.appendChild(icon('admin-page'));
            button.classList.add('smbb-copy-icon');

            // Le bouton se loge au bout du champ URL ; ClipboardJS l'écoute par sa classe, il marche toujours.
            var wrap = document.createElement('span');
            wrap.className = 'smbb-url-field';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            wrap.appendChild(button);

            if (box) {
                box.classList.add('smbb-copy-moved');
            }

            button.addEventListener('click', function () {
                button.classList.add('is-copied');
                button.firstChild.className = 'dashicons dashicons-yes';
                button.setAttribute('title', t('copied', 'Copié !'));
                window.setTimeout(function () {
                    button.classList.remove('is-copied');
                    button.firstChild.className = 'dashicons dashicons-admin-page';
                    button.setAttribute('title', label);
                }, 1800);
            });
        });
    }

    function run(root) {
        actions(root);
        copyButtons(root);
    }

    var pending = false;

    new MutationObserver(function () {
        if (pending) {
            return;
        }

        pending = true;
        window.requestAnimationFrame(function () {
            pending = false;
            run(document);
        });
    }).observe(document.body, { childList: true, subtree: true });

    run(document);
})();
