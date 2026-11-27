<?php
declare(strict_types=1);

/**
 * Cabeceras HTTP de blindaje (CSP, HSTS, Clickjacking, MIME sniffing, Permissions-Policy).
 */
final class SecurityHeaders
{
    public static function applyHtml(): void
    {
        self::applyCommon();
        header(
            "Content-Security-Policy: default-src 'self'; " .
            "script-src 'self'; " .
            "style-src 'self' 'unsafe-inline'; " .
            "img-src 'self' data: blob: https://*.basemaps.cartocdn.com https://server.arcgisonline.com https://tile.openstreetmap.org https://*.tile.openstreetmap.org; " .
            "media-src 'self' blob:; " .
            "connect-src 'self'; " .
            "frame-ancestors 'none'; " .
            "base-uri 'self'; " .
            "form-action 'self'"
        );
        header('Permissions-Policy: camera=(self), geolocation=(self), publickey-credentials-get=(self), publickey-credentials-create=(self), microphone=(), payment=(), usb=()');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    }

    public static function applyApi(): void
    {
        self::applyCommon();
        header('Content-Type: application/json; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }

    private static function applyCommon(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Cross-Origin-Opener-Policy: same-origin');
        header('Cross-Origin-Resource-Policy: same-origin');

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
