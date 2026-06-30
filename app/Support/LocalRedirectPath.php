<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Open-redirect guard for post-login deep links carried through the `?from=`
 * query. Only same-host relative paths survive; anything with a scheme, host,
 * protocol-relative `//` prefix, or backslash is rejected.
 */
class LocalRedirectPath
{
    /**
     * Reduce a stored `url.intended` value (a full absolute URL, or already a
     * relative path) to a safe relative path. Absolute URLs must point at the
     * app's own host; anything else is dropped.
     */
    public static function fromIntended(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url(trim($url));
        if ($parts === false) {
            return null;
        }

        if (isset($parts['host'])) {
            // The framework stores url.intended using the active request root, so
            // compare against that (url('/') falls back to config app.url when no
            // request is bound, e.g. in unit tests).
            $appHost = parse_url(url('/'), PHP_URL_HOST);
            if ($parts['host'] !== $appHost) {
                return null;
            }
        }

        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?'.$parts['query'];
        }

        return self::sanitize($path);
    }

    public static function sanitize(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim($path);

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return null;
        }

        if (str_contains($path, '\\')) {
            return null;
        }

        // A leading-slash value can still smuggle a host via parse_url; reject
        // anything that resolves to a scheme or host.
        $parts = parse_url($path);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        // Reconstruct from parse_url components rather than returning the original
        // string: parse_url normalises control characters (CRLF, null bytes) internally
        // but those characters survive in the original string and would enable header
        // injection if passed through as-is (e.g. %0D%0A in the query param).
        $safe = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $safe .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $safe .= '#'.$parts['fragment'];
        }

        return $safe;
    }
}
