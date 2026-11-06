<?php

namespace Smbb\Tomo\CodeTool;

defined('ABSPATH') || exit;

/**
 * Menu Documents : identifiants stables, types connus, état actif.
 */
final class DocumentsHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        unset($context);

        // Documents : identifiant stable (tiré du titre la première fois), type connu.
        if (isset($data['documents']) && is_array($data['documents'])) {
            $docs = array();
            $keys = array();

            foreach ($data['documents'] as $row) {
                if (!is_array($row) || trim((string) (isset($row['title']) ? $row['title'] : '')) === '') {
                    continue;
                }

                $key = sanitize_key(isset($row['key']) && $row['key'] !== '' ? $row['key'] : sanitize_title($row['title']));
                $base = $key !== '' ? $key : 'document';
                $key = $base;
                $n = 1;

                while (isset($keys[$key])) {
                    $key = $base . '-' . (++$n);
                }

                $keys[$key] = true;
                $docs[] = array(
                    'key' => $key,
                    'title' => sanitize_text_field($row['title']),
                    'kind' => isset($row['kind']) && $row['kind'] === 'consent' ? 'consent' : 'signature',
                    'text' => isset($row['text']) ? sanitize_textarea_field($row['text']) : '',
                    'required' => !empty($row['required']) ? 1 : 0,
                    'active' => !isset($row['active']) || !empty($row['active']) ? 1 : 0,
                );
            }

            $data['documents'] = $docs;
        }

        if (isset($data['documents_onsite_note'])) {
            $data['documents_onsite_note'] = sanitize_text_field($data['documents_onsite_note']);
        }

        return $data;
    }
}
