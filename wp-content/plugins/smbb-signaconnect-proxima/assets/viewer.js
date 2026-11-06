/* SignaConnect : affichage des pages PDF (pdf.js chargé à la demande). */
(function () {
    'use strict';

    var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs';
    var WORKER = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.worker.min.mjs';
    var loader = null;

    function pdfjs() {
        if (!loader) {
            loader = import(PDFJS).then(function (lib) {
                lib.GlobalWorkerOptions.workerSrc = WORKER;
                return lib;
            });
        }

        return loader;
    }

    /**
     * Affiche toutes les pages dans container. Chaque page est un <div class="scp-page">
     * (position relative) qui peut accueillir des zones positionnées en %.
     * Résout avec la liste des éléments de page.
     */
    function render(container, url, label) {
        container.innerHTML = '<div class="scp-loading"><span class="scp-spinner"></span></div>';

        return pdfjs().then(function (lib) {
            return lib.getDocument({ url: url, withCredentials: true }).promise;
        }).then(function (pdf) {
            container.innerHTML = '';
            var pages = [];
            var chain = Promise.resolve();
            var width = Math.max(280, container.clientWidth || 800);
            var ratio = Math.min(window.devicePixelRatio || 1, 2);

            for (var n = 1; n <= pdf.numPages; n += 1) {
                (function (number) {
                    chain = chain.then(function () {
                        return pdf.getPage(number);
                    }).then(function (page) {
                        var base = page.getViewport({ scale: 1 });
                        var viewport = page.getViewport({ scale: (width / base.width) * ratio });
                        var wrap = document.createElement('div');
                        var canvas = document.createElement('canvas');
                        var tag = document.createElement('span');

                        wrap.className = 'scp-page';
                        wrap.dataset.page = String(number);
                        wrap.style.aspectRatio = base.width + ' / ' + base.height;
                        canvas.width = Math.floor(viewport.width);
                        canvas.height = Math.floor(viewport.height);
                        tag.className = 'scp-page-number';
                        tag.textContent = (label || 'Page') + ' ' + number + ' / ' + pdf.numPages;
                        wrap.scpPdfPage = page;
                        wrap.dataset.pw = String(base.width);
                        wrap.dataset.ph = String(base.height);
                        wrap.appendChild(canvas);
                        wrap.appendChild(tag);
                        container.appendChild(wrap);
                        pages.push(wrap);

                        return page.render({ canvasContext: canvas.getContext('2d'), viewport: viewport }).promise;
                    });
                })(n);
            }

            return chain.then(function () {
                pages.numPages = pdf.numPages;
                return pages;
            });
        });
    }

    function post(url, data) {
        return fetch(url, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, data: { message: '' } };
            });
        }).then(function (json) {
            if (!json || !json.success) {
                var error = new Error(json && json.data && json.data.message ? json.data.message : '');
                throw error;
            }

            return json.data;
        });
    }

    function showMessage(element, text, tone) {
        if (!element) {
            return;
        }

        element.hidden = !text;
        element.className = 'scp-alert' + (tone ? ' scp-alert--' + tone : '');
        element.textContent = text || '';
    }

    window.ScpViewer = { render: render, post: post, showMessage: showMessage };
})();
