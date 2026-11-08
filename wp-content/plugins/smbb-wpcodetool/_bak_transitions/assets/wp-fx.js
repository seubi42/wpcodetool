/*
 * CodeTool – effets de l'admin (CodeTool › Apparence › Effets).
 * - body.smbb-fx-toasts   : les confirmations de WordPress (« Réglages enregistrés »…) deviennent
 *   des bulles qui disparaissent seules. Les erreurs et les messages avec formulaire restent.
 * - body.smbb-fx-skeleton : quand on quitte une page et que la suivante tarde, la zone de contenu
 *   laisse place à une silhouette qui pulse.
 * Masquage initial des confirmations en CSS (wp-skin.css) : rien ne clignote.
 */
(function () {
    'use strict';

    var body = document.body;
    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- 1. Bulles ---------- */

    var TOAST_SELECTOR = '#message.updated, #message.notice-success, [id^="setting-error-"].notice-success, '
        + '[id^="setting-error-"].updated, .passflow-flash.notice-success';

    function host() {
        var el = document.querySelector('.smbb-ui-toasts');

        if (!el) {
            el = document.createElement('div');
            el.className = 'smbb-ui-toasts';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            body.appendChild(el);
        }

        return el;
    }

    function toast(notice) {
        var item = document.createElement('div');
        var text = document.createElement('span');
        var source = notice.querySelector('p') || notice;

        item.className = 'smbb-ui-toast';
        text.className = 'smbb-ui-toast-text';

        // Le texte et ses liens (« Voir l'article »), sans le bouton de fermeture.
        Array.prototype.forEach.call(source.childNodes, function (node) {
            if (node.nodeType === 1 && (node.tagName === 'BUTTON' || node.classList.contains('screen-reader-text'))) {
                return;
            }
            text.appendChild(node.cloneNode(true));
        });

        if (!text.textContent.trim()) {
            return false;
        }

        item.appendChild(text);
        host().appendChild(item);

        var timer = null;

        function leave() {
            item.classList.add('is-leaving');
            window.setTimeout(function () {
                item.remove();
            }, 300);
        }

        function arm() {
            timer = window.setTimeout(leave, 5000);
        }

        // Survol ou focus : la bulle attend qu'on ait fini de la lire.
        item.addEventListener('mouseenter', function () {
            window.clearTimeout(timer);
        });
        item.addEventListener('mouseleave', arm);
        item.addEventListener('focusin', function () {
            window.clearTimeout(timer);
        });
        arm();

        return true;
    }

    function toasts() {
        var content = document.getElementById('wpbody-content');

        if (!content) {
            return;
        }

        Array.prototype.forEach.call(content.querySelectorAll(TOAST_SELECTOR), function (notice) {
            if (notice.closest('.smbb-notices-panel') || notice.querySelector('form, ul, ol, pre, input, select, textarea')) {
                notice.classList.add('smbb-toast-kept');
                return;
            }

            if (toast(notice)) {
                notice.remove();
            } else {
                notice.classList.add('smbb-toast-kept');
            }
        });
    }

    /* ---------- 2. Squelette de chargement ---------- */

    var skeletonTimer = null;
    var skeletonGuard = null;

    function skeleton() {
        var content = document.getElementById('wpbody-content');

        if (!content || document.querySelector('.smbb-skeleton')) {
            return;
        }

        var sk = document.createElement('div');
        sk.className = 'smbb-skeleton';
        sk.setAttribute('aria-hidden', 'true');
        sk.innerHTML = '<div class="smbb-sk-title"></div>'
            + '<div class="smbb-sk-row"><i></i><i></i><i></i></div>'
            + '<div class="smbb-sk-card"><b></b><b></b><b></b><b></b><b></b></div>'
            + '<div class="smbb-sk-card is-short"><b></b><b></b></div>';
        content.appendChild(sk);
        body.classList.add('smbb-is-leaving');
    }

    function cancelSkeleton() {
        window.clearTimeout(skeletonTimer);
        window.clearTimeout(skeletonGuard);
        body.classList.remove('smbb-is-leaving');
        var sk = document.querySelector('.smbb-skeleton');
        if (sk) {
            sk.remove();
        }
    }

    function schedule() {
        window.clearTimeout(skeletonTimer);
        // Une page rapide n'a pas besoin de silhouette : on n'affiche qu'au-delà de 250 ms.
        skeletonTimer = window.setTimeout(skeleton, 250);
        // Téléchargement (export CSV…) : la page ne change pas, on retire la silhouette.
        skeletonGuard = window.setTimeout(cancelSkeleton, 8000);
    }

    function leavesPage(a) {
        if (!a || a.target && a.target !== '_self' || a.hasAttribute('download')) {
            return false;
        }

        var href = a.getAttribute('href') || '';

        if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) {
            return false;
        }

        var url;
        try {
            url = new URL(a.href, window.location.href);
        } catch (e) {
            return false;
        }

        if (url.origin !== window.location.origin || url.pathname.indexOf('/wp-admin/') === -1) {
            return false;
        }

        if (/(export|download|\.csv|\.pdf|\.zip|\.xlsx?)/i.test(url.pathname + url.search)) {
            return false;
        }

        // Même page, seule l'ancre change.
        return !(url.pathname === window.location.pathname && url.search === window.location.search && url.hash);
    }

    function skeletonHooks() {
        // Écoute tout à la fin (window, phase de bulle) : les scripts qui gèrent le clic eux-mêmes
        // ont déjà appelé preventDefault.
        window.addEventListener('click', function (e) {
            if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
                return;
            }

            if (leavesPage(e.target.closest && e.target.closest('a[href]'))) {
                schedule();
            }
        });

        window.addEventListener('submit', function (e) {
            var form = e.target;

            if (e.defaultPrevented || (form.target && form.target !== '_self') || form.hasAttribute('data-smbb-no-skeleton')) {
                return;
            }

            // Fenêtre de confirmation (<form method="dialog">) : elle se ferme, la page ne change pas.
            if ((form.getAttribute('method') || '').toLowerCase() === 'dialog' || (e.submitter && (e.submitter.getAttribute('formmethod') || '').toLowerCase() === 'dialog') || form.closest('dialog')) {
                return;
            }

            var action = form.getAttribute('action') || '';
            if (/(export|download)/i.test(action)) {
                return;
            }

            schedule();
        });

        // Retour arrière depuis le cache du navigateur : la page revient telle qu'on l'a laissée.
        window.addEventListener('pageshow', cancelSkeleton);
        // Échap arrête le chargement dans le navigateur : la page reste, la silhouette s'en va.
        window.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                cancelSkeleton();
            }
        });
    }

    function start() {
        if (body.classList.contains('smbb-fx-toasts')) {
            toasts();
        }

        if (body.classList.contains('smbb-fx-skeleton') && !reduced) {
            skeletonHooks();
        }
    }

    if (document.readyState !== 'loading') {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start);
    }
})();
