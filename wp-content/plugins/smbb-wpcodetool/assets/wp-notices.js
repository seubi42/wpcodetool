/*
 * CodeTool – cloche des messages des extensions (CodeTool › Apparence).
 * Les bandeaux posés en haut des écrans (admin_notices) sont rangés dans un panneau ouvert par
 * une cloche dans la barre du haut. Restent à leur place : les confirmations d'action de
 * WordPress (#message, « Réglages enregistrés »), les notices en ligne, celles de CodeTool et
 * celles marquées data-smbb-keep / .smbb-keep-notice.
 */
(function () {
    'use strict';

    var i18n = window.smbbWpNotices || {};

    function keep(el) {
        if (el.id === 'message' || /^setting-error-/.test(el.id || '')) {
            return true;
        }

        if (el.hasAttribute('data-smbb-keep') || el.closest('.smbb-notices-panel')) {
            return true;
        }

        return el.matches('.inline, .hidden, .smbb-codetool-notice, .smbb-keep-notice, .passflow-flash:not(.passflow-test-notice):not(.notice-warning:not(.is-dismissible)), [aria-hidden="true"]');
    }

    function collect() {
        var content = document.getElementById('wpbody-content');

        if (!content) {
            return [];
        }

        // Seulement les bandeaux du haut : enfants directs de #wpbody-content ou d'un .wrap.
        var nodes = content.querySelectorAll(':scope > .notice, :scope > div.updated, :scope > div.error, :scope > .update-nag, '
            + ':scope > .wrap > .notice, :scope > .wrap > div.updated, :scope > .wrap > div.error, :scope > .wrap > .update-nag, '
            // Bandeaux d'état posés plus bas dans la page (en-tête Tomo…) : marqués pour la cloche.
            // Bandeaux que common.js de WordPress a déplacés juste après le titre de la page, quand ce titre
            // est rangé dans un en-tête (pages CodeTool, autres extensions) : ils ne sont plus enfants du .wrap.
            + '.wrap :is(h1, h2, .wp-header-end) ~ :is(.notice, div.updated, div.error, .update-nag), '
            + '.passflow-test-notice, .passflow-flash.notice-warning:not(.is-dismissible), [data-smbb-bell]');

        return Array.prototype.filter.call(nodes, function (el) {
            return !keep(el);
        });
    }

    function init() {
        var node = document.getElementById('wp-admin-bar-smbb-notices');
        var notices = collect();

        if (!node || !notices.length) {
            return;
        }

        var link = node.querySelector('.ab-item');
        var count = node.querySelector('.smbb-notices-count');
        var panel = document.createElement('div');
        var title = document.createElement('p');

        panel.className = 'smbb-notices-panel';
        panel.id = 'smbb-notices-panel';
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', i18n.title || 'Messages');
        title.className = 'smbb-notices-panel-title';
        title.textContent = i18n.title || 'Messages';
        panel.appendChild(title);

        notices.forEach(function (el) {
            panel.appendChild(el);
        });

        document.body.appendChild(panel);
        node.classList.remove('hidden');
        count.textContent = String(notices.length);

        // Pastille posée par un bandeau (data-smbb-bell-flag="card" : paiement fictif actif) :
        // elle s'affiche dans la cloche et explique, une fois dépliée, pourquoi il y a un message.
        notices.forEach(function (el) {
            var flag = el.getAttribute('data-smbb-bell-flag');

            if (!flag || link.querySelector('[data-flag="' + flag + '"]')) {
                return;
            }

            var badge = document.createElement('span');
            badge.className = 'smbb-' + (flag === 'card' ? 'cb' : flag) + '-flag';
            badge.setAttribute('data-flag', flag);
            badge.setAttribute('aria-hidden', 'true');
            badge.title = el.getAttribute('data-smbb-bell-flag-title') || '';
            link.insertBefore(badge, link.firstChild);
            node.classList.add('has-flag-' + flag);
        });
        link.setAttribute('aria-controls', panel.id);
        link.setAttribute('aria-expanded', 'false');

        function sync() {
            var left = panel.querySelectorAll('.notice, div.updated, div.error, .update-nag').length;
            count.textContent = String(left);

            // Bandeau à pastille fermé : la pastille s'en va avec lui.
            link.querySelectorAll('[data-flag]').forEach(function (badge) {
                if (!panel.querySelector('[data-smbb-bell-flag="' + badge.getAttribute('data-flag') + '"]')) {
                    node.classList.remove('has-flag-' + badge.getAttribute('data-flag'));
                    badge.remove();
                }
            });

            if (!left) {
                panel.hidden = true;
                node.classList.add('hidden');
            }
        }

        function toggle(open) {
            panel.hidden = !open;
            link.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        link.addEventListener('click', function (e) {
            e.preventDefault();
            toggle(panel.hidden);
        });

        document.addEventListener('click', function (e) {
            if (!panel.hidden && !panel.contains(e.target) && !node.contains(e.target)) {
                toggle(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !panel.hidden) {
                toggle(false);
                link.focus();
            }
        });

        // Une notice fermée (croix de WordPress) disparaît du DOM : on remet le compteur à jour.
        new MutationObserver(sync).observe(panel, { childList: true, subtree: true });
    }

    // Après common.js de WordPress (jQuery ready), qui déplace les notices et ajoute les croix.
    // On n'attend pas « load » : une image ou un script lent d'une extension peut le retarder longtemps.
    var started = false;

    function start() {
        if (started) {
            return;
        }

        started = true;
        window.setTimeout(init, 0);
    }

    if (window.jQuery) {
        window.jQuery(start);
    } else if (document.readyState !== 'loading') {
        start();
    } else {
        document.addEventListener('DOMContentLoaded', start);
    }
})();
