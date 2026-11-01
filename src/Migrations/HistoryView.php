<?php

declare(strict_types=1);

namespace Beacon\Migrations;

use Beacon\Database\ConnectionStatus;

/**
 * The repository read, or the reason it was not attempted.
 */
final class HistoryView
{
    public const OPENED = 'opened';

    public const MISSING = 'missing';

    public const UNSUPPORTED = 'unsupported';

    public const SQLITE = 'sqlite';

    public const TABLE = 'table';

    public const FAILED = 'failed';

    /**
     * @param array<string, int> $batches
     */
    private function __construct(
        public ConnectionStatus $status,
        public bool $available,
        public bool $empty,
        public array $batches,
        public bool $withheld,
        public string $reason,
    ) {
    }

    public static function opened(HistoryResult $result): self
    {
        return new self(
            ConnectionStatus::Connected,
            $result->available,
            $result->empty,
            $result->batches,
            $result->withheld,
            $result->available ? self::OPENED : self::MISSING,
        );
    }

    public static function closed(ConnectionStatus $status, string $reason): self
    {
        return new self($status, false, false, [], false, $reason);
    }
}
