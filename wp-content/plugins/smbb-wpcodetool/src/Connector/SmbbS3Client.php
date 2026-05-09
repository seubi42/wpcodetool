<?php

namespace Smbb\WpCodeTool\Connector;

use Aws\S3\S3Client;
use RuntimeException;

defined('ABSPATH') || exit;

/**
 * Thin wrapper around the AWS S3 client for SMBB business plugins.
 *
 * The toolkit does not bundle aws/aws-sdk-php. Install or load the SDK in the
 * host project/plugin before instantiating this connector.
 */
final class SmbbS3Client
{
    private $s3;
    private $bucket;
    private $prefix;

    public function __construct($endpoint, $access_key, $secret_key, $bucket, $use_path_style_endpoint, $region, $prefix = '')
    {
        if (!class_exists(S3Client::class)) {
            throw new RuntimeException('AWS SDK for PHP is required to use SmbbS3Client.');
        }

        $this->s3 = new S3Client(array(
            'version' => 'latest',
            'region' => (string) $region,
            'endpoint' => (string) $endpoint,
            'use_path_style_endpoint' => (bool) $use_path_style_endpoint,
            'credentials' => array(
                'key' => (string) $access_key,
                'secret' => (string) $secret_key,
            ),
        ));

        $this->bucket = (string) $bucket;
        $this->prefix = trim((string) $prefix, '/');
    }

    public static function fromSettings(array $settings)
    {
        return new self(
            isset($settings['endpoint']) ? $settings['endpoint'] : '',
            isset($settings['access_key']) ? $settings['access_key'] : '',
            isset($settings['secret_key']) ? $settings['secret_key'] : '',
            isset($settings['bucket']) ? $settings['bucket'] : '',
            !empty($settings['use_path_style_endpoint']),
            isset($settings['region']) ? $settings['region'] : 'us-east-1',
            isset($settings['base_prefix']) ? $settings['base_prefix'] : ''
        );
    }

    public function client()
    {
        return $this->s3;
    }

    public function bucket()
    {
        return $this->bucket;
    }

    public function testConnection()
    {
        $args = array(
            'Bucket' => $this->bucket,
            'MaxKeys' => 1,
        );

        if ($this->prefix !== '') {
            $args['Prefix'] = $this->prefix . '/';
        }

        return $this->s3->listObjectsV2($args);
    }

    public function key($key)
    {
        $key = ltrim((string) $key, '/');

        if ($this->prefix === '') {
            return $key;
        }

        return $this->prefix . '/' . $key;
    }

    public function putObject($key, $body, array $options = array())
    {
        return $this->s3->putObject(array_merge($options, array(
            'Bucket' => $this->bucket,
            'Key' => $this->key($key),
            'Body' => $body,
        )));
    }

    public function getObject($key, array $options = array())
    {
        return $this->s3->getObject(array_merge($options, array(
            'Bucket' => $this->bucket,
            'Key' => $this->key($key),
        )));
    }

    public function deleteObject($key, array $options = array())
    {
        return $this->s3->deleteObject(array_merge($options, array(
            'Bucket' => $this->bucket,
            'Key' => $this->key($key),
        )));
    }

    public function temporaryUrl($key, $expires = '+10 minutes')
    {
        $command = $this->s3->getCommand('GetObject', array(
            'Bucket' => $this->bucket,
            'Key' => $this->key($key),
        ));
        $request = $this->s3->createPresignedRequest($command, $expires);

        return (string) $request->getUri();
    }
}
