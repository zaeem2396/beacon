<?php

declare(strict_types=1);

namespace Beacon\Data;

use Beacon\QueueIntelligence\QueueStatus;

/**
 * One configured queue connection without credentials.
 */
final readonly class QueueConnectionInfo extends DataObject
{
    public function __construct(
        public string $name,
        public ?string $driver,
        public ?string $queueName,
        public ?int $retryAfter,
        public QueueStatus $backendStatus,
        public ?int $pending,
        public string $note,
    ) {
    }
}
