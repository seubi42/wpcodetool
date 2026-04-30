<?php

namespace Smbb\WpCodeTool\Resource;

defined('ABSPATH') || exit;

/**
 * Valide un modele JSON brut avant de l'exposer comme ressource runtime.
 */
final class ResourceModelValidator
{
    private const STORAGE_TYPES = array('custom_table', 'option', 'none');
    private const COLUMN_TYPES = array(
        'bigint',
        'bool',
        'boolean',
        'char',
        'date',
        'datetime',
        'decimal',
        'double',
        'float',
        'int',
        'integer',
        'json',
        'longtext',
        'mediumint',
        'mediumtext',
        'smallint',
        'text',
        'time',
        'timestamp',
        'tinyint',
        'varchar',
    );

    /**
     * @return array<int,array<string,string>>
     */
    public function validate(array $data, $plugin_dir = '', $model_file = '')
    {
        $errors = array();
        $file = (string) $model_file;
        $name = isset($data['name']) ? sanitize_key((string) $data['name']) : '';

        if ($name === '') {
            return array($this->error($file, 'La ressource doit declarer un champ "name" valide.'));
        }

        if (isset($data['storage']) && !is_array($data['storage'])) {
            $errors[] = $this->error($file, 'Le bloc "storage" doit etre un objet JSON.');

            return $errors;
        }

        $storage = isset($data['storage']) && is_array($data['storage']) ? $data['storage'] : array();
        $storage_type = isset($storage['type']) ? sanitize_key((string) $storage['type']) : 'custom_table';

        if (!in_array($storage_type, self::STORAGE_TYPES, true)) {
            $errors[] = $this->error(
                $file,
                'Le champ "storage.type" doit valoir "custom_table", "option" ou "none".'
            );

            return $errors;
        }

        if (isset($data['admin']) && !is_array($data['admin'])) {
            $errors[] = $this->error($file, 'Le bloc "admin" doit etre un objet JSON.');
        }

        if (isset($data['hooks']) && !is_array($data['hooks'])) {
            $errors[] = $this->error($file, 'Le bloc "hooks" doit etre un objet JSON.');
        } elseif (isset($data['hooks']) && is_array($data['hooks'])) {
            $errors = array_merge($errors, $this->validateHooks($data['hooks'], $plugin_dir, $file));
        }

        if (isset($data['api']) && !is_array($data['api'])) {
            $errors[] = $this->error($file, 'Le bloc "api" doit etre un objet JSON.');
        } elseif (isset($data['api']) && is_array($data['api'])) {
            $errors = array_merge($errors, $this->validateApi($data['api'], $plugin_dir, $file));
        }

        switch ($storage_type) {
            case 'custom_table':
                $errors = array_merge($errors, $this->validateCustomTable($data, $storage, $file));
                break;

            case 'option':
                $errors = array_merge($errors, $this->validateOption($storage, $file));
                break;
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $storage
     * @return array<int,array<string,string>>
     */
    private function validateCustomTable(array $data, array $storage, $file)
    {
        $errors = array();

        if (!isset($data['columns']) || !is_array($data['columns']) || empty($data['columns'])) {
            return array($this->error($file, 'Une ressource "custom_table" doit declarer un bloc "columns" non vide.'));
        }

        foreach ($data['columns'] as $column_name => $definition) {
            $normalized_name = sanitize_key((string) $column_name);

            if ($normalized_name === '') {
                $errors[] = $this->error($file, 'Chaque colonne doit avoir un nom technique non vide.');
                continue;
            }

            if (!is_array($definition)) {
                $errors[] = $this->error($file, 'La definition de colonne "' . $column_name . '" doit etre un objet JSON.');
                continue;
            }

            $type = isset($definition['type']) ? strtolower((string) $definition['type']) : 'varchar';

            if (!in_array($type, self::COLUMN_TYPES, true)) {
                $errors[] = $this->error($file, 'Le type de colonne "' . $column_name . '" est inconnu : "' . $type . '".');
            }
        }

        $primary_key = isset($storage['primaryKey']) ? sanitize_key((string) $storage['primaryKey']) : 'id';

        if ($primary_key === '') {
            $errors[] = $this->error($file, 'Le champ "storage.primaryKey" doit etre un identifiant valide.');
        } elseif (!array_key_exists($primary_key, $data['columns'])) {
            $errors[] = $this->error($file, 'La cle primaire "' . $primary_key . '" doit correspondre a une colonne declaree.');
        }

        $table = isset($storage['table']) ? trim((string) $storage['table']) : '';

        if ($table !== '' && trim((string) preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace('-', '_', $table)))) === '') {
            $errors[] = $this->error($file, 'Le champ "storage.table" doit produire un nom SQL non vide.');
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $storage
     * @return array<int,array<string,string>>
     */
    private function validateOption(array $storage, $file)
    {
        $errors = array();
        $option_name = isset($storage['optionName']) ? trim((string) $storage['optionName']) : '';

        if ($option_name === '') {
            $errors[] = $this->error($file, 'Une ressource "option" doit declarer un champ "storage.optionName" non vide.');
        }

        if (isset($storage['default']) && !is_array($storage['default'])) {
            $errors[] = $this->error($file, 'Le champ "storage.default" doit etre un objet JSON.');
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $hooks
     * @return array<int,array<string,string>>
     */
    private function validateHooks(array $hooks, $plugin_dir, $file)
    {
        $errors = array();

        if (isset($hooks['file']) && !is_scalar($hooks['file'])) {
            $errors[] = $this->error($file, 'Le champ "hooks.file" doit etre une chaine.');
        }

        if (isset($hooks['class']) && !is_scalar($hooks['class'])) {
            $errors[] = $this->error($file, 'Le champ "hooks.class" doit etre une chaine.');
        }

        if (!empty($hooks['file']) && $plugin_dir !== '') {
            $path = rtrim((string) $plugin_dir, '/\\')
                . DIRECTORY_SEPARATOR
                . 'codetool'
                . DIRECTORY_SEPARATOR
                . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string) $hooks['file']);

            if (!is_readable($path)) {
                $errors[] = $this->error($file, 'Le fichier de hooks est introuvable : "' . (string) $hooks['file'] . '".');
            }
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $api
     * @return array<int,array<string,string>>
     */
    private function validateApi(array $api, $plugin_dir, $file)
    {
        $errors = array();

        if (isset($api['namespace']) && trim((string) $api['namespace']) === '') {
            $errors[] = $this->error($file, 'Le champ "api.namespace" ne peut pas etre vide.');
        }

        if (isset($api['base']) && trim((string) $api['base']) === '') {
            $errors[] = $this->error($file, 'Le champ "api.base" ne peut pas etre vide.');
        }

        if (isset($api['actions']) && !is_array($api['actions'])) {
            $errors[] = $this->error($file, 'Le bloc "api.actions" doit etre un objet JSON.');
        }

        if (!isset($api['custom'])) {
            return $errors;
        }

        if (!is_array($api['custom'])) {
            $errors[] = $this->error($file, 'Le bloc "api.custom" doit etre un objet JSON.');

            return $errors;
        }

        foreach ($api['custom'] as $name => $route) {
            $route_name = sanitize_key((string) $name);

            if ($route_name === '') {
                $errors[] = $this->error($file, 'Chaque route custom API doit avoir un nom technique non vide.');
                continue;
            }

            if (!is_array($route)) {
                $errors[] = $this->error($file, 'La route custom "' . $name . '" doit etre un objet JSON.');
                continue;
            }

            $method = strtoupper(isset($route['method']) ? (string) $route['method'] : 'GET');

            if (!in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE'), true)) {
                $errors[] = $this->error($file, 'La route custom "' . $name . '" utilise une methode HTTP non supportee.');
            }

            if (empty($route['path']) || !is_scalar($route['path']) || strpos((string) $route['path'], '/') !== 0) {
                $errors[] = $this->error($file, 'La route custom "' . $name . '" doit declarer un champ "path" commencant par "/".');
            }

            if (empty($route['class']) || !is_scalar($route['class'])) {
                $errors[] = $this->error($file, 'La route custom "' . $name . '" doit declarer un champ "class" non vide.');
            }

            if (empty($route['callback']) || !is_scalar($route['callback'])) {
                $errors[] = $this->error($file, 'La route custom "' . $name . '" doit declarer un champ "callback" non vide.');
            }

            if (isset($route['args']) && !is_array($route['args'])) {
                $errors[] = $this->error($file, 'Le bloc "args" de la route custom "' . $name . '" doit etre un objet JSON.');
            }

            if (!empty($route['file']) && $plugin_dir !== '') {
                $path = rtrim((string) $plugin_dir, '/\\')
                    . DIRECTORY_SEPARATOR
                    . 'codetool'
                    . DIRECTORY_SEPARATOR
                    . str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string) $route['file']);

                if (!is_readable($path)) {
                    $errors[] = $this->error($file, 'Le fichier PHP de la route custom "' . $name . '" est introuvable.');
                }
            }
        }

        return $errors;
    }

    /**
     * @return array<string,string>
     */
    private function error($file, $message)
    {
        return array(
            'file' => (string) $file,
            'message' => (string) $message,
        );
    }
}
