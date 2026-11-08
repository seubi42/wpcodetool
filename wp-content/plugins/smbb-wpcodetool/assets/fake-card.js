/**
 * Carte bancaire FICTIVE (CodeTool › WooCommerce) : outils communs + tunnel classique.
 *
 * window.SmbbFakeCard expose :
 * - random()           carte aléatoire valide (Luhn) : {brand, number, expiry, cvc}
 * - brandOf(), format(), formatExpiry(), validate(data) → message d'erreur ou ''
 * - typewrite(value, onStep, done)  saisie animée chiffre par chiffre
 * - open3ds({amount, last4, brand, scenario, auto}) → Promise ('ok' | rejet 'failed' / 'cancelled')
 *
 * Le bloc Commande (fake-card-blocks.js) réutilise ces outils.
 */
(function (window, document) {
    'use strict';

    var cfg = window.smbbFakeCard || {};
    var i18n = cfg.i18n || {};

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function rand(n) {
        return Math.floor(Math.random() * n);
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function digits(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    var BRANDS = {
        visa: { prefixes: ['4'], length: 16, cvc: 3, label: 'Visa' },
        mastercard: { prefixes: ['51', '52', '53', '54', '55', '2221', '2720'], length: 16, cvc: 3, label: 'Mastercard' },
        amex: { prefixes: ['34', '37'], length: 15, cvc: 4, label: 'American Express' }
    };

    function luhnOk(number) {
        var sum = 0;
        var dbl = false;

        for (var i = number.length - 1; i >= 0; i--) {
            var d = +number.charAt(i);

            if (dbl) {
                d *= 2;
                if (d > 9) { d -= 9; }
            }

            sum += d;
            dbl = !dbl;
        }

        return number.length > 0 && sum % 10 === 0;
    }

    function luhnComplete(prefix, length) {
        var list = prefix.split('').map(Number);

        while (list.length < length - 1) {
            list.push(rand(10));
        }

        var sum = 0;
        var dbl = true;

        for (var i = list.length - 1; i >= 0; i--) {
            var d = list[i];

            if (dbl) {
                d *= 2;
                if (d > 9) { d -= 9; }
            }

            sum += d;
            dbl = !dbl;
        }

        list.push((10 - (sum % 10)) % 10);

        return list.join('');
    }

    function brandOf(value) {
        var d = digits(value);

        if (/^3[47]/.test(d)) { return 'amex'; }
        if (/^(5[1-5]|2[2-7])/.test(d)) { return 'mastercard'; }
        if (/^4/.test(d)) { return 'visa'; }

        return '';
    }

    function format(value) {
        var d = digits(value).slice(0, 19);

        if (brandOf(d) === 'amex') {
            d = d.slice(0, 15);

            return [d.slice(0, 4), d.slice(4, 10), d.slice(10)].filter(Boolean).join(' ');
        }

        return d.replace(/(\d{4})(?=\d)/g, '$1 ');
    }

    function formatExpiry(value) {
        var d = digits(value).slice(0, 4);

        if (d.length === 1 && +d > 1) { d = '0' + d; }

        return d.length > 2 ? d.slice(0, 2) + ' / ' + d.slice(2) : d;
    }

    function random() {
        var keys = ['visa', 'visa', 'mastercard', 'mastercard', 'amex'];
        var key = keys[rand(keys.length)];
        var brand = BRANDS[key];
        var year = (new Date().getFullYear() % 100) + 1 + rand(5);
        var cvc = '';

        for (var i = 0; i < brand.cvc; i++) {
            cvc += rand(10);
        }

        return {
            brand: key,
            number: format(luhnComplete(brand.prefixes[rand(brand.prefixes.length)], brand.length)),
            expiry: pad(1 + rand(12)) + ' / ' + pad(year),
            cvc: cvc
        };
    }

    function validate(data) {
        var number = digits(data.number);

        if (number.length < 13 || number.length > 19 || !luhnOk(number)) {
            return t('badNumber', 'Le numéro de carte n\'est pas valide.');
        }

        var m = /^\s*(\d{1,2})\s*\/\s*(\d{2})\s*$/.exec(data.expiry || '');
        var now = new Date();

        if (!m || +m[1] < 1 || +m[1] > 12 || (2000 + +m[2]) * 12 + +m[1] < now.getFullYear() * 12 + now.getMonth() + 1) {
            return t('badExpiry', 'La date d\'expiration n\'est pas valide.');
        }

        if (digits(data.cvc).length !== (brandOf(number) === 'amex' ? 4 : 3)) {
            return t('badCvc', 'Le code de sécurité n\'est pas valide.');
        }

        return '';
    }

    function needs3ds(scenario) {
        return scenario === '3ds' || scenario === '3ds_fail';
    }

    /**
     * Saisie animée : onStep(texte partiel) à chaque caractère, puis done().
     */
    function typewrite(value, onStep, done, speed) {
        var i = 0;
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (reduce) {
            onStep(value);
            if (done) { done(); }

            return function () {};
        }

        var timer = window.setInterval(function () {
            i++;
            onStep(value.slice(0, i));

            if (i >= value.length) {
                window.clearInterval(timer);
                if (done) { done(); }
            }
        }, speed || 28);

        return function () { window.clearInterval(timer); };
    }

    /* ------------------------------------------------------------------ */
    /* Lightbox 3D Secure                                                  */
    /* ------------------------------------------------------------------ */

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) { node.className = className; }
        if (text !== undefined) { node.textContent = text; }

        return node;
    }

    function sprintf(pattern, values) {
        return pattern.replace(/%(\d)\$s/g, function (all, n) {
            return values[+n - 1] !== undefined ? values[+n - 1] : '';
        });
    }

    var SCHEMES = { visa: 'Visa Secure', mastercard: 'Mastercard ID Check', amex: 'American Express SafeKey' };

    function open3ds(opts) {
        opts = opts || {};

        return new Promise(function (resolve, reject) {
            var previousFocus = document.activeElement;
            var overlay = el('div', 'smbb-fc-3ds');
            var box = el('div', 'smbb-fc-3ds-box');
            var timers = [];
            var stopTyping = function () {};
            var closed = false;
            var forceFail = opts.scenario === '3ds_fail';

            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', '3D Secure');

            function later(fn, ms) {
                timers.push(window.setTimeout(fn, ms));
            }

            function close(outcome) {
                if (closed) { return; }
                closed = true;
                stopTyping();
                timers.forEach(window.clearTimeout);
                document.removeEventListener('keydown', onKey, true);
                overlay.classList.remove('is-open');
                overlay.classList.add('is-closing');
                window.setTimeout(function () {
                    overlay.remove();
                    if (previousFocus && previousFocus.focus) { previousFocus.focus(); }

                    if (outcome === 'ok') {
                        resolve('ok');
                    } else {
                        reject(outcome);
                    }
                }, 260);
            }

            function onKey(event) {
                if (event.key === 'Escape' && overlay.classList.contains('is-challenge')) {
                    event.preventDefault();
                    close('cancelled');
                }
            }

            // En-tête : banque + programme de la carte.
            var head = el('div', 'smbb-fc-3ds-head');
            var bank = el('span', 'smbb-fc-3ds-bank');
            bank.appendChild(el('span', 'smbb-fc-3ds-shield'));
            bank.appendChild(el('span', '', t('bank', 'Banque Démo')));
            head.appendChild(bank);
            head.appendChild(el('span', 'smbb-fc-3ds-scheme', SCHEMES[opts.brand] || '3D Secure'));
            box.appendChild(head);

            // Étape 1 : connexion.
            var connect = el('div', 'smbb-fc-3ds-step is-connect');
            connect.appendChild(el('span', 'smbb-fc-3ds-spinner'));
            connect.appendChild(el('p', '', t('connecting', 'Connexion sécurisée à votre banque…')));
            var bar = el('span', 'smbb-fc-3ds-progress');
            bar.appendChild(el('span'));
            connect.appendChild(bar);
            box.appendChild(connect);

            // Étape 2 : défi (code SMS).
            var challenge = el('div', 'smbb-fc-3ds-step is-challenge');
            challenge.appendChild(el('h3', '', t('confirmTitle', 'Confirmez votre paiement')));
            challenge.appendChild(el('p', 'smbb-fc-3ds-amount', opts.amount || ''));
            challenge.appendChild(el('p', '', sprintf(t('confirmText', 'Paiement de %1$s chez %2$s avec la carte •••• %3$s.'), [opts.amount || '', cfg.merchant || '', opts.last4 || ''])));
            challenge.appendChild(el('p', 'smbb-fc-3ds-muted', t('smsText', 'Saisissez le code reçu par SMS.')));
            var codeLabel = el('label', 'smbb-fc-3ds-code');
            codeLabel.appendChild(el('span', '', t('code', 'Code de vérification')));
            var code = el('input');
            code.type = 'text';
            code.inputMode = 'numeric';
            code.autocomplete = 'one-time-code';
            code.maxLength = 6;
            codeLabel.appendChild(code);
            challenge.appendChild(codeLabel);
            var actions = el('div', 'smbb-fc-3ds-actions');
            var confirm = el('button', 'smbb-fc-3ds-confirm', t('confirm', 'Valider'));
            confirm.type = 'button';
            var cancel = el('button', 'smbb-fc-3ds-cancel', t('cancel', 'Annuler'));
            cancel.type = 'button';
            actions.appendChild(confirm);
            actions.appendChild(cancel);
            challenge.appendChild(actions);
            var fail = el('button', 'smbb-fc-3ds-fail', t('simulateFail', 'Simuler un refus'));
            fail.type = 'button';
            challenge.appendChild(fail);
            box.appendChild(challenge);

            // Étape 3 : résultat.
            var result = el('div', 'smbb-fc-3ds-step is-result');
            var mark = el('span', 'smbb-fc-3ds-mark');
            mark.innerHTML = '<svg viewBox="0 0 52 52" aria-hidden="true"><circle cx="26" cy="26" r="24"/><path class="ok" d="M15 27l7 7 15-16"/><path class="ko" d="M18 18l16 16M34 18L18 34"/></svg>';
            result.appendChild(mark);
            var resultText = el('p', 'smbb-fc-3ds-result-text');
            result.appendChild(resultText);
            box.appendChild(result);

            box.appendChild(el('p', 'smbb-fc-3ds-test', t('testNote', 'Simulation CodeTool : aucune banque n\'est contactée.')));
            overlay.appendChild(box);
            document.body.appendChild(overlay);
            document.addEventListener('keydown', onKey, true);

            function step(name) {
                overlay.classList.remove('is-connect', 'is-challenge', 'is-result');
                overlay.classList.add('is-' + name);
            }

            function finish(ok) {
                step('result');
                overlay.classList.add(ok ? 'is-ok' : 'is-ko');
                resultText.textContent = ok ? t('success', 'Paiement authentifié') : t('failure', 'Authentification refusée par votre banque');
                later(function () { close(ok ? 'ok' : 'failed'); }, ok ? 1200 : 1700);
            }

            function submit(wantFail) {
                if (confirm.disabled) { return; }
                stopTyping();
                confirm.disabled = true;
                cancel.disabled = true;
                fail.disabled = true;
                confirm.classList.add('is-busy');
                confirm.textContent = t('checking', 'Vérification…');
                later(function () { finish(!(wantFail || forceFail)); }, 1000);
            }

            confirm.addEventListener('click', function () { submit(false); });
            fail.addEventListener('click', function () { submit(true); });
            cancel.addEventListener('click', function () { close('cancelled'); });
            code.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    submit(false);
                }
            });

            step('connect');
            window.requestAnimationFrame(function () { overlay.classList.add('is-open'); });

            later(function () {
                step('challenge');
                confirm.focus();
                // Le « SMS » arrive : le code se tape tout seul.
                var otp = String(100000 + rand(900000));
                later(function () {
                    stopTyping = typewrite(otp, function (part) { code.value = part; }, function () {
                        if (opts.auto) { later(function () { submit(false); }, 700); }
                    }, 90);
                }, 700);
            }, 1500);
        });
    }

    var api = {
        random: random,
        brandOf: brandOf,
        brandLabel: function (key) { return BRANDS[key] ? BRANDS[key].label : ''; },
        format: format,
        formatExpiry: formatExpiry,
        validate: validate,
        needs3ds: needs3ds,
        typewrite: typewrite,
        open3ds: open3ds,
        digits: digits,
        t: t,
        config: cfg
    };

    window.SmbbFakeCard = api;

    /* ------------------------------------------------------------------ */
    /* Tunnel classique (shortcode) et page « Payer la commande »          */
    /* ------------------------------------------------------------------ */

    var $ = window.jQuery;

    if (!$) {
        return;
    }

    var memo = null;     // valeurs saisies, gardées quand WooCommerce redessine le formulaire
    var passed = false;  // 3D Secure fait : on laisse partir la soumission suivante

    function field(root, name) {
        return root.querySelector('[data-fc="' + name + '"]');
    }

    function read(root) {
        return {
            number: field(root, 'number').value,
            expiry: field(root, 'expiry').value,
            cvc: field(root, 'cvc').value,
            scenario: field(root, 'scenario').value
        };
    }

    function showBrand(root) {
        var brand = brandOf(field(root, 'number').value);
        var badge = field(root, 'brand');
        badge.setAttribute('data-brand', brand);
        badge.textContent = brand === 'amex' ? 'AMEX' : (brand === 'mastercard' ? 'MC' : (brand === 'visa' ? 'VISA' : ''));
    }

    function remember(root) {
        memo = read(root);
    }

    function fill(root, card, animate) {
        var number = field(root, 'number');
        var expiry = field(root, 'expiry');
        var cvc = field(root, 'cvc');

        if (root._stop) { root._stop(); }

        if (!animate) {
            number.value = card.number;
            expiry.value = card.expiry;
            cvc.value = card.cvc;
            showBrand(root);
            remember(root);

            return;
        }

        number.value = '';
        expiry.value = '';
        cvc.value = '';
        root.classList.add('is-typing');
        root._stop = typewrite(card.number, function (part) {
            number.value = part;
            showBrand(root);
        }, function () {
            root._stop = typewrite(card.expiry, function (part) { expiry.value = part; }, function () {
                root._stop = typewrite(card.cvc, function (part) { cvc.value = part; }, function () {
                    root.classList.remove('is-typing');
                    remember(root);
                }, 60);
            });
        });
    }

    function init() {
        document.querySelectorAll('.smbb-fc[data-smbb-fc="classic"]').forEach(function (root) {
            if (root.getAttribute('data-ready')) { return; }
            root.setAttribute('data-ready', '1');

            if (memo) {
                fill(root, memo, false);
                field(root, 'scenario').value = memo.scenario || field(root, 'scenario').value;
            } else {
                fill(root, random(), true);
            }

            field(root, 'number').addEventListener('input', function () {
                this.value = format(this.value);
                showBrand(root);
                remember(root);
            });
            field(root, 'expiry').addEventListener('input', function () {
                this.value = formatExpiry(this.value);
                remember(root);
            });
            field(root, 'cvc').addEventListener('input', function () {
                this.value = digits(this.value).slice(0, 4);
                remember(root);
            });
            field(root, 'scenario').addEventListener('change', function () { remember(root); });
            field(root, 'shuffle').addEventListener('click', function () {
                fill(root, random(), true);
            });
        });
    }

    function showError($form, message) {
        $('.woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        var $group = $('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><ul class="woocommerce-error" role="alert"><li></li></ul></div>');
        $group.find('li').text(message);
        $form.prepend($group);
        $('html, body').animate({ scrollTop: Math.max(0, $form.offset().top - 100) }, 400);
    }

    function selected($form) {
        return $form.find('input[name="payment_method"]:checked').val() === 'smbb_fake_card';
    }

    function amountOf(root) {
        return root.getAttribute('data-amount') || '';
    }

    /**
     * Avant l'envoi : contrôle, puis lightbox 3D Secure, puis on renvoie le formulaire.
     * Retourne true pour laisser partir l'envoi, false pour le retenir.
     */
    function beforeSubmit($form, resubmit) {
        var root = $form.find('.smbb-fc').get(0);

        if (!root) { return true; }

        var threeds = field(root, 'threeds');

        if (passed) {
            passed = false;

            return true;
        }

        var data = read(root);
        var error = validate(data);

        if (error) {
            showError($form, error);

            return false;
        }

        if (!needs3ds(data.scenario)) {
            threeds.value = '';

            return true;
        }

        open3ds({
            amount: amountOf(root),
            last4: digits(data.number).slice(-4),
            brand: brandOf(data.number),
            scenario: data.scenario,
            auto: !!+cfg.auto3ds
        }).then(function () {
            threeds.value = 'ok';
            passed = true;
            resubmit();
        }, function (reason) {
            if (reason === 'failed') {
                // Refus 3DS : on laisse la commande passer en « échouée » côté serveur, comme en vrai.
                threeds.value = 'failed';
                passed = true;
                resubmit();
            } else {
                threeds.value = '';
                showError($form, t('cancelled', 'Authentification 3D Secure annulée.'));
            }
        });

        return false;
    }

    $(function () {
        init();
        $(document.body).on('updated_checkout payment_method_selected init_checkout', init);

        // Tunnel classique : WooCommerce appelle triggerHandler (sans propagation) sur le formulaire.
        var $checkout = $('form.checkout');

        $checkout.on('checkout_place_order_smbb_fake_card', function () {
            return beforeSubmit($checkout, function () { $checkout.trigger('submit'); });
        });

        // Page « Payer la commande » : envoi classique du formulaire.
        var payForm = document.getElementById('order_review');

        if (payForm && document.body.classList.contains('woocommerce-order-pay')) {
            payForm.addEventListener('submit', function (event) {
                var $pay = $(payForm);

                if (!selected($pay)) { return; }

                if (!beforeSubmit($pay, function () {
                    var button = payForm.querySelector('#place_order');

                    if (button) {
                        button.click();
                    } else {
                        HTMLFormElement.prototype.submit.call(payForm);
                    }
                })) {
                    event.preventDefault();
                    event.stopImmediatePropagation();
                }
            }, true);
        }
    });
})(window, document);
