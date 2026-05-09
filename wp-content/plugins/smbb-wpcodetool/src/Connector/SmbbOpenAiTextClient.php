<?php

namespace Smbb\WpCodeTool\Connector;

use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Petit client OpenAI cote serveur pour les plugins metier SMBB.
 *
 * Il utilise l'API Responses, recommandee pour les nouveaux developpements, et
 * garde volontairement une surface simple : envoyer une consigne texte, recevoir
 * une reponse texte courte. Les cles API ne doivent jamais sortir cote client.
 */
final class SmbbOpenAiTextClient
{
    private $api_key;
    private $model;
    private $api_url;
    private $last_response_debug = array();

    public function __construct($api_key, $model = 'gpt-5-mini', $api_url = 'https://api.openai.com/v1/responses')
    {
        $this->api_key = trim((string) $api_key);
        $this->model = trim((string) $model) !== '' ? trim((string) $model) : 'gpt-5-mini';
        $this->api_url = trim((string) $api_url) !== '' ? trim((string) $api_url) : 'https://api.openai.com/v1/responses';
    }

    public static function fromSettings(array $settings)
    {
        return new self(
            isset($settings['openai_api_key']) ? $settings['openai_api_key'] : '',
            isset($settings['openai_model']) ? $settings['openai_model'] : 'gpt-5-mini'
        );
    }

    public function ask($prompt, array $options = array())
    {
        if ($this->api_key === '') {
            throw new RuntimeException('OpenAI API key is missing.');
        }

        $payload = array(
            'model' => $this->model,
            'input' => (string) $prompt,
            'reasoning' => array(
                'effort' => isset($options['reasoning_effort']) ? (string) $options['reasoning_effort'] : 'minimal',
            ),
            'max_output_tokens' => isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 180,
        );
        $payload = $this->applyTextOptions($payload, $options);

        return $this->request($payload, $options);
    }

