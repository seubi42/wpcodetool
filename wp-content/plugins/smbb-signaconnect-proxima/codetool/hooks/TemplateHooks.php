<?php

namespace Smbb\SignConnectProxima\CodeTool;

use Smbb\SignConnectProxima\Repository\AuditLog;
use Smbb\SignConnectProxima\Repository\Lookup;
use Smbb\SignConnectProxima\Repository\Templates;
use Smbb\SignConnectProxima\Support\Input;
use Smbb\SignConnectProxima\Support\Levels;
use Smbb\SignConnectProxima\Support\Placeholders;

defined('ABSPATH') || exit;

final class TemplateHooks
{
    public function beforeValidate(array $data, array $context = array())
    {
        $data = Input::stripManaged($data);
        $data = Input::trim($data, array('name'));
        $data = Input::nullableIds($data, array('sample_pdf_attachment_id'));
        unset($data['match_count']);

        // Champs perso : repeater de l'admin (tableau de lignes label / required) ou JSON de l'app.
        // Le repeater n'a pas de clé : on reprend celle du champ de même libellé déjà enregistré,
        // pour que les zones Destinataire qui le captent (custom:clé) restent branchées.
        if (isset($data['custom_fields'])) {
            $rows = is_array($data['custom_fields']) ? array_values($data['custom_fields']) : json_decode(trim((string) $data['custom_fields']) ?: '[]', true);
            $current = isset($context['current']) && is_array($context['current']) ? Templates::customFields(isset($context['current']['custom_fields']) ? $context['current']['custom_fields'] : '') : array();
            $known = array();

            foreach ($current as $field) {
                $known[Placeholders::key($field['label'])] = $field['key'];
            }

            foreach (is_array($rows) ? $rows : array() as $index => $row) {
                if (is_array($row) && empty($row['key']) && isset($row['label']) && isset($known[Placeholders::key($row['label'])])) {
                    $rows[$index]['key'] = $known[Placeholders::key($row['label'])];
                }
            }

            $data['custom_fields'] = wp_json_encode(Templates::customFields(is_array($rows) ? $rows : array()), JSON_UNESCAPED_UNICODE);
        }

        if (isset($data['signed_mail_cc']) && is_string($data['signed_mail_cc'])) {
            $data['signed_mail_cc'] = implode(', ', Templates::emails($data['signed_mail_cc']));
        }

        // Les zones arrivent en JSON texte, rempli par l'éditeur visuel (ou à la main).
        foreach (array('detection_zones', 'field_zones') as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]) === '' ? '[]' : trim($data[$key]);
            }
        }

        return $data;
    }

    public function validate(array $data, array $context = array())
    {
        $errors = array();

        if (empty($data['organization_id']) || !array_key_exists((int) $data['organization_id'], Lookup::organizations())) {
            $errors['organization_id'] = __('Choisissez une organisation.', 'smbb-signaconnect-proxima');
        }

        if (empty($data['name'])) {
            $errors['name'] = __('Le nom du modèle est obligatoire.', 'smbb-signaconnect-proxima');
        }

        if (isset($data['suggested_level']) && !in_array($data['suggested_level'], Levels::all(), true)) {
            $errors['suggested_level'] = __('Niveau inconnu.', 'smbb-signaconnect-proxima');
        }

        foreach (array('detection_zones', 'field_zones') as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $decoded = json_decode($data[$key], true);

                if (!is_array($decoded)) {
                    $errors[$key] = __('JSON invalide : une liste de zones est attendue.', 'smbb-signaconnect-proxima');
                }
            }
        }

        return $errors;
    }

    public function afterSave(array $row, array $context = array())
    {
        $is_create = isset($context['action']) && $context['action'] === 'create';

        AuditLog::record(
            $is_create ? 'template_created' : 'template_updated',
            sprintf(
                $is_create ? __('Modèle « %s » créé.', 'smbb-signaconnect-proxima') : __('Modèle « %s » modifié.', 'smbb-signaconnect-proxima'),
                isset($row['name']) ? $row['name'] : ''
            ),
            array('template_id' => isset($row['id']) ? (int) $row['id'] : 0),
            isset($row['organization_id']) ? (int) $row['organization_id'] : null
        );
    }

    public function beforeDelete(array $row, array $context = array())
    {
        AuditLog::record('template_deleted', sprintf(__('Modèle « %s » supprimé.', 'smbb-signaconnect-proxima'), isset($row['name']) ? $row['name'] : ''), array(), isset($row['organization_id']) ? (int) $row['organization_id'] : null);

        return true;
    }
}
