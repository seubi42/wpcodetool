/* Tomo : écran « Établir une facture » (Mon compte et administration).
 * 1. On coche les lignes (participants, produits avec la quantité), 2. « Générer la facture »
 * ouvre les coordonnées, 3. « Émettre la facture » (confirmation), en Ajax. */
(function () {
    'use strict';

    var t = window.tomoInvoiceText || {};

    function money(value) {
        return value.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
    }

    function ask(question, help) {
        return new Promise(function (resolve) {
            var box = document.createElement('div');
            box.className = 'tomo-inv-dialog';
            box.innerHTML = '<div class="tomo-inv-dialog-card" role="dialog" aria-modal="true"><p class="tomo-inv-dialog-q"></p><p class="tomo-inv-dialog-h"></p>'
                + '<p class="tomo-inv-dialog-b"><button type="button" class="button" data-no></button> <button type="button" class="button button-primary" data-yes></button></p></div>';
            box.querySelector('.tomo-inv-dialog-q').textContent = question;
            box.querySelector('.tomo-inv-dialog-h').textContent = help;
            box.querySelector('[data-no]').textContent = t.cancel || 'Annuler';
            box.querySelector('[data-yes]').textContent = t.confirm || 'OK';
            document.body.appendChild(box);
            box.querySelector('[data-yes]').focus();

            function close(answer) {
                box.remove();
                resolve(answer);
            }

            box.querySelector('[data-no]').addEventListener('click', function () { close(false); });
            box.querySelector('[data-yes]').addEventListener('click', function () { close(true); });
            box.addEventListener('click', function (e) { if (e.target === box) { close(false); } });
        });
    }

    function init(root) {
        var boxes = Array.prototype.slice.call(root.querySelectorAll('[data-tomo-inv-unit]'));
        var sum = root.querySelector('[data-tomo-inv-sum]');
        var next = root.querySelector('[data-tomo-inv-next]');
        var billing = root.querySelector('[data-tomo-inv-billing]');
        var submit = root.querySelector('[data-tomo-inv-submit]');
        var msg = root.querySelector('[data-tomo-inv-msg]');
        var profiles = [];

        try { profiles = JSON.parse(root.getAttribute('data-profiles') || '[]'); } catch (e) { profiles = []; }

        // Clés des unités cochées (pour un produit en quantité : les n premières).
        function keys() {
            var out = [];

            boxes.forEach(function (box) {
                if (!box.checked) { return; }
                var list = box.getAttribute('data-keys').split(',');
                var qty = box.closest('tr').querySelector('[data-tomo-inv-qty]');
                var n = qty ? parseInt(qty.value, 10) : list.length;
                out = out.concat(list.slice(0, n));
            });

            return out;
        }

        function refresh() {
            var total = 0;

            boxes.forEach(function (box) {
                if (!box.checked) { return; }
                var qty = box.closest('tr').querySelector('[data-tomo-inv-qty]');
                var n = qty ? parseInt(qty.value, 10) : box.getAttribute('data-keys').split(',').length;
                total += n * parseFloat(box.getAttribute('data-ttc') || '0');
            });

            sum.textContent = money(total);
            next.disabled = total === 0 && keys().length === 0;
            var head = billing.querySelector('[data-tomo-inv-total]');
            if (head) { head.textContent = money(total); }

            var fieldset = billing.querySelector('fieldset');
            var company = fieldset.querySelector('[data-tomo-inv-type]').value === 'company';
            Array.prototype.forEach.call(fieldset.querySelectorAll('[data-tomo-inv-company]'), function (input) {
                input.closest('label').hidden = !company;
            });
        }

        root.addEventListener('change', function (e) {
            var target = e.target;

            if (target.hasAttribute('data-tomo-inv-qty')) {
                target.closest('tr').querySelector('[data-tomo-inv-unit]').checked = true;
            }

            if (target.hasAttribute('data-tomo-inv-profile') && target.value !== '') {
                var profile = profiles[parseInt(target.value, 10)] || {};
                var fieldset = target.closest('fieldset');
                Object.keys(profile).forEach(function (key) {
                    var input = fieldset.querySelector('[name$="[' + key + ']"]');
                    if (input) { input.value = profile[key]; }
                });
            }

            refresh();
        });

        next.addEventListener('click', function () {
            if (!keys().length) {
                msg.textContent = t.none || '';
                msg.className = 'tomo-inv-msg is-error';
                return;
            }

            msg.textContent = '';
            billing.hidden = false;
            next.hidden = true;
            root.classList.add('is-billing');
            billing.scrollIntoView({ behavior: 'smooth', block: 'start' });
            billing.querySelector('[name$="[name]"]').focus({ preventScroll: true });
        });

        root.querySelector('[data-tomo-inv-back]').addEventListener('click', function () {
            billing.hidden = true;
            next.hidden = false;
            root.classList.remove('is-billing');
        });

        submit.addEventListener('click', function () {
            var chosen = keys();
            var name = billing.querySelector('[name$="[name]"]');

            if (!chosen.length) {
                msg.textContent = t.none || '';
                msg.className = 'tomo-inv-msg is-error';
                return;
            }

            if (!name.value.trim()) {
                msg.textContent = t.name || '';
                msg.className = 'tomo-inv-msg is-error';
                name.focus();
                return;
            }

            ask(t.confirmOne, t.confirmHelp).then(function (yes) {
                if (!yes) { return; }

                var data = new FormData();
                data.append('action', 'tomo_invoice');
                data.append('nonce', root.getAttribute('data-nonce'));
                data.append('order', root.getAttribute('data-order'));
                data.append('context', root.getAttribute('data-context'));

                chosen.forEach(function (key) { data.append('u[' + key + ']', '1'); });
                Array.prototype.forEach.call(billing.querySelectorAll('[name]'), function (input) {
                    if (input.type === 'checkbox') {
                        if (input.checked) { data.append(input.name, '1'); }
                    } else {
                        data.append(input.name, input.value);
                    }
                });

                submit.disabled = true;
                msg.className = 'tomo-inv-msg';
                msg.textContent = '…';

                fetch(root.getAttribute('data-url'), { method: 'POST', body: data, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (r) {
                        msg.textContent = r.message || '';
                        msg.className = 'tomo-inv-msg ' + (r.success ? 'is-ok' : 'is-error');
                        if (r.success && r.redirect) {
                            window.location.href = r.redirect;
                        } else {
                            submit.disabled = false;
                        }
                    })
                    .catch(function () {
                        msg.textContent = t.error || '';
                        msg.className = 'tomo-inv-msg is-error';
                        submit.disabled = false;
                    });
            });
        });

        refresh();
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('[data-tomo-inv]'), init);
    });
}());
