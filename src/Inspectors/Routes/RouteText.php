<?php

declare(strict_types=1);

namespace Beacon\Inspectors\Routes;

use Beacon\Data\RouteRecord;

/**
 * Turns raw route metadata into values that are safe to return.
 *
 * Anything that fails a pattern is withheld (null or dropped), not escaped
 * into the response. Filters use the same patterns and reject instead.
 */
final class RouteText
{
    public const MAX_URI = 200;

    public const MAX_NAME = 120;

    public const MAX_DOMAIN = 253;

    public const MAX_ACTION = 255;

    public const MAX_MIDDLEWARE = 160;

    public const MAX_MIDDLEWARE_COUNT = 64;

    private const VERBS = RouteRecord::METHODS;

    private const URI_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._~{}+-?/';

    private const NAME_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789._:-';

    private const HOST_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-';

    private const IDENTIFIER_HEAD = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_';

    private const IDENTIFIER_BODY = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    private const IDENTIFIER_DOTS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.';

    private const PARAMETER_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_.,|\\-';

    /**
     * Authentication middleware Beacon can recognise by name.
     * An alias outside this list is not treated as missing authentication.
     *
     * @var list<string>
     */
    private const AUTHENTICATION = [
        'auth',
        'auth.basic',
        'auth.session',
        'Illuminate\\Auth\\Middleware\\Authenticate',
        'Illuminate\\Auth\\Middleware\\AuthenticateWithBasicAuth',
        'Illuminate\\Session\\Middleware\\AuthenticateSession',
    ];

    public static function isUri(string $value): bool
    {
        return self::uri($value) !== null;
    }

    public static function isName(string $value): bool
    {
        return self::name($value) !== null;
    }

    public static function isMiddleware(string $value): bool
    {
        return strlen($value) <= self::MAX_MIDDLEWARE && self::isMiddlewarePattern($value);
    }

    public static function uri(string $value): ?string
    {
        if (strlen($value) > self::MAX_URI || ! self::isSafeUri($value)) {
            return null;
        }

        return $value;
    }

    public static function name(string $value): ?string
    {
        if ($value === '' || strlen($value) > self::MAX_NAME || ! self::containsOnly($value, self::NAME_CHARACTERS)) {
            return null;
        }

        return $value;
    }

    public static function domain(string $value): ?string
    {
        if (strlen($value) > self::MAX_DOMAIN || ! self::isDomainPattern($value)) {
            return null;
        }

        return $value;
    }

