<?php

namespace Smbb\SignConnect\Service;

use Smbb\SignConnect\Support\SignConnectSettings;

defined('ABSPATH') || exit;

final class DocumentAiOpenAiClient
{
    private $raw_debug = array();
    private $connector;

    public function ask($prompt, $include_signature_zone, array $page_image = array(), array $public_page_image = array())
    {
        $this->raw_debug = array();
        $client = $this->connector($include_signature_zone);
        $this->connector = $client;
        $options = $this->options($include_signature_zone);

        if ($include_signature_zone && !empty($public_page_image['success']) && !empty($public_page_image['path']) && method_exists($client, 'askWithImageFile')) {
            return array('answer' => $client->askWithImageFile($prompt, $public_page_image['path'], 'image/png', $options), 'transport' => 'ai_temp_file');
        }

        if ($include_signature_zone && !empty($public_page_image['success']) && !empty($public_page_image['path'])) {
            return array('answer' => $this->askWithImageFile($prompt, $public_page_image['path'], $options), 'transport' => 'raw_ai_temp_file_fallback');
        }

        if ($include_signature_zone && !empty($page_image['success']) && !empty($page_image['path']) && method_exists($client, 'askWithImageFile')) {
            return array(
                'answer' => $client->askWithImageFile($prompt, $page_image['path'], isset($page_image['mime_type']) ? (string) $page_image['mime_type'] : 'image/png', $options),
                'transport' => 'local_file_fallback',
            );
        }

        if ($include_signature_zone && !empty($page_image['success']) && !empty($page_image['path'])) {
            return array('answer' => $this->askWithImageFile($prompt, $page_image['path'], $options), 'transport' => 'raw_local_file_fallback');
        }

        return array('answer' => $client->ask($prompt, $options), 'transport' => 'text_only');
    }

    public function connectorDebug()
    {
        return $this->connector && method_exists($this->connector, 'getLastResponseDebug') ? $this->connector->getLastResponseDebug() : array();
    }

    public function rawDebug()
    {
        return $this->raw_debug;
    }

    private function connector($include_signature_zone)
    {
        $settings = SignConnectSettings::all();
        $model = isset($settings['openai_model']) && trim((string) $settings['openai_model']) !== ''
            ? trim((string) $settings['openai_model'])
            : ($include_signature_zone ? 'gpt-5' : 'gpt-5-mini');

        return new \Smbb\WpCodeTool\Connector\SmbbOpenAiTextClient(SignConnectSettings::openAiApiKey(), $model);
    }

    private function options($include_signature_zone)
    {
        if ($include_signature_zone) {
            return array(
                'reasoning_effort' => 'medium',
                'max_output_tokens' => 5000,
                'verbosity' => 'low',
                'image_detail' => 'high',
                'timeout' => 60,
            );
        }

        return array(
            'reasoning_effort' => 'minimal',
            'max_output_tokens' => 350,
            'verbosity' => 'low',
            'timeout' => 25,
        );
    }

    private function askWithImageFile($prompt, $image_path, array $options)
    {
        if (!is_readable($image_path)) {
            throw new \RuntimeException('Image file is not readable.');
        }

        $image_data = file_get_contents($image_path);

        if ($image_data === false || $image_data === '') {
            throw new \RuntimeException('Image file is empty.');
        }

        $settings = SignConnectSettings::all();
        $model = isset($settings['openai_model']) && trim((string) $settings['openai_model']) !== ''
            ? trim((string) $settings['openai_model'])
            : 'gpt-5';

        $payload = array(
            'model' => $model,
            'input' => array(array(
                'role' => 'user',
                'content' => array(
                    array('type' => 'input_text', 'text' => (string) $prompt),
                    array(
                        'type' => 'input_image',
                        'detail' => isset($options['image_detail']) ? (string) $options['image_detail'] : 'high',
                        'image_url' => 'data:image/png;base64,' . base64_encode($image_data),
                    ),
                ),
            )),
            'reasoning' => array('effort' => isset($options['reasoning_effort']) ? (string) $options['reasoning_effort'] : 'medium'),
            'max_output_tokens' => isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 5000,
            'text' => array(
                'format' => array('type' => 'text'),
                'verbosity' => isset($options['verbosity']) ? (string) $options['verbosity'] : 'low',
            ),
        );
        $this->raw_debug = array(
            'model' => $model,
            'has_image' => true,
            'image_detail' => isset($options['image_detail']) ? (string) $options['image_detail'] : 'high',
            'image_size' => strlen($image_data),
            'max_output_tokens' => $payload['max_output_tokens'],
            'reasoning_effort' => $payload['reasoning']['effort'],
        );

        $response = wp_remote_post('https://api.openai.com/v1/responses', array(
            'timeout' => isset($options['timeout']) ? (int) $options['timeout'] : 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . SignConnectSettings::openAiApiKey(),
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            throw new \RuntimeException($response->get_error_message());
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $this->raw_debug['status_code'] = $status_code;
        $this->raw_debug['body_preview'] = substr($body, 0, 8000);

        if ($status_code < 200 || $status_code >= 300) {
            throw new \RuntimeException('OpenAI API error ' . $status_code . ' - ' . $body);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenAI API returned invalid JSON.');
        }

        $this->raw_debug['response_status'] = isset($decoded['status']) ? (string) $decoded['status'] : '';
        $this->raw_debug['incomplete_details'] = isset($decoded['incomplete_details']) ? $decoded['incomplete_details'] : null;
        $text = $this->extractOutputText($decoded);
        $this->raw_debug['extracted_text_length'] = strlen($text);

        if ($text === '') {
            throw new \RuntimeException('OpenAI API returned an empty response.');
        }

        return $text;
    }

    private function extractOutputText(array $response)
    {
        if (!empty($response['output_text'])) {
            return trim((string) $response['output_text']);
        }

        if (empty($response['output']) || !is_array($response['output'])) {
            return '';
        }

        $parts = array();

        foreach ($response['output'] as $item) {
            if (empty($item['content']) || !is_array($item['content'])) {
                continue;
            }

            foreach ($item['content'] as $content) {
                if (isset($content['text'])) {
                    $parts[] = (string) $content['text'];
                }
            }
        }

        return trim(implode("\n", $parts));
    }
}
