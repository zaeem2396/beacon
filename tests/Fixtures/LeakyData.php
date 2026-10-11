<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Data\DataObject;

/**
 * Simulates an inspector that mistakenly copies secrets into its DTO.
 */
final readonly class LeakyData extends DataObject
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $applicationName,
        public string $dbPassword,
        public string $appKey,
        public array $config,
        public array $headers,
        public string $exceptionMessage,
    ) {
    }
}