    public function askWithPdfFile($prompt, $pdf_path, $filename, array $options = array())
    {
        if ($this->api_key === '') {
            throw new RuntimeException('OpenAI API key is missing.');
        }

        if (!is_readable($pdf_path)) {
            throw new RuntimeException('PDF file is not readable.');
        }

        $pdf_data = file_get_contents($pdf_path);

        if ($pdf_data === false || $pdf_data === '') {
            throw new RuntimeException('PDF file is empty.');
        }

        $payload = array(
            'model' => $this->model,
            'input' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_file',
                            'filename' => sanitize_file_name((string) $filename) ?: 'document.pdf',
                            'file_data' => base64_encode($pdf_data),
                        ),
                        array(
                            'type' => 'input_text',
                            'text' => (string) $prompt,
                        ),
                    ),
                ),
            ),
            'reasoning' => array(
                'effort' => isset($options['reasoning_effort']) ? (string) $options['reasoning_effort'] : 'minimal',
            ),
            'max_output_tokens' => isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 450,
        );
        $payload = $this->applyTextOptions($payload, $options);

        return $this->request($payload, $options);
    }

    public function askWithImageFile($prompt, $image_path, $mime_type = 'image/png', array $options = array())
    {
        if ($this->api_key === '') {
            throw new RuntimeException('OpenAI API key is missing.');
        }

        if (!is_readable($image_path)) {
            throw new RuntimeException('Image file is not readable.');
        }

        $image_data = file_get_contents($image_path);

        if ($image_data === false || $image_data === '') {
            throw new RuntimeException('Image file is empty.');
        }

        $mime_type = trim((string) $mime_type) !== '' ? trim((string) $mime_type) : 'image/png';
        $payload = array(
            'model' => $this->model,
            'input' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => (string) $prompt,
                        ),
                        array(
                            'type' => 'input_image',
                            'detail' => isset($options['image_detail']) ? (string) $options['image_detail'] : 'high',
                            'image_url' => 'data:' . $mime_type . ';base64,' . base64_encode($image_data),
                        ),
                    ),
                ),
            ),
            'reasoning' => array(
                'effort' => isset($options['reasoning_effort']) ? (string) $options['reasoning_effort'] : 'minimal',
            ),
            'max_output_tokens' => isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 450,
        );
        $payload = $this->applyTextOptions($payload, $options);

        return $this->request($payload, $options);
    }

    public function askWithImageUrl($prompt, $image_url, array $options = array())
    {
        if ($this->api_key === '') {
            throw new RuntimeException('OpenAI API key is missing.');
        }

        $image_url = trim((string) $image_url);

        if ($image_url === '') {
            throw new RuntimeException('Image URL is missing.');
        }

        $payload = array(
            'model' => $this->model,
            'input' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'input_text',
                            'text' => (string) $prompt,
                        ),
                        array(
                            'type' => 'input_image',
                            'detail' => isset($options['image_detail']) ? (string) $options['image_detail'] : 'high',
                            'image_url' => $image_url,
                        ),
                    ),
                ),
            ),
            'reasoning' => array(
                'effort' => isset($options['reasoning_effort']) ? (string) $options['reasoning_effort'] : 'minimal',
            ),
            'max_output_tokens' => isset($options['max_output_tokens']) ? (int) $options['max_output_tokens'] : 450,
        );
        $payload = $this->applyTextOptions($payload, $options);

        return $this->request($payload, $options);
    }


    public function getLastResponseDebug()
    {
        return $this->last_response_debug;
    }

    private function request(array $payload, array $options = array())
    {
        $this->last_response_debug = array(
            'model' => isset($payload['model']) ? (string) $payload['model'] : '',
            'max_output_tokens' => isset($payload['max_output_tokens']) ? (int) $payload['max_output_tokens'] : null,
            'reasoning_effort' => isset($payload['reasoning']['effort']) ? (string) $payload['reasoning']['effort'] : '',
            'text_verbosity' => isset($payload['text']['verbosity']) ? (string) $payload['text']['verbosity'] : '',
            'input_summary' => $this->inputSummary(isset($payload['input']) ? $payload['input'] : null),
        );

        $response = wp_remote_post($this->api_url, array(
            'timeout' => isset($options['timeout']) ? (int) $options['timeout'] : 25,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            throw new RuntimeException($response->get_error_message());
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $this->last_response_debug['status_code'] = $status_code;
        $this->last_response_debug['body_preview'] = substr($body, 0, 8000);

        if ($status_code < 200 || $status_code >= 300) {
            throw new RuntimeException('OpenAI API error ' . $status_code . ' - ' . $body);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI API returned invalid JSON.');
        }

        $this->last_response_debug['response_id'] = isset($decoded['id']) ? (string) $decoded['id'] : '';
        $this->last_response_debug['response_status'] = isset($decoded['status']) ? (string) $decoded['status'] : '';
        $this->last_response_debug['incomplete_details'] = isset($decoded['incomplete_details']) ? $decoded['incomplete_details'] : null;

        $text = $this->extractOutputText($decoded);
        $this->last_response_debug['extracted_text_length'] = strlen($text);

        if ($text === '') {
            throw new RuntimeException('OpenAI API returned an empty response.');
        }

        return $text;
    }

    private function applyTextOptions(array $payload, array $options)
    {
        if (!empty($options['verbosity'])) {
            $payload['text'] = array(
                'format' => array(
                    'type' => 'text',
                ),
                'verbosity' => (string) $options['verbosity'],
            );
        }

        return $payload;
    }

    private function inputSummary($input)
    {
        $summary = array(
            'has_image' => false,
            'image_detail' => '',
            'has_file' => false,
            'text_length' => 0,
        );

        if (is_string($input)) {
            $summary['text_length'] = strlen($input);

            return $summary;
        }

        if (!is_array($input)) {
            return $summary;
        }

        foreach ($input as $message) {
            if (empty($message['content']) || !is_array($message['content'])) {
                continue;
            }

            foreach ($message['content'] as $content) {
                $type = isset($content['type']) ? (string) $content['type'] : '';

                if ($type === 'input_text') {
                    $summary['text_length'] += isset($content['text']) ? strlen((string) $content['text']) : 0;
                } elseif ($type === 'input_image') {
                    $summary['has_image'] = true;
                    $summary['image_detail'] = isset($content['detail']) ? (string) $content['detail'] : '';
                } elseif ($type === 'input_file') {
                    $summary['has_file'] = true;
                }
            }
        }

        return $summary;
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
