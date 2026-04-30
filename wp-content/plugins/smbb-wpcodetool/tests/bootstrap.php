<?php

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$GLOBALS['smbb_test_options'] = array();
$GLOBALS['smbb_test_current_time'] = '2026-04-20 10:30:00';
$GLOBALS['smbb_test_current_user_id'] = 7;
$GLOBALS['smbb_test_dbdelta_result'] = array();
$GLOBALS['smbb_test_dbdelta_calls'] = array();

if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        unset($domain);

        return $text;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '')
    {
        unset($show);

        return 'CodeTool Tests';
    }
}

if (!function_exists('rest_url')) {
    function rest_url($path = '')
    {
        $path = trim((string) $path, '/');

        return 'https://example.test/wp-json' . ($path !== '' ? '/' . $path : '');
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit($value)
    {
        return rtrim((string) $value, '/');
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $key = strtolower((string) $key);

        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($text)
    {
        if (!is_scalar($text)) {
            return '';
        }

        return trim(strip_tags((string) $text));
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email($email)
    {
        return trim((string) $email);
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        return trim((string) $url);
    }
}

if (!function_exists('absint')) {
    function absint($value)
    {
        return abs((int) $value);
    }
}

if (!function_exists('current_time')) {
    function current_time($type)
    {
        if ($type === 'mysql') {
            return $GLOBALS['smbb_test_current_time'];
        }

        return strtotime($GLOBALS['smbb_test_current_time']);
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return (int) $GLOBALS['smbb_test_current_user_id'];
    }
}

if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['smbb_test_options'])
            ? $GLOBALS['smbb_test_options'][$name]
            : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        unset($autoload);
        $GLOBALS['smbb_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($name)
    {
        $exists = array_key_exists($name, $GLOBALS['smbb_test_options']);
        unset($GLOBALS['smbb_test_options'][$name]);

        return $exists;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return $value;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($value)
    {
        return $value instanceof WP_Error;
    }
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($value)
    {
        return $value;
    }
}

if (!function_exists('dbDelta')) {
    function dbDelta($sql)
    {
        $GLOBALS['smbb_test_dbdelta_calls'][] = $sql;

        return $GLOBALS['smbb_test_dbdelta_result'];
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $message;

        public function __construct($code = '', $message = '')
        {
            unset($code);
            $this->message = (string) $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public $data;
        public $status;
        public $headers = array();

        public function __construct($data = null, $status = 200)
        {
            $this->data = $data;
            $this->status = (int) $status;
        }

        public function header($name, $value)
        {
            $this->headers[(string) $name] = (string) $value;
        }
    }
}

function smbb_wpcodetool_tests_reset_environment()
{
    $GLOBALS['smbb_test_options'] = array();
    $GLOBALS['smbb_test_current_time'] = '2026-04-20 10:30:00';
    $GLOBALS['smbb_test_current_user_id'] = 7;
    $GLOBALS['smbb_test_dbdelta_result'] = array();
    $GLOBALS['smbb_test_dbdelta_calls'] = array();
}

$vendor = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

if (is_readable($vendor)) {
    require_once $vendor;
}

spl_autoload_register(function ($class) {
    $prefixes = array(
        'Smbb\\WpCodeTool\\Tests\\' => __DIR__ . DIRECTORY_SEPARATOR,
        'Smbb\\WpCodeTool\\' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR,
    );

    foreach ($prefixes as $prefix => $base_dir) {
        if (strpos($class, $prefix) !== 0) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $base_dir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
});
