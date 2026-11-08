<?php

namespace Smbb\WpCodeTool\Storage;

defined('ABSPATH') || exit;

/**
 * Une unité de stockage prête à servir (WordPress, S3…). Les clés sont des chemins relatifs
 * (« forms/12/34/devis.pdf »), sans « .. ». Les erreurs remontent en \RuntimeException.
 */
interface DiskInterface
{
    /** Envoie un fichier local. */
    public function putFile($key, $path, $content_type = 'application/octet-stream');

    /** Écrit un contenu texte ou binaire. */
    public function putString($key, $content, $content_type = 'text/plain');

    /** Copie l'objet dans un fichier temporaire local (à supprimer par l'appelant). */
    public function download($key);

    public function getString($key);

    public function delete($key);

    public function exists($key);

    /**
     * Lien direct temporaire (S3 : URL signée), ou null si l'unité n'en donne pas
     * (WordPress : fichiers privés servis par PHP).
     */
    public function temporaryUrl($key, $seconds = 600);

    /** Test complet (écrire, relire, supprimer). Retourne un message de succès. */
    public function test();
}
