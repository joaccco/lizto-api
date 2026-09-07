<?php

namespace App\Infrastructure\Observability\Services;

class SanitizerService
{
    /**
     * Sanitize an associative array or payload by redacting sensitive keys.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function sanitizePayload(array $data): array
    {
        $sensitiveKeys = config('observability.sensitive_keys', [
            'email', 'phone', 'phone_number', 'address', 'location_address', 'base_address',
            'lat', 'lng', 'base_lat', 'base_lng', 'location_lat', 'location_lng',
            'password', 'password_confirmation', 'token', 'session_token', 'device_token',
            'auth_token', 'access_token', 'authorization', 'secret', 'credentials', 'api_key', 'key',
            'name', 'commercial_name', 'user_name',
        ]);

        $sanitized = [];

        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (in_array($lowerKey, $sensitiveKeys, true)) {
                // If it's a name key, strip it out completely to prevent personal data leaks;
                // for structural fields like email/phone/address, redact with [FILTERED]
                if (in_array($lowerKey, ['name', 'commercial_name', 'user_name'], true)) {
                    continue;
                }
                $sanitized[$key] = '[FILTERED]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
