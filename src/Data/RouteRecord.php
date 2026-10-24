<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * One registered route, reduced to metadata that is safe to disclose.
 *
 * Fields that cannot be represented safely are null. Methods stay in a fixed
 * order so two inspections of the same route table compare equal.
 */
final readonly class RouteRecord extends DataObject
{
    public const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /**
     * @param list<string> $methods
     * @param list<string> $middleware
     */
    public function __construct(
        public array $methods,
        public ?string $uri,
        public ?string $name,
        public ?string $domain,
        public array $middleware,
        public ?string $action,
        public ?bool $frameworkGenerated,
    ) {
        $canonical = array_values(array_intersect(self::METHODS, $methods));

        if ($methods === [] || $methods !== $canonical) {
            throw new \InvalidArgumentException('HTTP methods must be a non-empty list in canonical order.');
        }

        if (! array_is_list($middleware) || count($middleware) > 64) {
            throw new \InvalidArgumentException('Route middleware must be a list of at most 64 names.');
        }

        foreach ($middleware as $name) {
            if (! is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Route middleware must contain only names.');
            }
        }
    }
}
