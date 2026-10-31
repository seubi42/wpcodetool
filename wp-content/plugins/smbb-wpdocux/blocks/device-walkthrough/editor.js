(function (blocks, blockEditor, components, data, element, i18n) {
    var el = element.createElement;
    var __ = i18n.__;
    var Fragment = element.Fragment;
    var useState = element.useState;
    var useEffect = element.useEffect;
    var useRef = element.useRef;
    var registerBlockType = blocks.registerBlockType;
    var getBlockType = blocks.getBlockType;
    var createBlock = blocks.createBlock;
    var InspectorControls = blockEditor.InspectorControls;
    var InnerBlocks = blockEditor.InnerBlocks;
    var MediaUpload = blockEditor.MediaUpload;
    var MediaUploadCheck = blockEditor.MediaUploadCheck;
    var useBlockProps = blockEditor.useBlockProps;
    var PanelBody = components.PanelBody;
    var SelectControl = components.SelectControl;
    var RangeControl = components.RangeControl;
    var TextControl = components.TextControl;
    var Button = components.Button;
    var ColorPalette = components.ColorPalette;
    var Dashicon = components.Dashicon;
    var Dropdown = components.Dropdown;
    var Placeholder = components.Placeholder;

    var ALLOWED_BLOCKS = ['smbb-wpdocux/device-step'];
    var TEMPLATE = [
        ['smbb-wpdocux/device-step', { title: __('Identification', 'smbb-wpdocux') }],
        ['smbb-wpdocux/device-step', { title: __('Motifs', 'smbb-wpdocux') }]
    ];
    var HOTSPOT_COLORS = [
        { name: 'Red', color: '#e11d48' },
        { name: 'Blue', color: '#2563eb' },
        { name: 'Green', color: '#16a34a' },
        { name: 'Amber', color: '#d97706' },
        { name: 'Purple', color: '#7c3aed' },
        { name: 'Black', color: '#111111' }
    ];

    function getStepLabel(step, index) {
        var attrs = step.attributes || {};
        return {
            number: String(index + 1).padStart(2, '0'),
            title: attrs.title || sprintfSafe(__('Step %d', 'smbb-wpdocux'), index + 1),
            imageUrl: attrs.imageUrl || '',
            imageAlt: attrs.imageAlt || '',
            hotspots: Array.isArray(attrs.hotspots) ? attrs.hotspots : []
        };
    }

    function sprintfSafe(text, value) {
        return String(text).replace('%d', value);
    }

    function clampPercent(value) {
        var number = parseFloat(value);

        if (Number.isNaN(number)) {
            return 50;
        }

        return Math.max(0, Math.min(100, Math.round(number * 10) / 10));
    }

    function renderIcon(icon, fallback) {
        return Dashicon ? el(Dashicon, { icon: icon }) : el('span', { className: 'smbb-wpdocux-device-step-editor__fallback-icon' }, fallback);
    }

    function renderHotspots(hotspots) {
        return (hotspots || []).map(function (hotspot, index) {
            var x = clampPercent(hotspot.x);
            var y = clampPercent(hotspot.y);
            var color = hotspot.color || '#e11d48';

            return el('span', {
                key: index,
                className: 'smbb-wpdocux-device__hotspot',
                style: {
                    '--hotspot-x': x + '%',
                    '--hotspot-y': y + '%',
                    '--hotspot-color': color
                }
            },
                el('span', { className: 'smbb-wpdocux-device__hotspot-dot' }, String(index + 1)),
                hotspot.tooltip ? el('span', { className: 'smbb-wpdocux-device__hotspot-tooltip' }, hotspot.tooltip) : null
            );
        });
    }

    function DevicePreview(props) {
        var steps = props.steps || [];
        var activeIndex = Math.min(props.activeIndex || 0, Math.max(steps.length - 1, 0));
        var activeStep = steps[activeIndex] ? getStepLabel(steps[activeIndex], activeIndex) : null;

        return el('div', { className: 'smbb-wpdocux-device-editor-preview' },
            el('div', { className: 'smbb-wpdocux-device__steps', role: 'tablist' },
                steps.length > 0 ? steps.map(function (step, index) {
                    var item = getStepLabel(step, index);
                    return el(Button, {
                        key: step.clientId || index,
                        className: 'smbb-wpdocux-device__step' + (index === activeIndex ? ' is-active' : ''),
                        'aria-label': item.number + ' ' + item.title,
                        onClick: function () {
                            props.onSelect(index);
                        }
                    },
                        el('span', { className: 'smbb-wpdocux-device__step-number' }, item.number),
                        el('span', { className: 'smbb-wpdocux-device__step-dot', 'aria-hidden': true }),
                        el('span', { className: 'smbb-wpdocux-device__step-title' }, item.title)
                    );
                }) : el('span', { className: 'smbb-wpdocux-device__notice' }, __('Add steps below to generate the buttons.', 'smbb-wpdocux'))
            ),
            el('div', { className: 'smbb-wpdocux-device__shell' },
                props.format === 'computer' ? el('div', { className: 'smbb-wpdocux-device__monitor-bar', 'aria-hidden': true },
                    el('span'),
                    el('span'),
                    el('span')
                ) : null,
                el('div', { className: 'smbb-wpdocux-device__screen' },
                    activeStep && activeStep.imageUrl
                        ? [
                            el('img', { key: 'image', src: activeStep.imageUrl, alt: activeStep.imageAlt }),
                            renderHotspots(activeStep.hotspots)
                        ]
                        : el('div', { className: 'smbb-wpdocux-device__empty' }, __('Choose a screenshot for this step.', 'smbb-wpdocux'))
                )
            ),
            props.format === 'computer' ? el('div', { className: 'smbb-wpdocux-device__stand', 'aria-hidden': true }, el('span')) : null
        );
    }

    if (!getBlockType('smbb-wpdocux/device-walkthrough')) {
        registerBlockType('smbb-wpdocux/device-walkthrough', {
            title: __('DocUX device', 'smbb-wpdocux'),
            description: __('Show numbered documentation steps inside a simulated phone or tablet.', 'smbb-wpdocux'),
            category: 'media',
            attributes: {
                format: {
                    type: 'string',
                    default: 'landscape'
                },
                transition: {
                    type: 'string',
                    default: 'fade'
                },
                indicatorStyle: {
                    type: 'string',
                    default: 'numbers'
                },
                buttonFontSize: {
                    type: 'number',
                    default: 14
                }
            },
            supports: {
                align: ['wide', 'full'],
                html: false
            },
            edit: function (props) {
                var attributes = props.attributes;
                var setAttributes = props.setAttributes;
                var clientId = props.clientId;
                var buttonFontSize = attributes.buttonFontSize || 14;
                var blockProps = useBlockProps({
                    className: 'smbb-wpdocux-device is-' + attributes.format + ' has-transition-' + attributes.transition + ' has-indicators-' + (attributes.indicatorStyle || 'numbers'),
                    style: {
                        '--smbb-wpdocux-step-font-size': buttonFontSize + 'px'
                    }
                });
                var steps = data.useSelect(function (select) {
                    return select('core/block-editor').getBlocks(clientId);
                }, [clientId]);
                var insertBlock = data.useDispatch('core/block-editor').insertBlock;
                var state = useState(0);
                var activeIndex = state[0];
                var setActiveIndex = state[1];

                useEffect(function () {
                    if (activeIndex > Math.max(steps.length - 1, 0)) {
                        setActiveIndex(Math.max(steps.length - 1, 0));
                    }
                }, [steps.length]);

                return el(Fragment, null,
                    el(InspectorControls, null,
                        el(PanelBody, { title: __('Device settings', 'smbb-wpdocux') },
                            el(SelectControl, {
                                label: __('Format', 'smbb-wpdocux'),
                                value: attributes.format,
                                options: [
                                    { label: __('Landscape tablet', 'smbb-wpdocux'), value: 'landscape' },
                                    { label: __('Portrait phone', 'smbb-wpdocux'), value: 'portrait' },
                                    { label: __('Computer', 'smbb-wpdocux'), value: 'computer' }
                                ],
                                onChange: function (value) {
                                    setAttributes({ format: value });
                                }
                            }),
                            el(SelectControl, {
                                label: __('Transition', 'smbb-wpdocux'),
                                value: attributes.transition,
                                options: [
                                    { label: __('Fade', 'smbb-wpdocux'), value: 'fade' },
                                    { label: __('Slide', 'smbb-wpdocux'), value: 'slide' },
                                    { label: __('Zoom', 'smbb-wpdocux'), value: 'zoom' },
                                    { label: __('None', 'smbb-wpdocux'), value: 'none' }
                                ],
                                onChange: function (value) {
                                    setAttributes({ transition: value });
                                }
                            }),
                            el(SelectControl, {
                                label: __('Step indicator', 'smbb-wpdocux'),
                                value: attributes.indicatorStyle || 'numbers',
                                options: [
                                    { label: __('Numbers', 'smbb-wpdocux'), value: 'numbers' },
                                    { label: __('Dots and check', 'smbb-wpdocux'), value: 'dots' }
                                ],
                                onChange: function (value) {
                                    setAttributes({ indicatorStyle: value });
                                }
                            }),
                            el(RangeControl, {
                                label: __('Button text size', 'smbb-wpdocux'),
                                value: buttonFontSize,
                                min: 11,
                                max: 20,
                                step: 1,
                                onChange: function (value) {
                                    setAttributes({ buttonFontSize: value || 14 });
                                }
                            })
                        )
                    ),
                    el('div', blockProps,
                        el(DevicePreview, {
                            steps: steps,
                            activeIndex: activeIndex,
                            format: attributes.format,
                            onSelect: setActiveIndex
                        }),
                        el('div', { className: 'smbb-wpdocux-device-editor-steps' },
                            el('div', { className: 'smbb-wpdocux-device-editor-steps__header' },
                                el('div', null,
                                    el('h3', null, __('Steps', 'smbb-wpdocux')),
                                    el('p', null, sprintfSafe(__('%d step(s)', 'smbb-wpdocux'), steps.length))
                                ),
                                el(Button, {
                                    className: 'smbb-wpdocux-device-editor-steps__add smbb-wpdocux-icon-button',
                                    label: __('Add step', 'smbb-wpdocux'),
                                    onClick: function () {
                                        insertBlock(createBlock('smbb-wpdocux/device-step', {
                                            title: sprintfSafe(__('Step %d', 'smbb-wpdocux'), steps.length + 1)
                                        }), steps.length, clientId);
                                        setActiveIndex(steps.length);
                                    }
                                }, renderIcon('plus-alt2', '+'))
                            ),
                            el('div', { className: 'smbb-wpdocux-device-editor-steps__blocks' },
                                el(InnerBlocks, {
                                    allowedBlocks: ALLOWED_BLOCKS,
                                    template: TEMPLATE,
                                    orientation: 'vertical',
                                    renderAppender: false
                                })
                            )
                        )
                    )
                );
            },
            save: function () {
                return el(InnerBlocks.Content);
            }
        });
    }

    if (!getBlockType('smbb-wpdocux/device-step')) {
        registerBlockType('smbb-wpdocux/device-step', {
            title: __('DocUX etape', 'smbb-wpdocux'),
            description: __('A numbered screenshot step for the DocUX device block.', 'smbb-wpdocux'),
            category: 'media',
            parent: ['smbb-wpdocux/device-walkthrough'],
            attributes: {
                title: {
                    type: 'string',
                    default: ''
                },
                imageId: {
                    type: 'number'
                },
                imageUrl: {
                    type: 'string',
                    default: ''
                },
                imageAlt: {
                    type: 'string',
                    default: ''
                },
                hotspots: {
                    type: 'array',
                    default: []
                }
            },
            supports: {
                html: false,
                reusable: false
            },
            edit: function (props) {
                var attributes = props.attributes;
                var setAttributes = props.setAttributes;
                var collapsedState = useState(true);
                var isCollapsed = collapsedState[0];
                var setIsCollapsed = collapsedState[1];
                var dragState = useState(null);
                var draggingHotspotIndex = dragState[0];
                var setDraggingHotspotIndex = dragState[1];
                var canvasRef = useRef(null);
                var suppressCanvasClickRef = useRef(false);
                var blockProps = useBlockProps({
                    className: 'smbb-wpdocux-device-step-editor' + (isCollapsed ? ' is-collapsed' : ' is-expanded')
                });

                useEffect(function () {
                    if (isCollapsed || !attributes.imageUrl) {
                        setDraggingHotspotIndex(null);
                    }
                }, [isCollapsed, attributes.imageUrl]);

                function onSelectImage(media) {
                    if (!media || !media.url) {
                        return;
                    }

                    setAttributes({
                        imageId: media.id,
                        imageUrl: media.url,
                        imageAlt: media.alt || media.title || ''
                    });
                }

                function getHotspots() {
                    return Array.isArray(attributes.hotspots) ? attributes.hotspots : [];
                }

                function setHotspot(index, patch) {
                    setAttributes({
                        hotspots: getHotspots().map(function (hotspot, currentIndex) {
                            return currentIndex === index ? Object.assign({}, hotspot, patch) : hotspot;
                        })
                    });
                }

                function removeHotspot(index) {
                    setAttributes({
                        hotspots: getHotspots().filter(function (hotspot, currentIndex) {
                            return currentIndex !== index;
                        })
                    });
                }

                function moveHotspot(index, direction) {
                    var hotspots = getHotspots().slice();
                    var nextIndex = index + direction;
                    var current;

                    if (nextIndex < 0 || nextIndex >= hotspots.length) {
                        return;
                    }

                    current = hotspots[index];
                    hotspots[index] = hotspots[nextIndex];
                    hotspots[nextIndex] = current;
                    setAttributes({ hotspots: hotspots });
                }

                function updateHotspotPosition(index, event) {
                    var canvas = canvasRef.current;
                    var bounds;
                    var x;
                    var y;

                    if (!canvas) {
                        return;
                    }

                    bounds = canvas.getBoundingClientRect();
                    x = clampPercent(((event.clientX - bounds.left) / bounds.width) * 100);
                    y = clampPercent(((event.clientY - bounds.top) / bounds.height) * 100);
                    setHotspot(index, { x: x, y: y });
                }

                function releaseHotspotPointer(event) {
                    if (canvasRef.current && canvasRef.current.releasePointerCapture && event.pointerId !== undefined) {
                        try {
                            canvasRef.current.releasePointerCapture(event.pointerId);
                        } catch (error) {
                            return;
                        }
                    }
                }

                function startHotspotDrag(index, event) {
                    event.preventDefault();
                    event.stopPropagation();
                    suppressCanvasClickRef.current = true;
                    setDraggingHotspotIndex(index);
                    updateHotspotPosition(index, event);

                    if (canvasRef.current && canvasRef.current.setPointerCapture && event.pointerId !== undefined) {
                        try {
                            canvasRef.current.setPointerCapture(event.pointerId);
                        } catch (error) {
                            return;
                        }
                    }
                }

                function dragHotspot(event) {
                    if (draggingHotspotIndex === null) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();
                    updateHotspotPosition(draggingHotspotIndex, event);
                }

                function stopHotspotDrag(event) {
                    if (draggingHotspotIndex === null) {
                        return;
                    }

                    event.preventDefault();
                    event.stopPropagation();
                    setDraggingHotspotIndex(null);
                    window.setTimeout(function () {
                        suppressCanvasClickRef.current = false;
                    }, 0);

                    releaseHotspotPointer(event);
                }

                function addHotspot(event) {
                    if (suppressCanvasClickRef.current) {
                        suppressCanvasClickRef.current = false;
                        return;
                    }

                    var bounds = event.currentTarget.getBoundingClientRect();
                    var x = clampPercent(((event.clientX - bounds.left) / bounds.width) * 100);
                    var y = clampPercent(((event.clientY - bounds.top) / bounds.height) * 100);

                    setAttributes({
                        hotspots: getHotspots().concat([{
                            x: x,
                            y: y,
                            color: '#e11d48',
                            tooltip: __('Hotspot', 'smbb-wpdocux')
                        }])
                    });
                }

                function renderEditableHotspots(hotspots) {
                    return (hotspots || []).map(function (hotspot, index) {
                        var x = clampPercent(hotspot.x);
                        var y = clampPercent(hotspot.y);
                        var color = hotspot.color || '#e11d48';
                        var isDragging = draggingHotspotIndex === index;

                        return el('span', {
                            key: index,
                            className: 'smbb-wpdocux-device__hotspot smbb-wpdocux-device-step-editor__editable-hotspot' + (isDragging ? ' is-dragging' : ''),
                            style: {
                                '--hotspot-x': x + '%',
                                '--hotspot-y': y + '%',
                                '--hotspot-color': color
                            },
                            onPointerDown: function (event) {
                                startHotspotDrag(index, event);
                            },
                            onClick: function (event) {
                                event.preventDefault();
                                event.stopPropagation();
                                suppressCanvasClickRef.current = false;
                            }
                        },
                            el('span', { className: 'smbb-wpdocux-device__hotspot-dot' }, String(index + 1)),
                            hotspot.tooltip ? el('span', { className: 'smbb-wpdocux-device__hotspot-tooltip' }, hotspot.tooltip) : null
                        );
                    });
                }

                return el('div', blockProps,
                    el('div', { className: 'smbb-wpdocux-device-step-editor__header' },
                        attributes.imageUrl
                            ? el('img', { className: 'smbb-wpdocux-device-step-editor__thumb', src: attributes.imageUrl, alt: '' })
                            : el('span', { className: 'smbb-wpdocux-device-step-editor__thumb is-empty', 'aria-hidden': true }),
                        el('div', { className: 'smbb-wpdocux-device-step-editor__summary' },
                            el('strong', null, attributes.title || __('Untitled step', 'smbb-wpdocux')),
                            el('span', null, attributes.imageUrl ? __('Image selected', 'smbb-wpdocux') : __('No image selected', 'smbb-wpdocux'))
                        ),
                        el(Button, {
                            className: 'smbb-wpdocux-device-step-editor__toggle smbb-wpdocux-icon-button' + (!isCollapsed ? ' is-active' : ''),
                            label: isCollapsed ? __('Edit', 'smbb-wpdocux') : __('Done', 'smbb-wpdocux'),
                            onClick: function () {
                                setIsCollapsed(!isCollapsed);
                            }
                        }, renderIcon(isCollapsed ? 'edit' : 'yes-alt', isCollapsed ? 'e' : 'ok'))
                    ),
                    !isCollapsed ? el('div', { className: 'smbb-wpdocux-device-step-editor__body' },
                        el(TextControl, {
                            label: __('Name', 'smbb-wpdocux'),
                            value: attributes.title || '',
                            placeholder: __('Identification', 'smbb-wpdocux'),
                            onChange: function (value) {
                                setAttributes({ title: value });
                            }
                        }),
                        el(MediaUploadCheck, null,
                            el(MediaUpload, {
                                onSelect: onSelectImage,
                                allowedTypes: ['image'],
                                value: attributes.imageId,
                                render: function (renderProps) {
                                    return el('div', { className: 'smbb-wpdocux-device-step-editor__media' },
                                        attributes.imageUrl
                                            ? el('button', {
                                                type: 'button',
                                                ref: canvasRef,
                                                className: 'smbb-wpdocux-device-step-editor__hotspot-canvas',
                                                onClick: addHotspot,
                                                onPointerMove: dragHotspot,
                                                onPointerUp: stopHotspotDrag,
                                                onPointerCancel: stopHotspotDrag
                                            },
                                                el('img', { src: attributes.imageUrl, alt: attributes.imageAlt || '' }),
                                                renderEditableHotspots(getHotspots())
                                            )
                                            : el(Placeholder, {
                                                label: __('Screenshot', 'smbb-wpdocux')
                                            }, __('No image selected.', 'smbb-wpdocux')),
                                        el('div', { className: 'smbb-wpdocux-device-step-editor__actions' },
                                            el(Button, { variant: 'primary', onClick: renderProps.open }, attributes.imageUrl ? __('Replace image', 'smbb-wpdocux') : __('Choose image', 'smbb-wpdocux')),
                                            attributes.imageUrl ? el(Button, {
                                                variant: 'secondary',
                                                onClick: function () {
                                                    setAttributes({ imageId: undefined, imageUrl: '', imageAlt: '' });
                                                }
                                            }, __('Remove', 'smbb-wpdocux')) : null
                                        )
                                    );
                                }
                            })
                        ),
                        attributes.imageUrl ? el('div', { className: 'smbb-wpdocux-device-step-editor__hotspots' },
                            el('div', { className: 'smbb-wpdocux-device-step-editor__hotspots-title' },
                                el('strong', null, __('Hotspots', 'smbb-wpdocux')),
                                el('span', null, __('Click the screenshot to add one.', 'smbb-wpdocux'))
                            ),
                            getHotspots().length > 0 ? getHotspots().map(function (hotspot, index) {
                                return el('div', { className: 'smbb-wpdocux-device-step-editor__hotspot-row', key: index },
                                    el('div', { className: 'smbb-wpdocux-device-step-editor__hotspot-row-header' },
                                        el('strong', null, sprintfSafe(__('Hotspot %d', 'smbb-wpdocux'), index + 1)),
                                        el('div', { className: 'smbb-wpdocux-device-step-editor__hotspot-actions' },
                                            el(Button, {
                                                className: 'smbb-wpdocux-device-step-editor__hotspot-action smbb-wpdocux-icon-button',
                                                disabled: index === 0,
                                                label: __('Move up', 'smbb-wpdocux'),
                                                onClick: function () {
                                                    moveHotspot(index, -1);
                                                }
                                            }, renderIcon('arrow-up-alt2', '^')),
                                            el(Button, {
                                                className: 'smbb-wpdocux-device-step-editor__hotspot-action smbb-wpdocux-icon-button',
                                                disabled: index === getHotspots().length - 1,
                                                label: __('Move down', 'smbb-wpdocux'),
                                                onClick: function () {
                                                    moveHotspot(index, 1);
                                                }
                                            }, renderIcon('arrow-down-alt2', 'v')),
                                            el(Button, {
                                                className: 'smbb-wpdocux-device-step-editor__hotspot-action smbb-wpdocux-icon-button is-danger',
                                                label: __('Remove', 'smbb-wpdocux'),
                                                onClick: function () {
                                                    removeHotspot(index);
                                                }
                                            }, renderIcon('trash', 'x'))
                                        )
                                    ),
                                    el('div', { className: 'smbb-wpdocux-device-step-editor__hotspot-fields' },
                                        el(TextControl, {
                                            label: __('Tooltip', 'smbb-wpdocux'),
                                            value: hotspot.tooltip || '',
                                            onChange: function (value) {
                                                setHotspot(index, { tooltip: value });
                                            }
                                        }),
                                        el(TextControl, {
                                            label: 'X',
                                            type: 'number',
                                            value: clampPercent(hotspot.x),
                                            min: 0,
                                            max: 100,
                                            step: 0.5,
                                            onChange: function (value) {
                                                setHotspot(index, { x: clampPercent(value) });
                                            }
                                        }),
                                        el(TextControl, {
                                            label: 'Y',
                                            type: 'number',
                                            value: clampPercent(hotspot.y),
                                            min: 0,
                                            max: 100,
                                            step: 0.5,
                                            onChange: function (value) {
                                                setHotspot(index, { y: clampPercent(value) });
                                            }
                                        }),
                                        el('div', { className: 'smbb-wpdocux-device-step-editor__color-field' },
                                            el('span', { className: 'smbb-wpdocux-device-step-editor__mini-label' }, __('Color', 'smbb-wpdocux')),
                                            el(Dropdown, {
                                                className: 'smbb-wpdocux-device-step-editor__color-dropdown',
                                                contentClassName: 'smbb-wpdocux-device-step-editor__color-popover',
                                                renderToggle: function (toggleProps) {
                                                    return el(Button, {
                                                        className: 'smbb-wpdocux-device-step-editor__color-toggle',
                                                        onClick: toggleProps.onToggle,
                                                        'aria-expanded': toggleProps.isOpen,
                                                        label: __('Choose color', 'smbb-wpdocux')
                                                    },
                                                        el('span', {
                                                            className: 'smbb-wpdocux-device-step-editor__color-swatch',
                                                            style: { backgroundColor: hotspot.color || '#e11d48' }
                                                        }),
                                                        el('span', null, hotspot.color || '#e11d48')
                                                    );
                                                },
                                                renderContent: function () {
                                                    return el('div', { className: 'smbb-wpdocux-device-step-editor__color-menu' },
                                                        el(ColorPalette, {
                                                            colors: HOTSPOT_COLORS,
                                                            value: hotspot.color || '#e11d48',
                                                            disableCustomColors: false,
                                                            onChange: function (value) {
                                                                setHotspot(index, { color: value || '#e11d48' });
                                                            }
                                                        }),
                                                        el(TextControl, {
                                                            label: __('Custom', 'smbb-wpdocux'),
                                                            value: hotspot.color || '#e11d48',
                                                            onChange: function (value) {
                                                                setHotspot(index, { color: value || '#e11d48' });
                                                            }
                                                        })
                                                    );
                                                }
                                            })
                                        )
                                    )
                                );
                            }) : el('p', { className: 'smbb-wpdocux-device-step-editor__empty-note' }, __('No hotspot yet.', 'smbb-wpdocux'))
                        ) : null
                    ) : null
                );
            },
            save: function () {
                return null;
            }
        });
    }
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.data, window.wp.element, window.wp.i18n);