    public static function action(string $value): ?string
    {
        if (strlen($value) > self::MAX_ACTION || ! self::isActionPattern($value)) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $methods
     *
     * @return list<string>
     */
    public static function methods(array $methods): array
    {
        $present = [];

        foreach ($methods as $method) {
            if (! is_string($method)) {
                continue;
            }

            $verb = strtoupper(trim($method));

            if (in_array($verb, self::VERBS, true)) {
                $present[$verb] = true;
            }
        }

        $ordered = [];

        foreach (self::VERBS as $verb) {
            if (isset($present[$verb])) {
                $ordered[] = $verb;
            }
        }

        return $ordered;
    }

    /**
     * @param array<mixed> $middleware
     *
     * @return list<string>
     */
    public static function middleware(array $middleware): array
    {
        $names = [];

        foreach ($middleware as $item) {
            $name = self::middlewareItem($item);

            if ($name !== null && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return array_slice($names, 0, self::MAX_MIDDLEWARE_COUNT);
    }

    /**
     * @return array{excluded: bool, unreadable: bool}
     */
    public static function authenticationExclusion(mixed $excluded): array
    {
        if ($excluded === null) {
            return ['excluded' => false, 'unreadable' => false];
        }

        if (is_string($excluded)) {
            $excluded = [$excluded];
        }

        if (! is_array($excluded)) {
            return ['excluded' => false, 'unreadable' => true];
        }

        $excludedAuth = false;
        $unreadable = false;

        foreach ($excluded as $item) {
            if (! is_string($item)) {
                $unreadable = true;

                continue;
            }

            $name = strstr($item, ':', true);
            $name = $name === false ? $item : $name;

            if (in_array($name, self::AUTHENTICATION, true)) {
                $excludedAuth = true;
            }
        }

        return ['excluded' => $excludedAuth, 'unreadable' => $unreadable];
    }

    private static function middlewareItem(mixed $item): ?string
    {
        if ($item instanceof \Closure) {
            return 'closure';
        }

        if (is_object($item)) {
            $item = $item::class;
        }

        if (! is_string($item)) {
            return null;
        }

        $item = trim($item);

        if ($item === '' || strlen($item) > self::MAX_MIDDLEWARE) {
            return null;
        }

        if (self::isMiddlewarePattern($item)) {
            return $item;
        }

        $name = strstr($item, ':', true);

        if (is_string($name) && self::isMiddlewareName($name) && strlen($name) <= self::MAX_MIDDLEWARE) {
            return $name;
        }

        return null;
    }

    private static function isSafeUri(string $value): bool
    {
        if ($value === '/') {
            return true;
        }

        if (! self::containsOnly($value, self::URI_CHARACTERS) || str_contains($value, '//')) {
            return false;
        }

        $path = trim($value, '/');

        if ($path === '') {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function isDomainPattern(string $value): bool
    {
        if ($value === '' || str_starts_with($value, '.') || str_ends_with($value, '.')) {
            return false;
        }

        foreach (explode('.', $value) as $label) {
            if ($label === '') {
                return false;
            }

            if (str_starts_with($label, '{')) {
                if (! str_ends_with($label, '}') || ! self::isIdentifier(substr($label, 1, -1), false)) {
                    return false;
                }

                continue;
            }

            if (! self::containsOnly($label, self::HOST_CHARACTERS)) {
                return false;
            }
        }

        return true;
    }

    private static function isActionPattern(string $value): bool
    {
        if ($value === 'closure') {
            return true;
        }

        $method = null;
        $separator = strpos($value, '@');

        if ($separator !== false) {
            if (substr_count($value, '@') !== 1) {
                return false;
            }

            $method = substr($value, $separator + 1);
            $value = substr($value, 0, $separator);
        }

        if (! self::isQualifiedName($value, false)) {
            return false;
        }

        return $method === null || self::isIdentifier($method, false);
    }

    private static function isMiddlewarePattern(string $value): bool
    {
        $parameters = null;
        $separator = strpos($value, ':');

        if ($separator !== false) {
            if (substr_count($value, ':') !== 1) {
                return false;
            }

            $parameters = substr($value, $separator + 1);
            $value = substr($value, 0, $separator);
        }

        if (! self::isMiddlewareName($value)) {
            return false;
        }

        if ($parameters === null) {
            return true;
        }

        $length = strlen($parameters);

        return $length >= 1 && $length <= 80 && self::containsOnly($parameters, self::PARAMETER_CHARACTERS);
    }

    private static function isMiddlewareName(string $value): bool
    {
        return self::isQualifiedName($value, true);
    }

    private static function isQualifiedName(string $value, bool $dotsInFirst): bool
    {
        if ($value === '') {
            return false;
        }

        $segments = explode('\\', $value);

        foreach ($segments as $index => $segment) {
            if (! self::isIdentifier($segment, $index === 0 && $dotsInFirst)) {
                return false;
            }
        }

        return true;
    }

    private static function isIdentifier(string $value, bool $dots): bool
    {
        if ($value === '' || ! self::containsOnly($value[0], self::IDENTIFIER_HEAD)) {
            return false;
        }

        return self::containsOnly(substr($value, 1), $dots ? self::IDENTIFIER_DOTS : self::IDENTIFIER_BODY);
    }

    private static function containsOnly(string $value, string $characters): bool
    {
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            if (! str_contains($characters, $value[$index])) {
                return false;
            }
        }

        return true;
    }
}
