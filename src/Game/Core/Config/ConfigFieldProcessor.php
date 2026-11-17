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
    public static function processPostData(array $schema, \App\Game\Core\HTTP $http, ?object $current = null): array
    {
        $processed = [];

        foreach ($schema as $fieldName => $fieldConfig) {
            // Hidden fields (counters like last_planet_pos) are not part of the form.
            if (($fieldConfig['type'] ?? '') === ConfigFieldSchema::TYPE_NONE) {
                continue;
            }
            $processed[$fieldName] = self::processField($fieldName, $fieldConfig, $http);
            // secrets are never shown in the form: an empty input keeps the stored value
            if (!empty($fieldConfig['secret']) && $processed[$fieldName] === '' && $current !== null) {
                $processed[$fieldName] = (string) ($current->$fieldName ?? '');
            }
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

        if ($type === ConfigFieldSchema::TYPE_NUMBER || $type === ConfigFieldSchema::TYPE_SLIDER || $type === ConfigFieldSchema::TYPE_FLOAT) {
            $value = min(max($value, $config['min'] ?? PHP_INT_MIN), $config['max'] ?? PHP_INT_MAX);
        }

        // Apply transform
        $value = self::applyTransform($value, $transform);

        // 'validate' => 'http_url': links are rendered as href, only empty or absolute http(s) URLs are stored
        if (($config['validate'] ?? '') === 'http_url') {
            $value = \App\Game\Alliance\AllianceText::httpUrlOrEmpty((string) $value);
        }

        // 'validate' => 'discord_guild': a Discord server ID (17-20 digits) or empty
        if (($config['validate'] ?? '') === 'discord_guild') {
            $value = trim((string) $value);
            $value = \App\Community\DiscordWidget::isValidGuildId($value) ? $value : '';
        }

        return $value;
    }

    /**
     * Applies transformations to values
     */
    private static function applyTransform(mixed $value, string $transform): mixed
    {
        return match ($transform) {
            'int' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => (bool) $value,
            'int_multiply_2500' => (int) $value * 2500,
            default => $value,
        };
    }

    /**
     * Applies type transformations to values without calculation
     */
    public static function applyTypeTransform(mixed $value, string $transform): mixed
    {
        return match ($transform) {
            'int', 'int_multiply_2500' => (int) $value,
            'float' => (float) $value,
            'string' => (string) $value,
            'bool' => (bool) $value,
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

            if (!empty($fieldConfig['secret'])) {
                // the form gets a hint (set / last characters) instead of the secret itself
                $configValues['_hint_' . $fieldName] = self::secretHint((string) $value);
                $value = '';
            }

            $configValues[$fieldName] = $value;
        }

        return $configValues;
    }

    /** "" when unset, else the last 4 characters behind bullets (short secrets: bullets only) */
    public static function secretHint(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return '••••' . (mb_strlen($value) > 8 ? mb_substr($value, -4) : '');
    }

    /**
     * Replaces the secret fields of a snapshot by their hint (admin log).
     *
     * @param array<string, mixed> $values field => value
     * @return array<string, mixed>
     */
    public static function maskSecrets(array $values, ConfigFieldSchema $schema): array
    {
        foreach ($schema->getAllFields() as $fieldName => $fieldConfig) {
            if (!empty($fieldConfig['secret']) && array_key_exists($fieldName, $values)) {
                $values[$fieldName] = self::secretHint((string) $values[$fieldName]);
            }
        }

        return $values;
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
