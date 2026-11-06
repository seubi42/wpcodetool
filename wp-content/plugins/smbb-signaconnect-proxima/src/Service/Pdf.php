<?php

namespace Smbb\SignConnectProxima\Service;

use setasign\Fpdi\Fpdi;

defined('ABSPATH') || exit;

if (!function_exists('wp_tempnam')) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

/**
 * Lecture et tamponnage des PDF de la signature interne (FPDI/FPDF fournis par CodeTool).
 *
 * Le parseur FPDI gratuit ne lit pas certains PDF récents (flux d'objets compressés).
 * On le détecte dès le dépôt : le PDF est alors normalisé avec qpdf, Ghostscript ou
 * pdftocairo s'ils sont présents sur le serveur, sinon le dépôt est refusé avec un
 * message clair (plutôt qu'un échec au moment où le signataire signe).
 */
final class Pdf
{
    const FIELD_TYPES = array('signature', 'full_name', 'date', 'place', 'sender_stamp', 'sender_signature');

    public static function fieldTypeLabels()
    {
        return array(
            'signature' => __('Signature', 'smbb-signaconnect-proxima'),
            'full_name' => __('Nom du signataire', 'smbb-signaconnect-proxima'),
            'date' => __('Date de signature', 'smbb-signaconnect-proxima'),
            'place' => __('Lieu', 'smbb-signaconnect-proxima'),
            'sender_stamp' => __('Tampon', 'smbb-signaconnect-proxima'),
            'sender_signature' => __("Signature de l'expéditeur", 'smbb-signaconnect-proxima'),
        );
    }

    /**
     * Vérifie qu'un PDF est exploitable.
     *
     * @return array{path:string,normalized:bool,page_count:int}
     */
    public static function inspect($path)
    {
        self::assertLibrary();

        try {
            return array('path' => $path, 'normalized' => false, 'page_count' => self::pageCount($path));
        } catch (\Throwable $exception) {
            $normalized = self::normalize($path);

            if ($normalized === '') {
                throw new \RuntimeException(__("Ce PDF utilise une compression que le serveur ne sait pas relire. Réenregistrez-le (Imprimer > PDF) ou installez qpdf ou Ghostscript sur le serveur.", 'smbb-signaconnect-proxima'));
            }

            return array('path' => $normalized, 'normalized' => true, 'page_count' => self::pageCount($normalized));
        }
    }

    public static function pageCount($path)
    {
        $pdf = new Fpdi();

        return (int) $pdf->setSourceFile($path);
    }

    /**
     * Tailles des pages en points PDF (1/72 pouce), pour positionner les champs Universign.
     *
     * @return array<int,array{0:float,1:float}> numéro de page => [largeur, hauteur]
     */
    public static function pageSizes($path)
    {
        self::assertLibrary();
        $pdf = new Fpdi('P', 'pt');
        $count = (int) $pdf->setSourceFile($path);
        $sizes = array();

        for ($page = 1; $page <= $count; $page++) {
            $size = $pdf->getTemplateSize($pdf->importPage($page));
            $sizes[$page] = array((float) $size['width'], (float) $size['height']);
        }

        return $sizes;
    }

    /**
     * Produit le PDF signé : signature et mentions dans les zones, puis page de preuve.
     *
     * @param array<int,array<string,mixed>> $fields zones en ratio de page (0–1)
     * @param array<string,mixed> $data signature_png, signer_name, signed_at (mysql), place (ville de l'organisation),
     *                                  stamp_path, stamp_name (nom de l'organisation sous le tampon), sender_name,
     *                                  sender_signature_png, proof (label => valeur), brand_color
     */
    public static function sign($source, $target, array $fields, array $data)
    {
        self::assertLibrary();
        $pdf = new Fpdi();
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->SetCreator('SignaConnect');
        $pdf->SetTitle(self::text(isset($data['title']) ? $data['title'] : 'Document signé'));
        $page_count = $pdf->setSourceFile($source);
        $signed_at = isset($data['signed_at']) ? (string) $data['signed_at'] : current_time('mysql');

        for ($page = 1; $page <= $page_count; $page++) {
            $template = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($template);
            $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', array($size['width'], $size['height']));
            $pdf->useTemplate($template);

            foreach ($fields as $field) {
                if ((int) $field['page_number'] === $page) {
                    self::drawField($pdf, $field, $size, $data, $signed_at);
                }
            }
        }

        self::proofPage($pdf, $data);
        $pdf->Output('F', $target);
    }

