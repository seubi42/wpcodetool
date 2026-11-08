/*
 * CodeTool – bouton « Remplacer le fichier » dans la fenêtre des médias
 * (médiathèque en grille et fenêtre ouverte depuis l'éditeur ou un champ image).
 * Il se place juste après « Modifier plus de détails ». Avec l'habillage CodeTool de la
 * médiathèque (wp-media.js), il prend le même style de bouton icône que les autres actions.
 * Depuis l'éditeur, la page s'ouvre dans un nouvel onglet pour ne pas perdre le travail en cours.
 */
(function () {
    'use strict';

    var cfg = window.smbbMediaReplace || {};

    if (!cfg.url) {
        return;
    }

    var onLibrary = /\/upload\.php$/.test(window.location.pathname);

    function idFromEditLink(a) {
        var m = /[?&]post=(\d+)/.exec(a.getAttribute('href') || '');

        return m ? m[1] : '';
    }

    function addButton(editLink) {
        var parent = editLink.parentNode;

        if (!parent || parent.querySelector('.smbb-media-replace-link')) {
            return;
        }

        var id = idFromEditLink(editLink);

        if (!id) {
            return;
        }

        var a = document.createElement('a');
        a.className = 'smbb-media-replace-link';
        a.href = cfg.url + id + (onLibrary ? '&return=grid' : '');
        a.title = cfg.label;

        if (!onLibrary) {
            a.target = '_blank';
            a.rel = 'noopener';
        }

        // Avec l'habillage CodeTool de la médiathèque, wp-media.js le transforme en bouton icône
        // comme les autres actions (il repasse à chaque changement de la fenêtre).
        a.textContent = cfg.label;
        var sep = document.createElement('span');
        sep.className = 'links-separator';
        sep.textContent = '|';
        parent.insertBefore(sep, editLink.nextSibling);
        parent.insertBefore(a, sep.nextSibling);
    }

    function run() {
        var links = document.querySelectorAll('.media-modal .attachment-info a[href*="post.php?post="], .media-modal .attachment-details a[href*="post.php?post="], .media-frame .attachment-details a.edit-attachment');

        Array.prototype.forEach.call(links, function (a) {
            if (/action=edit/.test(a.getAttribute('href') || '')) {
                addButton(a);
            }
        });
    }

    var pending = false;

    function schedule() {
        if (pending) {
            return;
        }

        pending = true;
        window.requestAnimationFrame(function () {
            pending = false;
            run();
        });
    }

    function start() {
        new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
        run();
    }

    if (document.body) {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start);
    }
})();
