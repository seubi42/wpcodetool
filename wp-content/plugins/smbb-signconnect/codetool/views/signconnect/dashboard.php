<?php

/**
 * SignConnect admin dashboard.
 *
 * @var \Smbb\WpCodeTool\Admin\Dashboard $dashboard_ui
 * @var string $page_header_html
 * @var string $notices_html
 */

defined('ABSPATH') || exit;

$documents_url = admin_url('admin.php?page=smbb-codetool-signdocument');
$storages_url = admin_url('admin.php?page=smbb-codetool-signstorage');
$templates_url = admin_url('admin.php?page=smbb-codetool-signtemplate');
$stamps_url = admin_url('admin.php?page=smbb-codetool-signstamp');
$sender_signatures_url = admin_url('admin.php?page=smbb-codetool-signusersignature');
$document_count = 0;
$document_total_size = 0;
$template_count = 0;

global $wpdb;

if (isset($wpdb)) {
    $documents_table = $wpdb->prefix . 'signdocument';
    $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $documents_table)) === $documents_table;

    if ($table_exists) {
        $document_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$documents_table} WHERE deleted_flag = 0");

        if ($wpdb->get_var("SHOW COLUMNS FROM {$documents_table} LIKE 'file_size'")) {
            $document_total_size = (int) $wpdb->get_var("SELECT COALESCE(SUM(file_size), 0) FROM {$documents_table} WHERE deleted_flag = 0");
        }
    }

    $templates_table = $wpdb->prefix . 'signtemplate';
    $templates_table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $templates_table)) === $templates_table;

    if ($templates_table_exists) {
        $template_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$templates_table} WHERE deleted_flag = 0");
    }
}

$document_total_size_label = function_exists('size_format')
    ? size_format($document_total_size, 1)
    : number_format_i18n($document_total_size) . ' B';

$pdftotext_status = static function () {
    if (!function_exists('proc_open')) {
        return array(
            'available' => false,
            'message' => __('proc_open is disabled, so SignConnect cannot test or run pdftotext for template detection.', 'smbb-signconnect'),
        );
    }

    $executable = 'pdftotext';

    if (class_exists('\Smbb\SignConnect\Support\SignConnectSettings')) {
        $configured = \Smbb\SignConnect\Support\SignConnectSettings::templateDetectionPdftotextPath();

        if ($configured !== '') {
            $executable = $configured;
        } else {
            foreach (array('/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/local/bin/pdftotext') as $candidate) {
                if (is_executable($candidate)) {
                    $executable = $candidate;
                    break;
                }
            }
        }
    }

    $command = escapeshellarg($executable) . ' -v';
    $descriptor_spec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );
    $process = @proc_open($command, $descriptor_spec, $pipes);

    if (!is_resource($process)) {
        return array(
            'available' => false,
            'executable' => $executable,
            'message' => __('pdftotext could not be started by PHP.', 'smbb-signconnect'),
        );
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $output = trim((string) $stdout . "\n" . (string) $stderr);

    return array(
        'available' => $status === 0,
        'executable' => $executable,
        'status' => $status,
        'output' => preg_replace('/\s+/', ' ', $output),
        'message' => $status === 0
            ? __('pdftotext is available for template detection.', 'smbb-signconnect')
            : __('pdftotext is not available to PHP. Template detection cannot read text positions.', 'smbb-signconnect'),
    );
};
$pdftotext = $pdftotext_status();

echo '<div class="wrap smbb-codetool smbb-signconnect">';
echo $page_header_html;

if (!empty($notices_html)) {
    echo $notices_html;
}

if (empty($pdftotext['available'])) {
    echo '<div class="notice notice-warning">';
    echo '<p><strong>' . esc_html__('SignConnect template detection needs Poppler pdftotext.', 'smbb-signconnect') . '</strong></p>';
    echo '<p>' . esc_html(isset($pdftotext['message']) ? (string) $pdftotext['message'] : '') . '</p>';
    echo '<p>' . esc_html__('On Debian/Ubuntu servers, install it with:', 'smbb-signconnect') . '</p>';
    echo '<p><code>sudo apt install poppler-utils</code></p>';
    echo '<p>' . esc_html__('Then verify from the server shell:', 'smbb-signconnect') . ' <code>pdftotext -v</code></p>';
    echo '<p>' . esc_html__('If it is installed outside PHP PATH, set the full executable path in SignConnect Settings > Debug > pdftotext path.', 'smbb-signconnect') . '</p>';

    if (!empty($pdftotext['executable']) || array_key_exists('status', $pdftotext) || !empty($pdftotext['output'])) {
        echo '<p class="description">';
        echo !empty($pdftotext['executable']) ? esc_html__('Executable:', 'smbb-signconnect') . ' <code>' . esc_html((string) $pdftotext['executable']) . '</code> ' : '';
        echo array_key_exists('status', $pdftotext) ? esc_html__('Exit status:', 'smbb-signconnect') . ' <code>' . esc_html((string) $pdftotext['status']) . '</code> ' : '';
        echo !empty($pdftotext['output']) ? esc_html__('Output:', 'smbb-signconnect') . ' <code>' . esc_html(substr((string) $pdftotext['output'], 0, 300)) . '</code>' : '';
        echo '</p>';
    }

    echo '</div>';
}

