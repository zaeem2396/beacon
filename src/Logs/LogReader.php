<?php

declare(strict_types=1);

namespace Beacon\Logs;

/**
 * Bounded reverse reader for Laravel file log channels.
 */
final class LogReader
{
    public const DEFAULT_LIMIT = 100;

    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly LogFiles $files,
        private readonly LogParser $parser = new LogParser(),
    ) {
    }

    /**
     * @return array{records: list<LogRecord>, truncated: bool, bytes_read: int, parse_failures: int, files: int}
     */
    public function read(?string $channel = null, int $limit = self::DEFAULT_LIMIT, int $maxBytes = LogFiles::MAX_BYTES): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $maxBytes = max(1024, min(LogFiles::MAX_BYTES, $maxBytes));
        $files = $this->files->files($channel);
        $records = [];
        $bytes = 0;
        $failures = 0;
        $truncated = false;

        foreach ($files as $file) {
            if ($bytes >= $maxBytes || count($records) >= $limit) {
                $truncated = true;

                break;
            }

            $chunk = $this->tail($file['path'], $maxBytes - $bytes);
            $bytes += $chunk['bytes'];

            if ($chunk['truncated']) {
                $truncated = true;
            }

            $parsed = $this->parser->parse($chunk['text'], $file['channel']);
            $failures += $parsed['failures'];

            foreach ($parsed['records'] as $record) {
                $records[] = $record;
            }
        }

        usort(
            $records,
            static fn (LogRecord $a, LogRecord $b): int => $b->timestamp <=> $a->timestamp,
        );

        if (count($records) > $limit) {
            $records = array_slice($records, 0, $limit);
            $truncated = true;
        }

        return [
            'records' => $records,
            'truncated' => $truncated,
            'bytes_read' => $bytes,
            'parse_failures' => $failures,
            'files' => count($files),
        ];
    }

    /**
     * @return array{text: string, bytes: int, truncated: bool}
     */
    private function tail(string $path, int $budget): array
    {
        $size = filesize($path);

        if ($size === false || $size <= 0) {
            return ['text' => '', 'bytes' => 0, 'truncated' => false];
        }

        $read = min($size, $budget);
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return ['text' => '', 'bytes' => 0, 'truncated' => false];
        }

        try {
            if ($read < $size) {
                fseek($handle, -$read, SEEK_END);
            }

            $text = stream_get_contents($handle);

            return [
                'text' => is_string($text) ? $text : '',
                'bytes' => $read,
                'truncated' => $read < $size,
            ];
        } finally {
            fclose($handle);
        }
    }
}
