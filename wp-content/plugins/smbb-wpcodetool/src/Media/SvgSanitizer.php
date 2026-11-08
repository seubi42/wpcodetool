<?php

namespace Smbb\WpCodeTool\Media;

defined('ABSPATH') || exit;

/**
 * Nettoyage des fichiers SVG envoyés dans la médiathèque.
 *
 * Un SVG est un document XML : il peut porter du JavaScript (<script>, onload=…, liens
 * javascript:, <foreignObject> avec du HTML), charger des ressources externes ou piéger
 * l'analyseur XML (entités, « billion laughs »). On garde une liste blanche d'éléments
 * et d'attributs de dessin, et on jette tout le reste.
 *
 * - Refusé d'office : fichier compressé (svgz), DOCTYPE / ENTITY, XML invalide, racine autre que <svg>.
 * - Supprimé : éléments hors liste (script, foreignObject, iframe, animations qui réécrivent href…),
 *   attributs hors liste et tous les on*, liens qui ne sont pas internes (#id) ou une image data:
 *   PNG/JPEG/GIF/WebP, url(…) externes et expressions dans les styles, commentaires et
 *   instructions de traitement.
 *
 * Filtres : smbb_wpcodetool_svg_allowed_tags, smbb_wpcodetool_svg_allowed_attributes.
 */
final class SvgSanitizer
{
    const TAGS = array(
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'metadata', 'switch',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath', 'image',
        'lineargradient', 'radialgradient', 'stop', 'pattern', 'clippath', 'mask', 'marker',
        'filter', 'feblend', 'fecolormatrix', 'fecomponenttransfer', 'fecomposite', 'feconvolvematrix',
        'fediffuselighting', 'fedisplacementmap', 'fedistantlight', 'fedropshadow', 'feflood',
        'fefunca', 'fefuncb', 'fefuncg', 'fefuncr', 'fegaussianblur', 'feimage', 'femerge',
        'femergenode', 'femorphology', 'feoffset', 'fepointlight', 'fespecularlighting',
        'fespotlight', 'fetile', 'feturbulence',
        'style', 'view',
    );

    const ATTRIBUTES = array(
        'id', 'class', 'style', 'lang', 'xml:lang', 'xml:space', 'tabindex', 'role', 'focusable',
        'aria-label', 'aria-labelledby', 'aria-describedby', 'aria-hidden',
        'xmlns', 'xmlns:xlink', 'xmlns:svg', 'version', 'baseprofile',
        'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy', 'fr',
        'd', 'points', 'pathlength', 'viewbox', 'preserveaspectratio', 'transform',
        'href', 'xlink:href', 'xlink:title',
        'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linecap',
        'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset',
        'opacity', 'color', 'display', 'visibility', 'overflow', 'clip', 'clip-path', 'clip-rule',
        'clippathunits', 'mask', 'maskunits', 'maskcontentunits', 'filter', 'filterunits', 'primitiveunits',
        'marker', 'marker-start', 'marker-mid', 'marker-end', 'markerwidth', 'markerheight', 'markerunits',
        'refx', 'refy', 'orient', 'patternunits', 'patterncontentunits', 'patterntransform',
        'gradientunits', 'gradienttransform', 'spreadmethod', 'offset', 'stop-color', 'stop-opacity',
        'font-family', 'font-size', 'font-size-adjust', 'font-stretch', 'font-style', 'font-variant', 'font-weight',
        'text-anchor', 'text-decoration', 'text-rendering', 'dominant-baseline', 'alignment-baseline',
        'baseline-shift', 'letter-spacing', 'word-spacing', 'writing-mode', 'dx', 'dy', 'rotate',
        'lengthadjust', 'textlength', 'startoffset', 'method', 'spacing', 'side',
        'shape-rendering', 'image-rendering', 'color-interpolation', 'color-interpolation-filters',
        'color-rendering', 'mix-blend-mode', 'isolation', 'paint-order', 'vector-effect',
        'in', 'in2', 'result', 'mode', 'type', 'values', 'operator', 'k1', 'k2', 'k3', 'k4',
        'stddeviation', 'edgemode', 'kernelmatrix', 'kernelunitlength', 'order', 'divisor', 'bias',
        'targetx', 'targety', 'preservealpha', 'surfacescale', 'diffuseconstant', 'specularconstant',
        'specularexponent', 'scale', 'xchannelselector', 'ychannelselector', 'flood-color', 'flood-opacity',
        'lighting-color', 'azimuth', 'elevation', 'pointsatx', 'pointsaty', 'pointsatz', 'limitingconeangle',
        'radius', 'basefrequency', 'numoctaves', 'seed', 'stitchtiles', 'tablevalues', 'slope',
        'intercept', 'amplitude', 'exponent', 'requiredfeatures', 'systemlanguage', 'media',
    );

    /** Attributs qui portent un lien. */
    const LINK_ATTRIBUTES = array('href', 'xlink:href');

    /**
     * Nettoie le fichier sur place.
     *
     * @return true|\WP_Error
     */
    public static function sanitizeFile($path)
    {
        $raw = @file_get_contents($path); // phpcs:ignore

        if ($raw === false || $raw === '') {
            return new \WP_Error('smbb_svg_read', __('Le fichier SVG est vide ou illisible.', 'smbb-wpcodetool'));
        }

        $clean = self::sanitize($raw);

        if (is_wp_error($clean)) {
            return $clean;
        }

        if (@file_put_contents($path, $clean) === false) { // phpcs:ignore
            return new \WP_Error('smbb_svg_write', __('Impossible d\'enregistrer le SVG nettoyé.', 'smbb-wpcodetool'));
        }

        return true;
    }

