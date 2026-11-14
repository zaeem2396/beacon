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
        $read = $this->reader->read($query->channel, $query->scanLimit(), LogFiles::MAX_BYTES);
        $records = [];

        foreach ($read['records'] as $log) {
            if ($log->exceptionClass === null && $log->level->rank() < LogLevel::Error->rank()) {
                continue;
            }

            if ($log->exceptionClass === null && ! $this->looksLikeException($log->message)) {
                continue;
            }

            $class = $log->exceptionClass ?? 'RuntimeException';
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

            if (count($records) >= $query->limit) {
                break;
            }
        }

        return [
            'records' => $records,
            'truncated' => $read['truncated'] || count($read['records']) > count($records) && count($records) >= $query->limit,
            'bytes_read' => $read['bytes_read'],
            'parse_failures' => $read['parse_failures'],
            'files' => $read['files'],
            'inspected' => count($read['records']),
        ];
    }

    private function looksLikeException(string $message): bool
    {
        return preg_match('/Exception|Error:/', $message) === 1;
    }
}