    private static function drawField($pdf, array $field, array $size, array $data, $signed_at)
    {
        $x = (float) $field['x'] * $size['width'];
        $y = (float) $field['y'] * $size['height'];
        $w = max(10, (float) $field['width'] * $size['width']);
        $h = max(5, (float) $field['height'] * $size['height']);

        switch ($field['field_type']) {
            case 'signature':
                $mention_h = min(9, max(5, $h * 0.3));
                $image_h = $h - $mention_h - 1;

                if (!empty($data['signature_png']) && is_readable($data['signature_png'])) {
                    self::imageFit($pdf, $data['signature_png'], $x, $y, $w, $image_h, 'PNG');
                }

                $pdf->SetFont('Helvetica', '', min(7, max(5, $mention_h * 0.75)));
                $pdf->SetTextColor(70, 70, 70);
                $pdf->SetXY($x, $y + $image_h + 0.5);
                $pdf->MultiCell($w, $mention_h / 2, self::text(sprintf(
                    'Signé électroniquement par %s le %s',
                    isset($data['signer_name']) ? $data['signer_name'] : '',
                    mysql2date('d/m/Y à H:i', $signed_at)
                )), 0, 'L');
                break;

            case 'full_name':
            case 'date':
            case 'place':
                if ($field['field_type'] === 'date') {
                    $value = mysql2date('d/m/Y', $signed_at);
                } elseif ($field['field_type'] === 'place') {
                    $value = isset($data['place']) ? (string) $data['place'] : '';
                } else {
                    $value = isset($data['signer_name']) ? $data['signer_name'] : '';
                }

                $font = max(7, min(12, $h * 0.55 / 0.3528));
                $pdf->SetFont('Helvetica', '', $font);
                $pdf->SetTextColor(20, 20, 20);
                $pdf->SetXY($x, $y);
                $pdf->Cell($w, $h, self::text($value), 0, 0, 'L');
                break;

            case 'sender_signature':
                $name_h = !empty($data['sender_name']) ? min(6, max(4, $h * 0.22)) : 0;

                if (!empty($data['sender_signature_png']) && is_readable($data['sender_signature_png'])) {
                    self::imageFit($pdf, $data['sender_signature_png'], $x, $y, $w, $h - $name_h, 'PNG');
                }

                if ($name_h > 0) {
                    $pdf->SetFont('Helvetica', '', min(7, $name_h / 0.3528 * 0.7));
                    $pdf->SetTextColor(70, 70, 70);
                    $pdf->SetXY($x, $y + $h - $name_h);
                    $pdf->Cell($w, $name_h, self::text($data['sender_name']), 0, 0, 'L');
                }
                break;

            case 'sender_stamp':
                // Sous le tampon : le nom de l'organisation (jamais celui de l'expéditeur).
                $stamp_name = isset($data['stamp_name']) ? trim((string) $data['stamp_name']) : '';
                $name_h = $stamp_name !== '' ? min(6, max(4, $h * 0.22)) : 0;

                if (!empty($data['stamp_path']) && is_readable($data['stamp_path'])) {
                    self::imageFit($pdf, $data['stamp_path'], $x, $y, $w, $h - $name_h, '');
                }

                if ($name_h > 0) {
                    $pdf->SetFont('Helvetica', '', min(8, $name_h / 0.3528 * 0.7));
                    $pdf->SetTextColor(40, 40, 40);
                    $pdf->SetXY($x, $y + $h - $name_h);
                    $pdf->Cell($w, $name_h, self::text($stamp_name), 0, 0, 'C');
                }
                break;
        }
    }

    /**
     * Image centrée dans la zone en gardant ses proportions.
     */
    private static function imageFit($pdf, $path, $x, $y, $w, $h, $type)
    {
        $info = @getimagesize($path);

        if (!$info || $info[0] < 1 || $info[1] < 1 || $h <= 0) {
            return;
        }

        $ratio = min($w / $info[0], $h / $info[1]);
        $dw = $info[0] * $ratio;
        $dh = $info[1] * $ratio;

        if ($type === '') {
            $type = $info[2] === IMAGETYPE_PNG ? 'PNG' : ($info[2] === IMAGETYPE_JPEG ? 'JPG' : '');
        }

        if ($type !== '') {
            $pdf->Image($path, $x + ($w - $dw) / 2, $y + ($h - $dh) / 2, $dw, $dh, $type);
        }
    }

