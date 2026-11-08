<?php

namespace Smbb\Passflow\CodeTool;

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
                $gdpr = !empty($row['gdpr']);
                $text = isset($row['text']) ? sanitize_textarea_field($row['text']) : '';
                $docs[] = array(
                    'key' => $key,
                    'title' => sanitize_text_field($row['title']),
                    // Rôle « Consentement RGPD » : toujours une question oui / non, avec un texte proposé s'il est vide.
                    'kind' => $gdpr ? 'consent' : (isset($row['kind']) && in_array($row['kind'], array('consent', 'form'), true) ? $row['kind'] : 'signature'),
                    'text' => $gdpr && trim($text) === '' ? \Smbb\Passflow\Service\Documents::gdprDefaultText() : $text,
                    'gdpr' => $gdpr ? 1 : 0,
                    'required' => !empty($row['required']) ? 1 : 0,
                    'active' => !isset($row['active']) || !empty($row['active']) ? 1 : 0,
                    'form_id' => isset($row['form_id']) ? absint($row['form_id']) : 0,
                    'form_notify' => !empty($row['form_notify']) ? 1 : 0,
                );
            }

            $data['documents'] = $docs;
        }

        if (isset($data['documents_onsite_note'])) {
            $data['documents_onsite_note'] = sanitize_text_field($data['documents_onsite_note']);
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        unset($context);
        $errors = array();
        $gdpr = array();

        foreach (isset($data['documents']) && is_array($data['documents']) ? $data['documents'] : array() as $row) {
            if (is_array($row) && !empty($row['gdpr']) && !empty($row['active'])) {
                $gdpr[] = $row['title'];
            }
        }

        if (count($gdpr) > 1) {
            $errors['documents'] = sprintf(__('Un seul document actif peut porter le rôle « Consentement RGPD » (%s).', 'smbb-passflow'), implode(', ', $gdpr));

            return $errors;
        }

        foreach (isset($data['documents']) && is_array($data['documents']) ? $data['documents'] : array() as $row) {
            if (is_array($row) && ($row['kind'] ?? '') === 'form' && empty($row['form_id'])) {
                $errors['documents'] = sprintf(__('« %s » : choisissez le formulaire à remplir (type « Formulaire seul »).', 'smbb-passflow'), $row['title']);
                break;
            }
        }

        return $errors;
    }
}
