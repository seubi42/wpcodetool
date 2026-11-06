<?php

namespace Smbb\SignConnectProxima\Support;

defined('ABSPATH') || exit;

/**
 * Normalisations communes aux hooks CodeTool.
 */
final class Input
{
    /**
     * Retire les champs d'audit que le formulaire ne doit jamais fournir.
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
     * Une liste de cases à cocher absente du POST veut dire « rien de coché ».
     * On ne garde que les valeurs autorisées, sans doublon.
     */
    public static function checkboxList(array $data, $key, array $allowed)
    {
        $values = isset($data[$key]) ? $data[$key] : array();

        if (is_string($values)) {
            $decoded = json_decode($values, true);
            $values = is_array($decoded) ? $decoded : array();
        }

        $values = array_map('strval', is_array($values) ? $values : array());
        $data[$key] = array_values(array_unique(array_intersect($values, array_map('strval', $allowed))));

        return $data;
    }

    /**
     * Nombre décimal saisi en français (virgule) ou anglais (point).
     */
    public static function decimal(array $data, array $keys, $scale = 2)
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $value = str_replace(array(' ', ','), array('', '.'), (string) $data[$key]);
            $data[$key] = $value === '' ? '0' : $value;

            if (is_numeric($data[$key])) {
                $data[$key] = number_format((float) $data[$key], $scale, '.', '');
            }
        }

        return $data;
    }

    public static function integer(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                $data[$key] = is_numeric($data[$key]) ? (string) (int) $data[$key] : $data[$key];
            }
        }

        return $data;
    }

    /**
     * Les champs secrets (mot de passe) laissés vides en modification gardent leur valeur.
     */
    public static function keepSecrets(array $data, array $keys, array $context)
    {
        $current = isset($context['current']) && is_array($context['current']) ? $context['current'] : array();

        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) === '' && !empty($current[$key])) {
                $data[$key] = $current[$key];
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

    public static function isPercent($value)
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 100;
    }

    public static function isAmount($value)
    {
        return is_numeric($value) && (float) $value >= 0;
    }
}
