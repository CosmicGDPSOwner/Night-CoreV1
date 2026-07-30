<?php

declare(strict_types=1);

namespace NightCore\Web\Security;

use RuntimeException;

final class SecurityHeaders
{
    public static function contentSecurityPolicy(string $nonce): string
    {
        if ($nonce === '' || preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $nonce) !== 1) {
            throw new RuntimeException('Invalid Content-Security-Policy nonce.');
        }

        $quotedNonce = "'nonce-" . $nonce . "'";

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "manifest-src 'self'",
            "worker-src 'none'",
            'style-src ' . "'self' " . $quotedNonce,
            "style-src-attr 'unsafe-inline'",
            'script-src ' . "'self' " . $quotedNonce,
            "script-src-attr 'none'",
        ]);
    }

    public static function send(string $nonce, bool $privatePage = false): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Security-Policy: ' . self::contentSecurityPolicy($nonce));
        header('Referrer-Policy: same-origin');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');

        if ($privatePage) {
            header('X-Robots-Tag: noindex, nofollow, noarchive');
        }
    }
}
