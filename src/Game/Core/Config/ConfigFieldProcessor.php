<?php

namespace App\Game\Core\Config;

use App\Game\Core\HTTP;
use App\Game\Core\Config\ConfigFieldSchema;

/**
 * Processor for config fields
 * Unifies the processing of all config parameters
 */
class ConfigFieldProcessor
{
    /**
     * Processes POST data according to schema
     */
    public static function processPostData(array $schema, \App\Game\Core\HTTP $http): array
    {
        $processed = [];

        foreach ($schema as $fieldName => $fieldConfig) {
            // Hidden fields (counters like last_planet_pos) are not part of the form.
            if (($fieldConfig['type'] ?? '') === ConfigFieldSchema::TYPE_NONE) {
                continue;
            }
            $processed[$fieldName] = self::processField($fieldName, $fieldConfig, $http);
        }

        return $processed;
    }

    /**
     * Processes a single field
     */
    private static function processField(string $fieldName, array $config, \App\Game\Core\HTTP $http): mixed
    {
        $type = $config['type'] ?? 'text';
        $transform = $config['transform'] ?? 'string';
        $default = $config['default'] ?? null;

        // Checkbox: special handling
        if ($type === ConfigFieldSchema::TYPE_CHECKBOX) {
            return isset($http->post()[$fieldName]) && $http->post()[$fieldName] === 'on' ? 1 : 0;
        }

        // Other types: read via HTTP::_GP
        $value = $http->_GP($fieldName, $default);

        if ($type === ConfigFieldSchema::TYPE_NUMBER || $type === ConfigFieldSchema::TYPE_SLIDER) {
            $value = min(max($value, $config['min'] ?? PHP_INT_MIN), $config['max'] ?? PHP_INT_MAX);
        }

        // Apply transform
        return self::applyTransform($value, $transform);
    }

    /**
     * Applies transformations to values
     */
    private static function applyTransform(mixed $value, string $transform): mixed
    {
        return match ($transform) {
            'int' => (int)$value,
            'float' => (float)$value,
            'string' => (string)$value,
            'bool' => (bool)$value,
            'int_multiply_2500' => (int)$value * 2500,
            default => $value,
        };
    }

    /**
     * Applies type transformations to values without calculation
     */
    public static function applyTypeTransform(mixed $value, string $transform): mixed
    {
        return match ($transform) {
            'int', 'int_multiply_2500' => (int)$value,
            'float' => (float)$value,
            'string' => (string)$value,
            'bool' => (bool)$value,
            default => $value,
        };
    }

    /**
     * Splits configuration values for template display
     * (Reverse of transformations)
     */
    public static function prepareTemplateData(object $config, ConfigFieldSchema $schema): array
    {
        $configValues = [];

        foreach ($schema->getAllFields() as $fieldName => $fieldConfig) {
            $value = $config->$fieldName ?? null;
            $transform = $fieldConfig['transform'] ?? 'string';

            // Reverse transform for certain fields
            if ($transform === 'int_multiply_2500') {
                $value = $value / 2500;
            }

            $configValues[$fieldName] = $value;
        }

        return $configValues;
    }

    /**
     * Creates before/after snapshots for logging
     */
    public static function createSnapshot(object $config, ConfigFieldSchema $schema): array
    {
        $snapshot = [];

        foreach (array_keys($schema->getAllFields()) as $fieldName) {
            $snapshot[$fieldName] = $config->$fieldName ?? null;
        }

        return $snapshot;
    }
}