echo $dashboard_ui->hero(array(
    'eyebrow' => __('SignConnect', 'smbb-signconnect'),
    'badge' => __('Admin', 'smbb-signconnect'),
    'title' => __('Signature operations', 'smbb-signconnect'),
    'description' => __('Manage documents to sign and the S3-compatible storages used to keep their files.', 'smbb-signconnect'),
    'actions' => array(
        array(
            'label' => __('Documents', 'smbb-signconnect'),
            'url' => $documents_url,
            'variant' => 'primary',
        ),
        array(
            'label' => __('Storages', 'smbb-signconnect'),
            'url' => $storages_url,
            'variant' => 'secondary',
        ),
        array(
            'label' => __('Templates', 'smbb-signconnect'),
            'url' => $templates_url,
            'variant' => 'secondary',
        ),
        array(
            'label' => __('Stamps', 'smbb-signconnect'),
            'url' => $stamps_url,
            'variant' => 'secondary',
        ),
        array(
            'label' => __('Sender signatures', 'smbb-signconnect'),
            'url' => $sender_signatures_url,
            'variant' => 'secondary',
        ),
    ),
    'tiles' => array(
        array(
            'icon' => 'dashicons-media-document',
            'label' => __('Documents', 'smbb-signconnect'),
            'meta' => sprintf(
                /* translators: 1: document count, 2: total file size. */
                _n('%1$s document, %2$s stored', '%1$s documents, %2$s stored', $document_count, 'smbb-signconnect'),
                number_format_i18n($document_count),
                $document_total_size_label
            ),
        ),
        array(
            'icon' => 'dashicons-cloud',
            'label' => __('Storages', 'smbb-signconnect'),
            'meta' => __('S3 endpoint, bucket, credentials', 'smbb-signconnect'),
        ),
        array(
            'icon' => 'dashicons-layout',
            'label' => __('Templates', 'smbb-signconnect'),
            'meta' => sprintf(
                /* translators: %s: template count. */
                _n('%s reusable layout', '%s reusable layouts', $template_count, 'smbb-signconnect'),
                number_format_i18n($template_count)
            ),
        ),
    ),
));

echo $dashboard_ui->split(array(
    $dashboard_ui->panel(
        __('Documents', 'smbb-signconnect'),
        __('Access the document table and link each record to a storage path.', 'smbb-signconnect'),
        '<p><a class="button button-primary" href="' . esc_url($documents_url) . '">' . esc_html__('Open documents', 'smbb-signconnect') . '</a></p>'
    ),
    $dashboard_ui->panel(
        __('Storages', 'smbb-signconnect'),
        __('Manage and test S3-compatible storage connections.', 'smbb-signconnect'),
        '<p><a class="button" href="' . esc_url($storages_url) . '">' . esc_html__('Open storages', 'smbb-signconnect') . '</a></p>'
    ),
    $dashboard_ui->panel(
        __('Templates', 'smbb-signconnect'),
        __('Prepare reusable detection and signature zones from a sample PDF.', 'smbb-signconnect'),
        '<p><a class="button" href="' . esc_url($templates_url) . '">' . esc_html__('Open templates', 'smbb-signconnect') . '</a></p>'
    ),
    $dashboard_ui->panel(
        __('Stamps', 'smbb-signconnect'),
        __('Manage sender stamp images and select one on each user profile.', 'smbb-signconnect'),
        '<p><a class="button" href="' . esc_url($stamps_url) . '">' . esc_html__('Open stamps', 'smbb-signconnect') . '</a></p>'
    ),
    $dashboard_ui->panel(
        __('Sender signatures', 'smbb-signconnect'),
        __('Audit or remove sender signatures captured when documents are sent.', 'smbb-signconnect'),
        '<p><a class="button" href="' . esc_url($sender_signatures_url) . '">' . esc_html__('Open sender signatures', 'smbb-signconnect') . '</a></p>'
    ),
));

echo '</div>';
