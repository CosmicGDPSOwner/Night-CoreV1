<?php

declare(strict_types=1);

namespace NightCore\Core;

final class ClientIp
{
    public static function detect(bool $trustProxyHeaders): string
    {
        if ($trustProxyHeaders) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR'] as $key) {
                $value = $_SERVER[$key] ?? '';
                if (!is_string($value) || $value === '') {
                    continue;
                }

                $candidate = trim(explode(',', $value)[0]);
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($remote) && filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
    }
}
