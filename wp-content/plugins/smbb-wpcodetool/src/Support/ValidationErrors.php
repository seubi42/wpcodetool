<?php

namespace Smbb\WpCodeTool\Support;

// Cette petite utilitaire est partagee entre l'admin et l'API.
defined('ABSPATH') || exit;

/**
 * Normalisation des erreurs de validation CodeTool.
 *
 * Le but est simple :
 * - les hooks validate() peuvent rester tres legers ;
 * - l'admin peut afficher les erreurs au bon endroit dans le formulaire ;
 * - l'API REST peut decrire les memes erreurs avec une structure stable.
 *
 * Formes d'entree acceptees :
 * - array('name' => 'Name is required.')
 * - array('api.endpoint' => 'Endpoint is invalid.')
 * - array('json_table[0][label]' => 'Label is required.')
 * - array(array('path' => 'name', 'message' => '...'))
 */
final class ValidationErrors
{
    /**
     * Normalise une liste d'erreurs vers une forme riche et stable.
     *
     * Sortie :
     * array(
     *     'api.endpoint' => array(
     *         'field' => 'api.endpoint',
     *         'path' => 'api.endpoint',
     *         'html_name' => 'api[endpoint]',
     *         'message' => 'Endpoint is invalid.',
     *     ),
     * )
     */
    public static function normalize(array $errors)
    {
        $normalized = array();
        $global_index = 0;

        foreach ($errors as $key => $value) {
            $entry = self::normalizeEntry($key, $value);

            if (!$entry || $entry['message'] === '') {
                continue;
            }

            $entry_key = $entry['path'] !== '' ? $entry['path'] : '__global_' . $global_index;
            $normalized[$entry_key] = $entry;

            if ($entry['path'] === '') {
                $global_index++;
            }
        }

        return $normalized;
    }

    /**
     * Version "liste numerique" pratique pour une reponse JSON.
     */
    public static function listing(array $errors)
    {
        return array_values(self::normalize($errors));
    }

    /**
     * Version simple utile pour les notices admin.
     *
     * On conserve la cle du champ quand on la connait, sinon on pousse juste le message.
     */
    public static function noticeDetails(array $errors)
    {
        $details = array();

        foreach (self::normalize($errors) as $entry) {
            if ($entry['path'] !== '') {
                $details[$entry['path']] = $entry['message'];
                continue;
            }

            $details[] = $entry['message'];
        }

        return $details;
    }

    /**
     * Convertit un nom de champ HTML vers une notation pointee.
     *
     * Exemples :
     * - name -> name
     * - api[endpoint] -> api.endpoint
     * - json_table[0][label] -> json_table.0.label
     */
    public static function pathFromFieldName($field)
    {
        $field = trim((string) $field);

        if ($field === '') {
            return '';
        }

        if (strpos($field, '[') !== false) {
            $parts = preg_split('/\\[|\\]/', $field, -1, PREG_SPLIT_NO_EMPTY);

            return implode('.', array_map('strval', $parts));
        }

        $field = trim($field, '.');

        return preg_replace('/\\.+/', '.', $field);
    }

    /**
     * Convertit une notation pointee vers un nom HTML.
     *
     * Exemples :
     * - api.endpoint -> api[endpoint]
     * - json_table.0.label -> json_table[0][label]
     */
    public static function htmlNameFromPath($path)
    {
        $path = self::pathFromFieldName($path);

        if ($path === '') {
            return '';
        }

        $parts = explode('.', $path);
        $name = array_shift($parts);

        foreach ($parts as $part) {
            $name .= '[' . $part . ']';
        }

        return $name;
    }

    /**
     * Construit une entree normalisee.
     */
    private static function normalizeEntry($key, $value)
    {
        $path = is_string($key) ? self::pathFromFieldName($key) : '';
        $message = '';

        if (is_array($value)) {
            if (isset($value['path'])) {
                $path = self::pathFromFieldName($value['path']);
            } elseif (isset($value['field'])) {
                $path = self::pathFromFieldName($value['field']);
            } elseif (isset($value['name'])) {
                $path = self::pathFromFieldName($value['name']);
            }

            if (isset($value['message'])) {
                $message = (string) $value['message'];
            } elseif (isset($value['error'])) {
                $message = (string) $value['error'];
            }
        } else {
            $message = (string) $value;
        }

        $message = trim($message);

        return array(
            'field' => $path,
            'path' => $path,
            'html_name' => $path !== '' ? self::htmlNameFromPath($path) : '',
            'message' => $message,
        );
    }
}
