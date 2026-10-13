<?php

declare(strict_types=1);

namespace Beacon\Diagnostics;

use Beacon\Data\DataObject;

/**
 * Messages and hints must never contain credentials or configuration values
 * that could be secret.
 */
final readonly class CheckResult extends DataObject
{
    public function __construct(
        public string $id,
        public string $name,
        public DiagnosticStatus $status,
        public string $message,
        public ?string $hint = null,
    ) {
    }
}
