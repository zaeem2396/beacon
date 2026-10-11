<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Data\DataObject;

final readonly class ApplicationInfo extends DataObject
{
    public function __construct(
        public string $laravelVersion,
        public string $phpVersion,
        public string $environment,
        public string $applicationName,
    ) {
    }
}
