/* SignaConnect : onglet « Modèles » de l'app (import du PDF d'exemple, suppression). */
(function () {
    'use strict';

    var V = window.ScpViewer;

    document.querySelectorAll('[data-scp-tpl-form]').forEach(function (form) {
        var file = form.querySelector('[data-scp-tpl-file]');
        var label = form.querySelector('[data-scp-tpl-file-label]');
        var name = form.querySelector('[data-scp-tpl-file-name]');
        var hidden = form.querySelector('[name="sample_pdf_attachment_id"]');
        var del = form.querySelector('[data-scp-tpl-delete]');
        var idle = label.textContent;

        file.addEventListener('change', function () {
            if (!file.files || !file.files[0]) {
                return;
            }

            var body = new FormData();
            body.append('action', 'scp_app_templatesample');
            body.append('_wpnonce', form.getAttribute('data-nonce'));
            body.append('organization_id', form.querySelector('[name="organization_id"]').value);
            body.append('file', file.files[0]);
            label.textContent = '…';
            label.parentNode.classList.add('is-busy');

            V.post(form.getAttribute('data-ajax'), body).then(function (data) {
                /* L'éditeur surveille ce champ et affiche le nouveau PDF. */
                hidden.value = String(data.id);
                name.textContent = data.name;
                idle = label.getAttribute('data-change') || idle;
            }).catch(function (error) {
                name.textContent = error.message || '';
            }).then(function () {
                label.textContent = idle;
                label.parentNode.classList.remove('is-busy');
                file.value = '';
            });
        });

        if (del) {
            del.addEventListener('click', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        }
    });
})();

/* 0.10.0 : champs de l'email du document signé, visibles seulement si la case est cochée. */
(function () {
    'use strict';

    document.querySelectorAll('[data-scp-tpl-mail]').forEach(function (box) {
        var toggle = box.querySelector('[data-scp-tpl-mail-toggle]');
        var fields = box.querySelector('[data-scp-tpl-mail-fields]');

        toggle.addEventListener('change', function () {
            fields.hidden = !toggle.checked;
        });
    });
})();

/* 0.11.0 : champs perso du modèle (N° de commande…), enregistrés en JSON dans custom_fields. */
(function () {
    'use strict';

    document.querySelectorAll('[data-scp-tpl-custom]').forEach(function (box) {
        var store = box.querySelector('[name="custom_fields"]');
        var list = box.querySelector('[data-scp-tpl-custom-list]');
        var add = box.querySelector('[data-scp-tpl-custom-add]');
        var form = box.closest('form') || document;
        var vars = form.querySelectorAll('[data-scp-tpl-custom-vars]');
        var max = parseInt(box.getAttribute('data-max'), 10) || 6;
        var fields = [];

        try {
            fields = JSON.parse(store.value || '[]') || [];
        } catch (error) {
            fields = [];
        }

        function save() {
            var clean = fields.filter(function (field) {
                return field.label.trim() !== '';
            }).map(function (field) {
                return { key: field.key, label: field.label.trim().replace(/[\[\]]/g, ''), required: !!field.required };
            });
            store.value = JSON.stringify(clean);
            add.hidden = fields.length >= max;

            Array.prototype.forEach.call(vars, function (target) {
                target.innerHTML = '';
                clean.forEach(function (field) {
                    var code = document.createElement('code');
                    code.textContent = '[' + field.label + ']';
                    target.appendChild(code);
                    target.appendChild(document.createTextNode(' '));
                });
            });

            /* L'éditeur de zones propose ces champs comme cibles des zones Destinataire. */
            document.dispatchEvent(new CustomEvent('scp-custom-fields', { detail: clean }));
        }

        function render() {
            list.innerHTML = '';
            fields.forEach(function (field, index) {
                var row = document.createElement('div');
                row.className = 'scp-tpl-custom-row';
                row.innerHTML = '<input type="text" maxlength="60"><label class="scp-tpl-custom-req"><input type="checkbox"><span></span></label><button type="button" class="scp-tpl-custom-remove"></button>';
                var label = row.querySelector('input[type="text"]');
                var required = row.querySelector('input[type="checkbox"]');
                var remove = row.querySelector('button');
                label.value = field.label;
                label.placeholder = box.getAttribute('data-label-placeholder') || '';
                required.checked = !!field.required;
                row.querySelector('span').textContent = box.getAttribute('data-required') || '';
                remove.textContent = '×';
                remove.setAttribute('aria-label', box.getAttribute('data-remove') || '');
                remove.title = box.getAttribute('data-remove') || '';
                label.addEventListener('input', function () {
                    field.label = label.value;
                    save();
                });
                required.addEventListener('change', function () {
                    field.required = required.checked;
                    save();
                });
                remove.addEventListener('click', function () {
                    fields.splice(index, 1);
                    render();
                });
                list.appendChild(row);
            });
            save();
        }

        add.addEventListener('click', function () {
            if (fields.length >= max) {
                return;
            }

            fields.push({ key: 'c' + Date.now().toString(36), label: '', required: false });
            render();
            var inputs = list.querySelectorAll('input[type="text"]');
            inputs[inputs.length - 1].focus();
        });

        render();
    });
})();
