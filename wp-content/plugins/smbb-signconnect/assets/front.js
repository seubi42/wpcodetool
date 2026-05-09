(function () {
    function showMessage(container, type, message) {
        if (!container) {
            return;
        }

        container.innerHTML = '<div class="smbb-signconnect-notice is-' + type + '"><p>' + escapeHtml(message) + '</p></div>';
    }

    function configText(key) {
        return window.SmbbSignConnect && SmbbSignConnect.i18n ? SmbbSignConnect.i18n[key] : '';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    var pdfJsPromise = null;

    document.querySelectorAll('[data-signconnect-public-pdf]').forEach(initPublicPdfViewer);

    function loadPdfJs() {
        if (!pdfJsPromise) {
            pdfJsPromise = import('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs').then(function (pdfjsLib) {
                pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.worker.min.mjs';
                return pdfjsLib;
            });
        }

        return pdfJsPromise;
    }

    function initPublicPdfViewer(viewer) {
        var url = viewer.dataset.pdfUrl || '';
        var pages = viewer.querySelector('[data-signconnect-public-pdf-pages]');
        var status = viewer.querySelector('[data-signconnect-public-pdf-status]');
        var fields = parsePublicSignatureFields(viewer.dataset.signatureFields || '[]');

        if (!url || !pages) {
            return;
        }

        // Le PDF reste servi par WordPress avec le token public : aucune URL S3 ni credential ne part au navigateur.
        loadPdfJs()
            .then(function (pdfjsLib) {
                return pdfjsLib.getDocument({ url: url, withCredentials: true }).promise;
            })
            .then(function (pdf) {
                var sequence = Promise.resolve();

                if (status) {
                    status.hidden = true;
                }

                for (var pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
                    sequence = sequence.then(renderPublicPdfPage.bind(null, pdf, pages, pageNumber, fields));
                }

                return sequence;
            })
            .catch(function () {
                if (status) {
                    status.hidden = false;
                    status.innerHTML = '<span>' + escapeHtml(configText('publicPdfError') || 'The PDF cannot be displayed. You can still download it after signing.') + '</span>';
                }
            });
    }

    function parsePublicSignatureFields(raw) {
        try {
            var fields = JSON.parse(raw);

            return Array.isArray(fields) ? fields : [];
        } catch (error) {
            return [];
        }
    }

    function renderPublicPdfPage(pdf, container, pageNumber, fields) {
        return pdf.getPage(pageNumber).then(function (page) {
            var baseViewport = page.getViewport({ scale: 1 });
            var maxWidth = Math.min(Math.max((container.clientWidth || 900) - 32, 240), 960);
            var displayScale = Math.max(0.2, maxWidth / baseViewport.width);
            var outputScale = window.devicePixelRatio || 1;
            var viewport = page.getViewport({ scale: displayScale });
            var canvas = document.createElement('canvas');
            var context = canvas.getContext('2d');
            var figure = document.createElement('figure');
            var stage = document.createElement('div');
            var caption = document.createElement('figcaption');

            canvas.width = Math.floor(viewport.width * outputScale);
            canvas.height = Math.floor(viewport.height * outputScale);
            canvas.style.width = Math.floor(viewport.width) + 'px';
            canvas.style.height = Math.floor(viewport.height) + 'px';

            context.setTransform(outputScale, 0, 0, outputScale, 0, 0);
            figure.className = 'smbb-signconnect-public-pdf-page';
            stage.className = 'smbb-signconnect-public-pdf-stage';
            stage.style.width = Math.floor(viewport.width) + 'px';
            stage.style.height = Math.floor(viewport.height) + 'px';
            caption.textContent = 'Page ' + pageNumber + ' / ' + pdf.numPages;

            stage.appendChild(canvas);
            addPublicSignatureFieldOverlays(stage, fields, pageNumber);
            figure.appendChild(stage);
            figure.appendChild(caption);
            container.appendChild(figure);

            return page.render({ canvasContext: context, viewport: viewport }).promise;
        });
    }

    function addPublicSignatureFieldOverlays(stage, fields, pageNumber) {
        fields
            .filter(function (field) {
                return Number(field.page_number) === pageNumber;
            })
            .forEach(function (field, index) {
                var overlay = document.createElement('button');
                var label = configText('signatureZoneLabel') || 'Your signature will appear here';

                overlay.type = 'button';
                overlay.className = 'smbb-signconnect-public-signature-zone';
                overlay.style.left = (Number(field.x) * 100) + '%';
                overlay.style.top = (Number(field.y) * 100) + '%';
                overlay.style.width = (Number(field.width) * 100) + '%';
                overlay.style.height = (Number(field.height) * 100) + '%';
                overlay.textContent = label;
                overlay.setAttribute('aria-label', label + ' - go to the signature');
                overlay.addEventListener('click', scrollToPublicSignaturePad);

                stage.appendChild(overlay);
            });
    }

    function scrollToPublicSignaturePad() {
        var target = document.querySelector('[data-signconnect-signature-pad]');

        if (!target) {
            return;
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(function () {
            target.focus({ preventScroll: true });
        }, 420);
    }

}());
