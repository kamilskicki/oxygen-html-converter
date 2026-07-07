<?php

declare(strict_types=1);

namespace OxyHtmlConverter\Services;

class RequestOptions
{
    /**
     * @param mixed $value
     */
    public function parseBool($value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return $default;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($parsed === null) {
            return $default;
        }

        return (bool) $parsed;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, bool|int>
     */
    public function normalizeConvert(array $input): array
    {
        $startingNodeId = isset($input['startingNodeId']) ? intval($input['startingNodeId']) : 1;
        if ($startingNodeId < 1) {
            $startingNodeId = 1;
        }

        $safeMode = $this->parseBool($input['safeMode'] ?? true, true);
        $strictNative = $this->parseBool($input['strictNative'] ?? false, false);

        return [
            'startingNodeId' => $startingNodeId,
            'wrapInContainer' => $this->parseBool($input['wrapInContainer'] ?? true, true),
            'includeCssElement' => $this->parseBool($input['includeCssElement'] ?? false, false),
            'inlineStyles' => $this->parseBool($input['inlineStyles'] ?? true, true),
            'safeMode' => $safeMode,
            'unsafeModeExplicit' => array_key_exists('safeMode', $input)
                && !$safeMode,
            'allowExecutableCode' => !$safeMode
                && !$strictNative
                && $this->parseBool($input['allowExecutableCode'] ?? false, false),
            'strictNative' => $strictNative,
            'debugMode' => $this->parseBool($input['debugMode'] ?? false, false),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, bool>
     */
    public function normalizeBatch(array $input): array
    {
        $safeMode = $this->parseBool($input['safeMode'] ?? true, true);
        $strictNative = $this->parseBool($input['strictNative'] ?? false, false);

        return [
            'inlineStyles' => $this->parseBool($input['inlineStyles'] ?? true, true),
            'safeMode' => $safeMode,
            'unsafeModeExplicit' => array_key_exists('safeMode', $input)
                && !$safeMode,
            'allowExecutableCode' => !$safeMode
                && !$strictNative
                && $this->parseBool($input['allowExecutableCode'] ?? false, false),
            'strictNative' => $strictNative,
            'debugMode' => $this->parseBool($input['debugMode'] ?? false, false),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, bool>
     */
    public function normalizePreview(array $input): array
    {
        $safeMode = $this->parseBool($input['safeMode'] ?? true, true);
        $strictNative = $this->parseBool($input['strictNative'] ?? false, false);

        return [
            'inlineStyles' => $this->parseBool($input['inlineStyles'] ?? true, true),
            'safeMode' => $safeMode,
            'unsafeModeExplicit' => array_key_exists('safeMode', $input)
                && !$safeMode,
            'allowExecutableCode' => !$safeMode
                && !$strictNative
                && $this->parseBool($input['allowExecutableCode'] ?? false, false),
            'strictNative' => $strictNative,
            'debugMode' => $this->parseBool($input['debugMode'] ?? false, false),
        ];
    }
}