    /**
     * @return string|\WP_Error SVG nettoyé
     */
    public static function sanitize($raw)
    {
        $raw = (string) $raw;

        // SVGZ (gzip) : on ne décompresse pas, on refuse.
        if (substr($raw, 0, 2) === "\x1f\x8b") {
            return new \WP_Error('smbb_svg_gzip', __('Les SVG compressés (.svgz) ne sont pas acceptés.', 'smbb-wpcodetool'));
        }

        // BOM UTF-8.
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }

        // Pas de DOCTYPE ni d'entités : c'est la porte des attaques XXE / « billion laughs ».
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $raw)) {
            return new \WP_Error('smbb_svg_doctype', __('Ce SVG contient une déclaration DOCTYPE ou des entités XML : il est refusé par sécurité. Réexportez-le depuis votre logiciel de dessin (« SVG simple » ou « optimisé »).', 'smbb-wpcodetool'));
        }

        if (!class_exists('DOMDocument')) {
            return new \WP_Error('smbb_svg_dom', __('L\'extension PHP « dom » manque sur le serveur : impossible de vérifier les SVG.', 'smbb-wpcodetool'));
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->formatOutput = false;
        $doc->preserveWhiteSpace = true;
        $loaded = $doc->loadXML($raw, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || !$doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') {
            return new \WP_Error('smbb_svg_invalid', __('Ce fichier n\'est pas un SVG valide.', 'smbb-wpcodetool'));
        }

        $tags = array_map('strtolower', (array) apply_filters('smbb_wpcodetool_svg_allowed_tags', self::TAGS));
        $attributes = array_map('strtolower', (array) apply_filters('smbb_wpcodetool_svg_allowed_attributes', self::ATTRIBUTES));

        self::cleanNode($doc->documentElement, array_flip($tags), array_flip($attributes));

        // Commentaires et instructions de traitement au niveau du document.
        foreach (iterator_to_array($doc->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $doc->removeChild($child);
            }
        }

        $out = $doc->saveXML($doc->documentElement);

        if (!is_string($out) || $out === '') {
            return new \WP_Error('smbb_svg_invalid', __('Ce fichier n\'est pas un SVG valide.', 'smbb-wpcodetool'));
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $out . "\n";
    }

    private static function cleanNode(\DOMElement $element, array $tags, array $attributes)
    {
        // Attributs.
        foreach (iterator_to_array($element->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = (string) $attr->nodeValue;

            if (strpos($name, 'on') === 0 || !isset($attributes[$name])) {
                $element->removeAttributeNode($attr);
                continue;
            }

            if (in_array($name, self::LINK_ATTRIBUTES, true) && !self::safeLink($value)) {
                $element->removeAttributeNode($attr);
                continue;
            }

            if (self::dangerousValue($value)) {
                $element->removeAttributeNode($attr);
            }
        }

        // Enfants.
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (!isset($tags[strtolower($child->localName)])) {
                    $element->removeChild($child);
                    continue;
                }

                if (strtolower($child->localName) === 'style') {
                    $css = $child->textContent;

                    if (self::dangerousValue($css)) {
                        $element->removeChild($child);
                        continue;
                    }

                    // Uniquement du texte dans <style>.
                    while ($child->firstChild) {
                        $child->removeChild($child->firstChild);
                    }

                    $child->appendChild($child->ownerDocument->createTextNode($css));
                }

                self::cleanNode($child, $tags, $attributes);
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction || $child instanceof \DOMEntityReference) {
                $element->removeChild($child);
            }
        }
    }

    /** Lien interne (#id) ou image embarquée data: PNG/JPEG/GIF/WebP. */
    private static function safeLink($value)
    {
        $value = trim(preg_replace('/[\x00-\x20]+/', '', (string) $value));

        if ($value === '' || $value[0] === '#') {
            return true;
        }

        return (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=]+$#i', $value);
    }

    /** Script, expression CSS, import, url() externe. */
    private static function dangerousValue($value)
    {
        $flat = strtolower(preg_replace('/[\x00-\x20]+|\\\\/', '', html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        if ($flat === '') {
            return false;
        }

        foreach (array('javascript:', 'vbscript:', 'data:text', 'data:application', 'expression(', '@import', 'behavior:', '-moz-binding', '<script') as $needle) {
            if (strpos($flat, $needle) !== false) {
                return true;
            }
        }

        // url(…) : seulement vers un élément du dessin (#id) ou une image data:.
        if (preg_match_all('/url\(([^)]*)\)/', $flat, $m)) {
            foreach ($m[1] as $target) {
                $target = trim($target, '\'"');

                if ($target !== '' && $target[0] !== '#' && !preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $target)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Largeur et hauteur du dessin (attributs width/height, sinon viewBox).
     *
     * @return array{0:int,1:int}
     */
    public static function dimensions($path)
    {
        $raw = @file_get_contents($path, false, null, 0, 65536); // phpcs:ignore
        $w = 0;
        $h = 0;

        if (is_string($raw) && preg_match('/<svg\b[^>]*>/is', $raw, $tag)) {
            if (preg_match('/\swidth\s*=\s*["\']\s*([\d.]+)(px)?\s*["\']/i', $tag[0], $m)) {
                $w = (int) round((float) $m[1]);
            }

            if (preg_match('/\sheight\s*=\s*["\']\s*([\d.]+)(px)?\s*["\']/i', $tag[0], $m)) {
                $h = (int) round((float) $m[1]);
            }

            if ((!$w || !$h) && preg_match('/\sviewBox\s*=\s*["\']\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*["\']/i', $tag[0], $m)) {
                $w = (int) round((float) $m[1]);
                $h = (int) round((float) $m[2]);
            }
        }

        return array(max(0, $w), max(0, $h));
    }
}
