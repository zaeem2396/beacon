<?php

declare(strict_types=1);

namespace Beacon\Security;

/**
 * Reduces a URL to the parts that say where an application is served: scheme,
 * host, port and path.
 *
 * User information, query strings and fragments are always removed because
 * they commonly carry credentials, signatures or tokens. Anything that is not
 * an absolute http(s) URL with a valid host is rejected rather than repaired.
 */
final class UrlSanitizer
{
    public const MAX_LENGTH = 2048;

    private const SCHEMES = ['http', 'https'];

    private const HOST_PATTERN = '/^(\[[0-9a-f:.]+\]|[a-z0-9_]([a-z0-9_.-]*[a-z0-9_])?)$/';

    /**
     * @return string|null the sanitized URL, or null when the value is not a valid absolute http(s) URL
     */
    public function sanitize(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return null;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, self::SCHEMES, true) || preg_match(self::HOST_PATTERN, $host) !== 1) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port . rtrim($parts['path'] ?? '', '/');
    }
}
