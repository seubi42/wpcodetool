<?php

namespace Smbb\Sample\CodeTool;

use Smbb\WpCodeTool\Support\RequestInput;

defined('ABSPATH') || exit;

/**
 * Exemple de classe cible pour les routes publiques CodeTool.
 */
final class SamplePublicRoutes
{
    public function ping($info)
    {
        return array(
            'success' => true,
            'message' => 'pong',
            'source' => 'smbb-sample public route ' . $info,
        );
    }

    public function preview($id)
    {
        return array(
            'success' => true,
            'id' => (string) $id,
            'message' => sprintf('Preview requested for %s.', (string) $id),
        );
    }

    public function previewImage($id)
    {
        $request_input = new RequestInput();

        return array(
            'success' => true,
            'id' => (string) $id,
            'client_ip' => $request_input->get_client_ip(),
            'input' => $request_input->get_input_json(),
            'token' => $request_input->get_bearer_token(),
            'message' => sprintf('Image preview requested for %s.', (string) $id),
        );
    }
}
