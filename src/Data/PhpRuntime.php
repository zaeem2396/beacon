<?php

declare(strict_types=1);

namespace Beacon\Data;

/**
 * Safe PHP process facts. Extension names are an allowlisted presence map.
 */
final readonly class PhpRuntime extends DataObject
{
    /**
     * @param list<string> $extensions
     */
    public function __construct(
        public string $version,
        public string $sapi,
        public ?string $os,
        public ?string $memoryLimit,
        public ?string $maxExecutionTime,
        public ?string $uploadMaxFilesize,
        public ?string $postMaxSize,
        public array $extensions,
    ) {
    }
}
