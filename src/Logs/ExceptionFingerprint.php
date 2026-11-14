<?php

declare(strict_types=1);

namespace Beacon\Logs;

use Beacon\Runtime\TextSanitizer;

/**
 * Stable fingerprint for equivalent exception occurrences.
 */
final class ExceptionFingerprint
{
    public function make(
        ?string $class,
        ?string $message,
        ExceptionCategory $category,
        ?string $file,
        ?int $line,
    ): string {
        $parts = [
            strtolower($class ?? 'unknown'),
            TextSanitizer::normalizeForFingerprint($message),
            $category->value,
            strtolower($file ?? ''),
            $line === null ? '' : (string) $line,
        ];

        return substr(hash('sha256', implode('|', $parts)), 0, 32);
    }
}
