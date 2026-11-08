/**
 * Fiche Journée vivante : compte les inscrits de 0 à N et fait tourner le compte à rebours.
 * Le rendu initial vient du PHP (Admin\SlotPulse) ; même calcul ici, chaque seconde.
 * prefers-reduced-motion : pas de comptage animé (l'horloge, elle, reste à l'heure).
 */
(function () {
    'use strict';

    var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var DAY = 86400;
    var HOUR = 3600;

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    function seg(n, label) {
        return '<span class="passflow-pulse-seg"><b>' + esc(n) + '</b><small>' + esc(label) + '</small></span>';
    }

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function countUp(el) {
        var target = parseInt(el.getAttribute('data-pulse-count'), 10) || 0;

        if (reduce || target <= 0 || !window.requestAnimationFrame) {
            return;
        }

        var t0 = null;
        var duration = 1100;

        el.textContent = '0';
        window.requestAnimationFrame(function step(t) {
            if (t0 === null) {
                t0 = t;
            }

            var p = Math.min(1, (t - t0) / duration);
            el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));

            if (p < 1) {
                window.requestAnimationFrame(step);
            }
        });
    }

    function state(c, now) {
        var l = c.labels;
        var localDay = function (ts, off) { return Math.floor((ts + off) / DAY); };
        // Décalage du fuseau du site au début et maintenant (heure d'été comprise, au chargement).
        var cal = localDay(c.start, c.tz) - localDay(now, c.tzNow);
        var diff, d, h, m, s, html, label, chip;

        if (now < c.start) {
            diff = c.start - now;
            d = Math.floor(diff / DAY);
            h = Math.floor((diff % DAY) / HOUR);
            m = Math.floor((diff % HOUR) / 60);
            s = diff % 60;
            chip = cal === 0 ? l.today : (cal === 1 ? l.tomorrow : '');

            if (d >= 1) {
                html = seg('J-' + d, d > 1 ? l.days : l.day) + seg(h, l.h) + seg(pad(m), l.min);
                label = l.before + ' J-' + d + ', ' + h + ' ' + l.h + ' ' + pad(m);
            } else {
                html = (h > 0 ? seg(h, l.h) : '') + seg(pad(m), l.min) + seg(pad(s), l.s);
                label = l.before + ' ' + h + ' ' + l.h + ' ' + pad(m);
            }

            return {
                state: diff < HOUR ? 'soon' : (d < 1 ? 'near' : 'before'),
                kicker: esc(l.before) + (chip ? ' <span class="passflow-pulse-chip">' + esc(chip) + '</span>' : ''),
                html: html,
                label: label
            };
        }

        if (now < c.end) {
            diff = c.end - now;
            h = Math.floor(diff / HOUR);
            m = Math.floor((diff % HOUR) / 60);
            s = diff % 60;

            return {
                state: 'live',
                kicker: '<span class="passflow-pulse-dot" aria-hidden="true"></span>' + esc(l.live),
                html: c.allDay
                    ? '<span class="passflow-pulse-state">' + esc(l.allDay) + '</span>'
                    : '<span class="passflow-pulse-ends">' + esc(l.endsIn) + '</span>' + (h > 0 ? seg(h, l.h) : '') + seg(pad(m), l.min) + seg(pad(s), l.s),
                label: l.live
            };
        }

        var ago = localDay(now, c.tzNow) - localDay(c.end, c.tz);
        var text = ago <= 0 ? l.agoToday : (ago === 1 ? l.agoYesterday : l.agoDays.replace('%d', ago));

        return {
            state: 'done',
            kicker: esc(l.done),
            html: '<span class="passflow-pulse-state">' + esc(text) + '</span>',
            label: l.done + ' ' + text
        };
    }

    function clock(el) {
        var c;

        try {
            c = {
                start: parseInt(el.getAttribute('data-pulse-start'), 10),
                end: parseInt(el.getAttribute('data-pulse-end'), 10),
                tz: parseInt(el.getAttribute('data-pulse-tz'), 10) || 0,
                tzNow: parseInt(el.getAttribute('data-pulse-tz-now'), 10) || 0,
                allDay: el.getAttribute('data-pulse-allday') === '1',
                labels: JSON.parse(el.getAttribute('data-pulse-labels') || '{}')
            };
        } catch (e) {
            return;
        }

        if (!c.start || !c.end) {
            return;
        }

        // Horloge du serveur : on corrige l'écart avec celle du poste.
        var skew = (parseInt(el.getAttribute('data-pulse-now'), 10) || Math.floor(Date.now() / 1000)) - Math.floor(Date.now() / 1000);
        var kicker = el.querySelector('.passflow-pulse-kicker');
        var count = el.querySelector('.passflow-pulse-count');
        var last = { state: '', kicker: '', html: '', label: '' };
        var timer;

        function tick() {
            var st = state(c, Math.floor(Date.now() / 1000) + skew);

            if (st.state !== last.state) {
                el.className = el.className.replace(/\bis-[a-z]+\b/g, '').trim() + ' is-' + st.state;

                // Le décompte arrive à zéro : petit éclat (sauf mouvement réduit).
                if (last.state && !reduce && st.state === 'live') {
                    el.classList.add('is-go');
                    window.setTimeout(function () { el.classList.remove('is-go'); }, 1200);
                }
            }

            if (kicker && st.kicker !== last.kicker) {
                kicker.innerHTML = st.kicker;
            }

            if (count && st.html !== last.html) {
                count.innerHTML = st.html;
            }

            if (st.label !== last.label) {
                el.setAttribute('aria-label', st.label);
            }

            last = st;

            if (st.state === 'done' && timer) {
                window.clearInterval(timer);
            }
        }

        tick();

        if (last.state !== 'done') {
            timer = window.setInterval(tick, 1000);
        }
    }

    function init() {
        Array.prototype.forEach.call(document.querySelectorAll('.passflow-pulse [data-pulse-count]'), countUp);
        Array.prototype.forEach.call(document.querySelectorAll('.passflow-pulse-clock[data-pulse-start]'), clock);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
