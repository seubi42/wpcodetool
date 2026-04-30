<?php

namespace Smbb\WpCodeTool\Admin;

defined('ABSPATH') || exit;

/**
 * Petit helper autour de la requete admin WordPress.
 */
final class AdminRequest
{
    public function method()
    {
        return isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
    }

    public function page()
    {
        return $this->queryText('page');
    }

    public function queryHas($key)
    {
        return isset($_GET[$key]);
    }

    public function postHas($key)
    {
        return isset($_POST[$key]);
    }

    public function queryText($key, $default = '')
    {
        if (!isset($_GET[$key])) {
            return $default;
        }

        return sanitize_text_field(wp_unslash($_GET[$key]));
    }

    public function queryKey($key, $default = '')
    {
        if (!isset($_GET[$key])) {
            return $default;
        }

        return sanitize_key(wp_unslash($_GET[$key]));
    }

    public function queryInt($key, $default = 0)
    {
        if (!isset($_GET[$key])) {
            return $default;
        }

        return (int) wp_unslash($_GET[$key]);
    }

    /**
     * @return mixed
     */
    public function queryValue($key, $default = null)
    {
        if (!isset($_GET[$key])) {
            return $default;
        }

        return wp_unslash($_GET[$key]);
    }

    /**
     * @return array<string,mixed>|array<int,mixed>
     */
    public function queryArray($key)
    {
        $value = $this->queryValue($key, array());

        return is_array($value) ? $value : array();
    }

    public function postText($key, $default = '')
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return sanitize_text_field(wp_unslash($_POST[$key]));
    }

    public function postKey($key, $default = '')
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return sanitize_key(wp_unslash($_POST[$key]));
    }

    public function postInt($key, $default = 0)
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return (int) wp_unslash($_POST[$key]);
    }

    public function postAbsInt($key, $default = 0)
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return absint(wp_unslash($_POST[$key]));
    }

    public function postEmail($key, $default = '')
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return sanitize_email(wp_unslash($_POST[$key]));
    }

    /**
     * @return mixed
     */
    public function postValue($key, $default = null)
    {
        if (!isset($_POST[$key])) {
            return $default;
        }

        return wp_unslash($_POST[$key]);
    }

    /**
     * @return array<string,mixed>|array<int,mixed>
     */
    public function postArray($key)
    {
        $value = $this->postValue($key, array());

        return is_array($value) ? $value : array();
    }

    /**
     * @return array<string,mixed>
     */
    public function postData()
    {
        $posted = wp_unslash($_POST);

        return is_array($posted) ? $posted : array();
    }
}
