/*
 * Bloc « Formulaire » (smbb/form) : choisir un formulaire CodeTool et le voir dans l'éditeur.
 * Pas d'étape de build : wp.element.createElement directement.
 */
(function (wp) {
    'use strict';

    if (!wp || !wp.blocks || !wp.element) {
        return;
    }

    var el = wp.element.createElement;
    var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (text) { return text; };
    var be = wp.blockEditor || wp.editor;
    var c = wp.components;
    var ServerSideRender = wp.serverSideRender;
    var data = window.SmbbFormsBlock || { forms: [], newUrl: '', editUrl: '' };

    var icon = el('svg', { width: 24, height: 24, viewBox: '0 0 24 24', xmlns: 'http://www.w3.org/2000/svg' },
        el('path', { d: 'M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm0 2v14h14V5H5Zm2 2h10v2H7V7Zm0 4h10v2H7v-2Zm0 4h6v2H7v-2Z', fill: 'currentColor' })
    );

    function options() {
        return [{ value: 0, label: __('Choisir un formulaire…', 'smbb-wpcodetool') }].concat((data.forms || []).map(function (form) {
            return { value: Number(form.value), label: form.label };
        }));
    }

    function picker(props) {
        return el(c.SelectControl, {
            label: __('Formulaire', 'smbb-wpcodetool'),
            value: props.attributes.formId || 0,
            options: options(),
            onChange: function (value) {
                props.setAttributes({ formId: parseInt(value, 10) || 0 });
            },
            __nextHasNoMarginBottom: true
        });
    }

    wp.blocks.registerBlockType('smbb/form', {
        apiVersion: 2,
        title: __('Formulaire', 'smbb-wpcodetool'),
        description: __('Un formulaire de CodeTool › Formulaires (contact, devis…).', 'smbb-wpcodetool'),
        category: 'widgets',
        icon: icon,
        keywords: [__('contact', 'smbb-wpcodetool'), __('formulaire', 'smbb-wpcodetool'), 'form', 'wpforms'],
        supports: { html: false },
        attributes: {
            formId: { type: 'number', default: 0 },
            className: { type: 'string', default: '' }
        },

        edit: function (props) {
            var blockProps = be && be.useBlockProps ? be.useBlockProps() : {};
            var id = props.attributes.formId || 0;
            var side = el(be.InspectorControls, {},
                el(c.PanelBody, { title: __('Formulaire', 'smbb-wpcodetool'), initialOpen: true },
                    picker(props),
                    id
                        ? el('p', { style: { marginTop: '12px' } }, el(c.ExternalLink, { href: data.editUrl + id }, __('Modifier ce formulaire', 'smbb-wpcodetool')))
                        : null,
                    el('p', { style: { marginTop: '8px' } }, el(c.ExternalLink, { href: data.newUrl }, __('Créer un formulaire', 'smbb-wpcodetool')))
                )
            );

            if (!id) {
                return el('div', blockProps, side,
                    el(c.Placeholder, {
                        icon: icon,
                        label: __('Formulaire', 'smbb-wpcodetool'),
                        instructions: (data.forms || []).length
                            ? __('Choisissez le formulaire à afficher.', 'smbb-wpcodetool')
                            : __('Aucun formulaire pour l\'instant : créez-en un dans Formulaires.', 'smbb-wpcodetool')
                    },
                        (data.forms || []).length
                            ? picker(props)
                            : el(c.Button, { variant: 'primary', href: data.newUrl, target: '_blank' }, __('Créer un formulaire', 'smbb-wpcodetool'))
                    )
                );
            }

            return el('div', blockProps, side,
                el(c.Disabled || 'div', {},
                    ServerSideRender
                        ? el(ServerSideRender, { block: 'smbb/form', attributes: { formId: id, className: props.attributes.className || '' } })
                        : el('p', {}, __('Formulaire n° ', 'smbb-wpcodetool') + id)
                )
            );
        },

        save: function () {
            return null;
        }
    });
})(window.wp);
