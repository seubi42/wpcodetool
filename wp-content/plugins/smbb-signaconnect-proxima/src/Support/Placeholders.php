<?php

namespace Smbb\SignConnectProxima\Support;

use Smbb\SignConnectProxima\Repository\Documents;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Templates;

defined('ABSPATH') || exit;

/**
 * Variables entre crochets des textes paramétrés dans un modèle (email du document signé,
 * puis nommage) : [nom], [email], [mobile], [document], [modèle], [expéditeur], [organisation],
 * [équipe], [date], et les champs perso du modèle par leur libellé ([N° de commande]). Casse et accents ignorés ; une variable inconnue reste telle quelle.
 */
final class Placeholders
{
    /**
     * Libellés affichés dans l'aide des formulaires.
     *
     * @return string[]
     */
    public static function help()
    {
        return array('[nom]', '[email]', '[mobile]', '[document]', '[modèle]', '[expéditeur]', '[organisation]', '[équipe]', '[date]');
    }

    /**
     * Valeurs d'un document.
     *
     * @return array<string,string> clé normalisée => valeur
     */
    public static function values(array $document, array $organization = array(), array $template = array())
    {
        $sender = !empty($document['sender_id']) ? get_userdata((int) $document['sender_id']) : false;
        $date = !empty($document['signed_at']) ? $document['signed_at'] : (!empty($document['sent_at']) ? $document['sent_at'] : current_time('mysql'));
        $teams = Lookup::teams();
        $team_id = isset($document['team_id']) ? (int) $document['team_id'] : 0;
        $values = array(
            'nom' => Documents::recipientName($document),
            'destinataire' => Documents::recipientName($document),
            'signataire' => isset($document['signer_name']) && $document['signer_name'] !== '' ? (string) $document['signer_name'] : Documents::recipientName($document),
            'email' => isset($document['recipient_email']) ? (string) $document['recipient_email'] : '',
            'mobile' => isset($document['recipient_phone']) ? (string) $document['recipient_phone'] : '',
            'telephone' => isset($document['recipient_phone']) ? (string) $document['recipient_phone'] : '',
            'document' => preg_replace('/\.pdf$/i', '', isset($document['filename']) ? (string) $document['filename'] : ''),
            'fichier' => preg_replace('/\.pdf$/i', '', isset($document['filename']) ? (string) $document['filename'] : ''),
            'modele' => isset($template['name']) ? (string) $template['name'] : '',
            'expediteur' => $sender ? (string) $sender->display_name : '',
            'organisation' => isset($organization['name']) ? (string) $organization['name'] : '',
            'equipe' => isset($teams[$team_id]) ? (string) $teams[$team_id]['name'] : '',
            'date' => mysql2date('d/m/Y', $date),
        );

        // Champs perso du modèle : [N° de commande], par leur libellé.
        foreach (self::custom($document) as $field) {
            $key = self::key($field['label']);

            if ($key !== '' && !isset($values[$key])) {
                $values[$key] = (string) $field['value'];
            }
        }

        // Champ perso du modèle laissé vide : la variable disparaît plutôt que de rester entre crochets.
        foreach ($template ? Templates::customFields($template) : array() as $field) {
            $key = self::key($field['label']);

            if ($key !== '' && !isset($values[$key])) {
                $values[$key] = '';
            }
        }

        return $values;
    }

    /**
     * Valeurs des champs perso d'un document (colonne custom_values, JSON ou tableau).
     *
     * @return array<string,array{label:string,value:string}>
     */
    public static function custom(array $document)
    {
        $raw = isset($document['custom_values']) ? $document['custom_values'] : null;
        $items = is_array($raw) ? $raw : json_decode((string) $raw, true);
        $custom = array();

        foreach (is_array($items) ? $items : array() as $key => $item) {
            if (is_array($item) && isset($item['label'], $item['value']) && (string) $item['value'] !== '') {
                $custom[(string) $key] = array('label' => (string) $item['label'], 'value' => (string) $item['value']);
            }
        }

        return $custom;
    }

    public static function render($text, array $values)
    {
        return (string) preg_replace_callback('/\[([^\[\]]{1,60})\]/u', function ($match) use ($values) {
            $key = self::key($match[1]);

            return array_key_exists($key, $values) ? (string) $values[$key] : $match[0];
        }, (string) $text);
    }

    /**
     * Clé de comparaison : minuscules, sans accents ni espaces superflus.
     */
    public static function key($label)
    {
        $label = remove_accents(mb_strtolower(trim((string) $label)));

        return (string) preg_replace('/\s+/', ' ', $label);
    }
}
