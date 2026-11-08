/*
 * CodeTool – page profil / modification d'un compte rangée (CodeTool › Apparence).
 * WordPress (et les extensions) y empilent des titres h2 suivis de tableaux : on regroupe chaque
 * titre et ce qui le suit dans une carte, puis on range les cartes sous des onglets en haut
 * (Identité, Adresses, Préférences, Sécurité, Autres), dans le style de la barre CodeTool.
 * Le formulaire et ses champs ne changent pas : tous les onglets partent à l'enregistrement.
 */
(function () {
    'use strict';

    var form = document.getElementById('your-profile');

    if (!form) {
        return;
    }

    var i18n = window.smbbWpProfile || {};

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    // Icône selon le titre (français ou anglais), sinon une icône neutre.
    var ICONS = [
        [/options personnelles|personal options/i, 'admin-appearance'],
        [/^nom$|^name$/i, 'id-alt'],
        [/contact/i, 'email-alt'],
        [/propos|about/i, 'admin-users'],
        [/gestion du compte|account management/i, 'lock'],
        [/application/i, 'admin-network'],
        [/codetool/i, 'art'],
        [/factur|billing/i, 'cart'],
        [/livraison|shipping/i, 'car'],
        [/confirm/i, 'yes-alt'],
        [/session/i, 'desktop'],
        [/r[ôo]le|capabilit/i, 'groups']
    ];

    // Onglets, dans l'ordre d'affichage ; une section va dans le premier dont le motif correspond.
    var TABS = [
        { key: 'identite', label: t('identity', 'Identité'), icon: 'id-alt', match: /^nom$|^name$|contact|propos|about|r[ôo]le/i },
        { key: 'adresses', label: t('addresses', 'Adresses'), icon: 'location', match: /adresse|address|factur|billing|livraison|shipping/i },
        { key: 'preferences', label: t('preferences', 'Préférences'), icon: 'admin-appearance', match: /options personnelles|personal options|codetool|apparence|appearance/i },
        { key: 'securite', label: t('security', 'Sécurité'), icon: 'lock', match: /gestion du compte|account management|application|session|mot de passe|password|two.?factor|deux facteurs/i },
        { key: 'autres', label: t('other', 'Autres'), icon: 'admin-generic', match: null }
    ];

    function iconFor(title) {
        for (var i = 0; i < ICONS.length; i++) {
            if (ICONS[i][0].test(title)) {
                return ICONS[i][1];
            }
        }

        return 'admin-generic';
    }

    function tabFor(title) {
        for (var i = 0; i < TABS.length; i++) {
            if (TABS[i].match && TABS[i].match.test(title)) {
                return TABS[i];
            }
        }

        return TABS[TABS.length - 1];
    }

    function headingOf(el) {
        if (el.tagName === 'H2') {
            return el;
        }

        // Bloc qui commence par son propre titre (mots de passe d'application…).
        var first = el.firstElementChild;

        return first && first.tagName === 'H2' ? first : null;
    }

    var children = Array.prototype.slice.call(form.children);
    var sections = [];
    var current = null;

    children.forEach(function (el) {
        if (el.matches('p.submit, input[type="hidden"], script, style')) {
            return;
        }

        var h2 = headingOf(el);

        if (h2) {
            current = { heading: h2, block: el === h2 ? null : el, items: [] };
            sections.push(current);

            if (el !== h2) {
                current.items.push(el);
            }
            return;
        }

        if (current) {
            current.items.push(el);
        }
    });

    if (sections.length < 2) {
        form.classList.add('smbb-pf-ready');
        return;
    }

    var layout = document.createElement('div');
    var nav = document.createElement('nav');
    var panels = document.createElement('div');

    layout.className = 'smbb-pf-layout';
    nav.className = 'smbb-pf-tabs';
    nav.setAttribute('role', 'tablist');
    nav.setAttribute('aria-label', t('nav', 'Sections'));
    panels.className = 'smbb-pf-cards';
    layout.appendChild(nav);
    layout.appendChild(panels);

    var anchor = sections[0].block || sections[0].heading;
    form.insertBefore(layout, anchor);

    // Un panneau par onglet, créé dans l'ordre de TABS.
    TABS.forEach(function (tab) {
        tab.panel = document.createElement('div');
        tab.panel.className = 'smbb-pf-panel';
        tab.panel.id = 'smbb-pf-tab-' + tab.key;
        tab.panel.setAttribute('role', 'tabpanel');
        tab.count = 0;
        panels.appendChild(tab.panel);
    });

    sections.forEach(function (section, index) {
        var title = section.heading.textContent.replace(/\s+/g, ' ').trim();
        var card = document.createElement('section');
        var header = document.createElement('header');
        var icon = document.createElement('span');
        var bodyEl = document.createElement('div');
        var id = 'smbb-pf-' + (index + 1);
        var tab = tabFor(title);

        card.className = 'smbb-pf-card';
        card.id = id;
        header.className = 'smbb-pf-head';
        icon.className = 'smbb-pf-icon dashicons dashicons-' + iconFor(title);
        icon.setAttribute('aria-hidden', 'true');
        bodyEl.className = 'smbb-pf-body';

        if (!section.heading.id) {
            section.heading.id = id + '-title';
        }
        card.setAttribute('aria-labelledby', section.heading.id);

        header.appendChild(icon);
        header.appendChild(section.heading);
        card.appendChild(header);
        section.items.forEach(function (el) {
            bodyEl.appendChild(el);
        });
        card.appendChild(bodyEl);
        tab.panel.appendChild(card);

        // Une section vide (une extension n'a rien affiché) reste cachée.
        if (!bodyEl.textContent.trim() && !bodyEl.querySelector('input, select, textarea, img')) {
            card.hidden = true;
            return;
        }

        tab.count++;
    });

    var used = TABS.filter(function (tab) {
        if (!tab.count) {
            tab.panel.hidden = true;
        }

        return tab.count > 0;
    });

    var STORE = 'smbbProfileTab';

    function select(tab, focus) {
        used.forEach(function (other) {
            var on = other === tab;
            other.panel.hidden = !on;
            other.button.classList.toggle('is-active', on);
            other.button.setAttribute('aria-selected', on ? 'true' : 'false');
            other.button.tabIndex = on ? 0 : -1;
        });

        if (focus) {
            tab.button.focus();
        }

        try {
            window.sessionStorage.setItem(STORE, tab.key);
        } catch (e) { /* stockage indisponible : on repartira du premier onglet */ }
    }

    used.forEach(function (tab, index) {
        var button = document.createElement('button');
        var glyph = document.createElement('span');

        button.type = 'button';
        button.className = 'smbb-pf-tab';
        button.id = 'smbb-pf-tabbtn-' + tab.key;
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-controls', tab.panel.id);
        glyph.className = 'dashicons dashicons-' + tab.icon;
        glyph.setAttribute('aria-hidden', 'true');
        button.appendChild(glyph);
        button.appendChild(document.createTextNode(tab.label));
        button.addEventListener('click', function () {
            select(tab, false);
        });
        button.addEventListener('keydown', function (e) {
            var step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;

            if (step) {
                e.preventDefault();
                select(used[(index + step + used.length) % used.length], true);
            }
        });
        tab.panel.setAttribute('aria-labelledby', button.id);
        tab.button = button;
        nav.appendChild(button);
    });

    // Une seule catégorie : pas d'onglets, juste les cartes.
    if (used.length < 2) {
        nav.remove();
        form.classList.add('smbb-pf-ready');
        return;
    }

    // Onglet de départ : celui d'un lien #…, sinon celui d'avant l'enregistrement, sinon le premier.
    var start = used[0];
    var saved = null;

    try {
        saved = window.sessionStorage.getItem(STORE);
    } catch (e) { /* idem */ }

    used.forEach(function (tab) {
        if (saved === tab.key) {
            start = tab;
        }
    });

    if (location.hash) {
        var target = null;
        try {
            target = form.querySelector(location.hash);
        } catch (e) { /* ancre qui n'est pas un sélecteur valide */ }

        used.forEach(function (tab) {
            if (target && tab.panel.contains(target)) {
                start = tab;
            }
        });
    }

    select(start, false);

    // Un champ invalide dans un autre onglet : on y va, sinon le navigateur ne peut pas le montrer.
    form.addEventListener('invalid', function (e) {
        used.forEach(function (tab) {
            if (tab.panel.hidden && tab.panel.contains(e.target)) {
                select(tab, false);
            }
        });
    }, true);

    var submit = form.querySelector(':scope > p.submit');
    if (submit) {
        submit.classList.add('smbb-pf-savebar');
        panels.appendChild(submit);
    }

    form.classList.add('smbb-pf-ready');
})();