    /**
     * Page de preuve ajoutée à la fin du document.
     */
    private static function proofPage($pdf, array $data)
    {
        $proof = isset($data['proof']) && is_array($data['proof']) ? $data['proof'] : array();

        if (!$proof) {
            return;
        }

        list($r, $g, $b) = self::rgb(isset($data['brand_color']) ? $data['brand_color'] : '#db1f26');
        $pdf->AddPage('P', 'A4');
        $pdf->SetFillColor($r, $g, $b);
        $pdf->Rect(0, 0, 210, 4, 'F');
        $pdf->SetXY(18, 18);
        $pdf->SetTextColor(20, 20, 20);
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->Cell(174, 10, self::text('Certificat de signature'), 0, 2, 'L');
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->MultiCell(174, 5, self::text("Ce document a été signé électroniquement avec SignaConnect (signature électronique simple). Les informations ci-dessous sont également conservées dans le journal d'audit."), 0, 'L');
        $pdf->Ln(4);

        foreach ($proof as $label => $value) {
            if ((string) $value === '') {
                continue;
            }

            $y = $pdf->GetY();
            $pdf->SetDrawColor(230, 230, 230);
            $pdf->Line(18, $y, 192, $y);
            $pdf->SetXY(18, $y + 1.5);
            $pdf->SetFont('Helvetica', '', 9);
            $pdf->SetTextColor(110, 110, 110);
            $pdf->Cell(55, 5, self::text($label), 0, 0, 'L');
            $pdf->SetTextColor(20, 20, 20);
            $pdf->SetFont('Helvetica', strlen((string) $value) > 60 ? '' : 'B', strlen((string) $value) > 60 ? 8 : 9);
            $pdf->MultiCell(119, 5, self::text((string) $value), 0, 'L');
            $pdf->SetY(max($pdf->GetY(), $y + 8) + 1);
        }
    }

    private static function rgb($hex)
    {
        $hex = ltrim((string) $hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (!preg_match('/^[0-9a-fA-F]{6}/', $hex)) {
            return array(30, 64, 175);
        }

        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    /**
     * FPDF (polices de base) attend du Windows-1252.
     */
    private static function text($value)
    {
        $converted = function_exists('iconv') ? @iconv('UTF-8', 'windows-1252//TRANSLIT', (string) $value) : false;

        return $converted !== false ? $converted : (string) $value;
    }

    private static function assertLibrary()
    {
        if (!class_exists(Fpdi::class)) {
            throw new \RuntimeException(__('La bibliothèque PDF (FPDI) de CodeTool est indisponible.', 'smbb-signaconnect-proxima'));
        }
    }

    /**
     * @return string chemin du PDF normalisé, ou '' si aucun outil n'a fonctionné
     */
    private static function normalize($path)
    {
        if (!function_exists('proc_open')) {
            return '';
        }

        $candidates = array(
            array('qpdf', array('--object-streams=disable', '{in}', '{out}')),
            array('gs', array('-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.4', '-dNOPAUSE', '-dBATCH', '-dSAFER', '-sOutputFile={out}', '{in}')),
            array('pdftocairo', array('-pdf', '{in}', '{out}')),
        );

        foreach ($candidates as $candidate) {
            $out = wp_tempnam('scp-normalized.pdf');
            $parts = array(escapeshellarg($candidate[0]));

            foreach ($candidate[1] as $argument) {
                $argument = str_replace(array('{in}', '{out}'), array($path, $out), $argument);
                $parts[] = escapeshellarg($argument);
            }

            $process = @proc_open(implode(' ', $parts), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

            if (is_resource($process)) {
                stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $status = proc_close($process);

                if ($status === 0 && is_readable($out) && filesize($out) > 0) {
                    try {
                        self::pageCount($out);

                        return $out;
                    } catch (\Throwable $exception) {
                        // Outil suivant.
                    }
                }
            }

            @unlink($out);
        }

        return '';
    }
}
