<?php

namespace Smbb\SignConnect\Service;

defined('ABSPATH') || exit;

/**
 * Genere une image moyenne qualite d'une page PDF pour l'analyse IA.
 *
 * Imagick est le chemin le plus simple côté serveur WordPress. Si l'extension
 * ou la policy ImageMagick PDF n\'est pas disponible, on retourne une erreur
 * explicite et le service appelant peut journaliser le fallback.
 */
final class PdfPageRasterizer
{
    public function rasterize($pdf_path, $page_number)
    {
        if (!class_exists('\Imagick')) {
            return array('success' => false, 'error' => 'Imagick extension is not available.');
        }

        if (!is_readable($pdf_path)) {
            return array('success' => false, 'error' => 'PDF file is not readable.');
        }

        $page_index = max(0, (int) $page_number - 1);
        $temporary_path = wp_tempnam('signconnect-page.png');

        if (!$temporary_path) {
            return array('success' => false, 'error' => 'Unable to create temporary image file.');
        }

        $temporary_path = preg_replace('/\.tmp$/', '.png', $temporary_path);

        try {
            $image = new \Imagick();
            $image->setResolution(160, 160);
            $image->readImage((string) $pdf_path . '[' . $page_index . ']');
            $image->setImageFormat('png');
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageCompressionQuality(72);

            if ($image->getImageWidth() > 1900) {
                $image->resizeImage(1900, 0, \Imagick::FILTER_LANCZOS, 1);
            }

            $image->writeImage($temporary_path);
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $image->clear();
            $image->destroy();

            return array(
                'success' => true,
                'path' => $temporary_path,
                'mime_type' => 'image/png',
                'width' => (int) $width,
                'height' => (int) $height,
            );
        } catch (\Throwable $exception) {
            @unlink($temporary_path);

            return array(
                'success' => false,
                'error' => $exception->getMessage(),
            );
        }
    }
}
