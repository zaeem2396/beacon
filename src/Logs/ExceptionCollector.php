<?php

declare(strict_types=1);

namespace Beacon\Logs;

use Beacon\Data\ExceptionRecord;
use Beacon\Runtime\TextSanitizer;

/**
 * Builds normalized exception records from supported file log channels.
 */
final class ExceptionCollector
{
    public function __construct(
        private readonly LogReader $reader,
        private readonly ExceptionClassifier $classifier = new ExceptionClassifier(),
        private readonly ExceptionFingerprint $fingerprints = new ExceptionFingerprint(),
    ) {
    }

    /**
     * @return array{
     *     records: list<ExceptionRecord>,
     *     truncated: bool,
     *     bytes_read: int,
     *     parse_failures: int,
     *     files: int,
     *     inspected: int
     * }
     */
    public function collect(ExceptionQuery $query): array
    {
        $needed = $query->scanLimit();
        $read = $this->reader->read($query->channel, $needed, LogFiles::MAX_BYTES);
        $records = [];
        $capped = false;

        foreach ($read['records'] as $log) {
            $class = $log->exceptionClass ?? $this->classFromMessage($log->message);

            if ($class === null) {
                continue;
            }

            $message = $log->exceptionMessage ?? $log->message;
            $category = $this->classifier->classify($class, $message);

            if ($query->rejects($log, $class, $category)) {
                continue;
            }

            $fingerprint = $this->fingerprints->make($class, $message, $category, $log->file, $log->line);
            $id = substr(hash('sha256', $fingerprint . '|' . $log->timestamp . '|' . $log->message), 0, 16);

            $records[] = new ExceptionRecord(
                exceptionId: $id,
                fingerprint: $fingerprint,
                className: $class,
                message: TextSanitizer::sanitize($message) ?? 'Exception',
                category: $category,
                severity: $log->level,
                recordedAt: $log->timestamp,
                channel: $log->channel,
                file: $log->file,
                line: $log->line,
                environment: null,
                tracePresent: $log->tracePresent,
                traceTruncated: $log->traceTruncated,
                traceFrames: $log->traceFrames,
            );

            if (count($records) >= $needed) {
                $capped = true;

                break;
            }
        }

        return [
            'records' => $records,
            'truncated' => $read['truncated'] || $capped,
            'bytes_read' => $read['bytes_read'],
            'parse_failures' => $read['parse_failures'],
            'files' => $read['files'],
            'inspected' => count($read['records']),
        ];
    }

    private function classFromMessage(string $message): ?string
    {
        if (preg_match('/\b([A-Za-z_][A-Za-z0-9_\\\\]*(?:Exception|Error))\b/', $message, $match) !== 1) {
            return null;
        }

        $class = ltrim($match[1], '\\');

        return preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,190}$/', $class) === 1 ? $class : null;
    }
}
