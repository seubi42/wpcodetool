/**
 * Carte bancaire FICTIVE dans le bloc Commande de WooCommerce.
 * S'appuie sur window.SmbbFakeCard (fake-card.js) : numéros aléatoires, contrôles, lightbox 3D Secure.
 */
(function (wc, wp) {
    'use strict';

    if (!wc || !wc.wcBlocksRegistry || !wp || !wp.element || !window.SmbbFakeCard) {
        return;
    }

    var registry = wc.wcBlocksRegistry;
    var data = wc.wcSettings.getSetting('smbb_fake_card_data', {});
    var decode = wp.htmlEntities ? wp.htmlEntities.decodeEntities : function (s) { return s; };
    var h = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var Fake = window.SmbbFakeCard;
    var cfg = Fake.config || {};
    var t = Fake.t;
    var title = decode(data.title || 'Carte bancaire (TEST)');

    function amountLabel(billing) {
        try {
            var total = billing.cartTotal.value / Math.pow(10, billing.currency.minorUnit);

            return new Intl.NumberFormat(document.documentElement.lang || 'fr-FR', { style: 'currency', currency: billing.currency.code }).format(total);
        } catch (e) {
            return '';
        }
    }

    function badgeText(brand) {
        return brand === 'amex' ? 'AMEX' : (brand === 'mastercard' ? 'MC' : (brand === 'visa' ? 'VISA' : ''));
    }

    function Label() {
        return h('span', { className: 'smbb-fc-label' },
            h('span', null, title),
            h('span', { className: 'smbb-fc-badge is-test' }, 'TEST')
        );
    }

    function Content(props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var state = useState({ number: '', expiry: '', cvc: '' });
        var card = state[0];
        var setCard = state[1];
        var scenarioState = useState(cfg.scenario || '3ds');
        var scenario = scenarioState[0];
        var setScenario = scenarioState[1];
        var typingState = useState(false);
        var typing = typingState[0];
        var setTyping = typingState[1];
        var stopRef = useRef(function () {});
        var cardRef = useRef(card);
        cardRef.current = card;

        function shuffle() {
            var next = Fake.random();
            stopRef.current();
            setTyping(true);
            setCard({ number: '', expiry: '', cvc: '' });
            stopRef.current = Fake.typewrite(next.number, function (part) {
                setCard(function (c) { return { number: part, expiry: c.expiry, cvc: c.cvc }; });
            }, function () {
                stopRef.current = Fake.typewrite(next.expiry, function (part) {
                    setCard(function (c) { return { number: c.number, expiry: part, cvc: c.cvc }; });
                }, function () {
                    stopRef.current = Fake.typewrite(next.cvc, function (part) {
                        setCard(function (c) { return { number: c.number, expiry: c.expiry, cvc: part }; });
                    }, function () { setTyping(false); }, 60);
                });
            });
        }

        useEffect(function () {
            shuffle();

            return function () { stopRef.current(); };
        }, []);

        useEffect(function () {
            return eventRegistration.onPaymentSetup(function () {
                var current = cardRef.current;
                var values = { number: current.number, expiry: current.expiry, cvc: current.cvc, scenario: scenario };
                var error = Fake.validate(values);
                var payload = function (threeds) {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                smbb_fake_card_number: Fake.digits(values.number),
                                smbb_fake_card_expiry: values.expiry,
                                smbb_fake_card_cvc: Fake.digits(values.cvc),
                                smbb_fake_card_scenario: scenario,
                                smbb_fake_card_threeds: threeds
                            }
                        }
                    };
                };

                if (error) {
                    return { type: emitResponse.responseTypes.ERROR, message: error };
                }

                if (!Fake.needs3ds(scenario)) {
                    return payload('');
                }

                // Le bloc attend la promesse : la lightbox s'ouvre avant la création du paiement.
                return Fake.open3ds({
                    amount: amountLabel(props.billing),
                    last4: Fake.digits(values.number).slice(-4),
                    brand: Fake.brandOf(values.number),
                    scenario: scenario,
                    auto: !!+cfg.auto3ds
                }).then(function () {
                    return payload('ok');
                }, function (reason) {
                    if (reason === 'failed') {
                        return payload('failed');
                    }

                    return { type: emitResponse.responseTypes.ERROR, message: t('cancelled', 'Authentification 3D Secure annulée.') };
                });
            });
        }, [eventRegistration.onPaymentSetup, emitResponse.responseTypes, scenario, props.billing]);

        var brand = Fake.brandOf(card.number);
        var scenarios = cfg.scenarios || {};

        function input(key, label, placeholder, onChange, extra) {
            return h('div', { className: 'smbb-fc-field' },
                h('label', { htmlFor: 'smbb-fc-b-' + key }, label),
                h('div', { className: 'smbb-fc-input' + (extra ? ' has-brand' : '') },
                    h('input', {
                        id: 'smbb-fc-b-' + key,
                        type: 'text',
                        inputMode: 'numeric',
                        autoComplete: 'off',
                        placeholder: placeholder,
                        value: card[key],
                        onChange: function (event) {
                            stopRef.current();
                            setTyping(false);
                            var value = onChange(event.target.value);
                            setCard(function (c) {
                                var next = { number: c.number, expiry: c.expiry, cvc: c.cvc };
                                next[key] = value;

                                return next;
                            });
                        }
                    }),
                    extra || null
                )
            );
        }

        return h('div', { className: 'smbb-fc' + (typing ? ' is-typing' : '') },
            data.description ? h('p', { className: 'smbb-fc-description' }, decode(data.description)) : null,
            h('div', { className: 'smbb-fc-ribbon' }, t('ribbon', 'Mode test : aucune carte n\'est débitée')),
            input('number', t('number', 'Numéro de carte'), '1234 1234 1234 1234', Fake.format,
                h('span', { className: 'smbb-fc-brand', 'data-brand': brand, 'aria-hidden': 'true' }, badgeText(brand))),
            h('div', { className: 'smbb-fc-row' },
                input('expiry', t('expiry', 'Expiration'), 'MM / AA', Fake.formatExpiry),
                input('cvc', t('cvc', 'CVC'), '123', function (v) { return Fake.digits(v).slice(0, 4); })
            ),
            h('div', { className: 'smbb-fc-tools' },
                h('button', { type: 'button', className: 'smbb-fc-shuffle', onClick: shuffle },
                    h('span', { 'aria-hidden': 'true' }, '⟳'), ' ' + t('shuffle', 'Autre carte')),
                h('label', { className: 'smbb-fc-scenario' },
                    h('span', null, t('scenario', 'Scénario')),
                    h('select', { value: scenario, onChange: function (event) { setScenario(event.target.value); } },
                        Object.keys(scenarios).map(function (key) {
                            return h('option', { key: key, value: key }, scenarios[key]);
                        })
                    )
                )
            )
        );
    }

    registry.registerPaymentMethod({
        name: 'smbb_fake_card',
        label: h(Label),
        ariaLabel: title,
        content: h(Content),
        edit: h('div', { className: 'smbb-fc' }, h('div', { className: 'smbb-fc-ribbon' }, t('ribbon', 'Mode test : aucune carte n\'est débitée'))),
        canMakePayment: function () { return true; },
        supports: { features: data.supports || ['products'] }
    });
})(window.wc, window.wp);
