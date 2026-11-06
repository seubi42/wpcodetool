<?php

namespace Smbb\Tomo\Support;

defined('ABSPATH') || exit;

/**
 * Normalisations communes aux hooks CodeTool.
 */
final class Input
{
    /**
     * Retire les champs d'audit que le formulaire ou l'API ne doivent jamais fournir.
     */
    public static function stripManaged(array $data)
    {
        foreach (array('creation_date', 'creation_by', 'lastupdate_date', 'lastupdate_by') as $key) {
            unset($data[$key]);
        }

        return $data;
    }

    public static function trim(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Nombre décimal saisi en français (virgule) ou anglais (point). Vide -> null.
     */
    public static function decimal(array $data, array $keys, $scale = 2)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            $value = str_replace(array(' ', ','), array('', '.'), (string) $data[$key]);

            if ($value === '') {
                $data[$key] = null;
            } elseif (is_numeric($value)) {
                $data[$key] = number_format((float) $value, $scale, '.', '');
            } else {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * Entiers optionnels : vide -> null.
     */
    public static function integer(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }

            if ($data[$key] === '') {
                $data[$key] = null;
            } elseif (is_numeric($data[$key])) {
                $data[$key] = (string) (int) $data[$key];
            }
        }

        return $data;
    }

    /**
     * Ids optionnels : chaîne vide ou 0 -> null.
     */
    public static function nullableIds(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && (string) $data[$key] !== '' && (int) $data[$key] > 0) {
                $data[$key] = (string) (int) $data[$key];
            } elseif (array_key_exists($key, $data)) {
                $data[$key] = null;
            }
        }

        return $data;
    }

    /**
     * Liste d'ids (cases à cocher). Absente du POST d'un formulaire = rien de coché.
     */
    public static function idList(array $data, $key, $missing_means_empty = true)
    {
        if (!array_key_exists($key, $data)) {
            if ($missing_means_empty) {
                $data[$key] = array();
            }

            return $data;
        }

        $values = $data[$key];

        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : array();
        }

        $ids = array();

        foreach (is_array($values) ? $values : array() as $value) {
            if ((int) $value > 0) {
                $ids[] = (string) (int) $value;
            }
        }

        $data[$key] = array_values(array_unique($ids));

        return $data;
    }

    public static function isEmail($value)
    {
        return $value === null || $value === '' || is_email((string) $value);
    }

    /**
     * Téléphone : on garde les chiffres et le +, au format international si possible (pour Twilio).
     */
    public static function phone($value)
    {
        $value = preg_replace('/[^0-9+]/', '', (string) $value);

        if ($value === '') {
            return '';
        }

        // 06 12 34 56 78 -> +33612345678 (numéros français saisis sans indicatif).
        if (preg_match('/^0[1-9][0-9]{8}$/', $value)) {
            return '+33' . substr($value, 1);
        }

        if (strpos($value, '00') === 0) {
            return '+' . substr($value, 2);
        }

        return $value;
    }

    public static function isDate($value)
    {
        $date = \DateTime::createFromFormat('Y-m-d', (string) $value);

        return $date && $date->format('Y-m-d') === (string) $value;
    }

    /**
     * Heure HH:MM ou HH:MM:SS -> HH:MM:SS, sinon chaîne vide.
     */
    public static function time($value)
    {
        $value = trim((string) $value);

        if (preg_match('/^([01]?[0-9]|2[0-3])[:hH]([0-5][0-9])(?::([0-5][0-9]))?$/', $value, $matches)) {
            return sprintf('%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], isset($matches[3]) ? (int) $matches[3] : 0);
        }

        return '';
    }
}
