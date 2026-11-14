<?php

declare(strict_types=1);

namespace Beacon\Logs;

use Beacon\Runtime\TextSanitizer;

/**
 * Parses Laravel single/daily file log chunks into records.
 */
final class LogParser
{
    public const MAX_FRAMES = 12;

    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+([A-Za-z0-9_-]+)\.([A-Z]+):\s+(.*)$/';

    /**
     * @return array{records: list<LogRecord>, failures: int}
     */
    public function parse(string $text, string $channel): array
    {
        if ($text === '') {
            return ['records' => [], 'failures' => 0];
        }

        if (($cut = strpos($text, "\n[")) !== false && ! str_starts_with($text, '[')) {
            $text = substr($text, $cut + 1);
        }

        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $records = [];
        $failures = 0;
        $current = null;

        foreach ($lines as $line) {
            if (preg_match(self::HEADER, $line, $match) === 1) {
                if ($current !== null) {
                    $records[] = $this->finish($current, $channel, $failures);
                }

                $level = LogLevel::tryFromLoose($match[3]);

                if ($level === null) {
                    $failures++;
                    $current = null;

                    continue;
                }

                $current = [
                    'timestamp' => $match[1],
                    'level' => $level,
                    'channel' => SafeChannel::name($match[2]) ?? $channel,
                    'body' => [$match[4]],
                ];

                continue;
            }

            if ($current !== null) {
                $current['body'][] = $line;
            }
        }

        if ($current !== null) {
            $records[] = $this->finish($current, $channel, $failures);
        }

        return ['records' => $records, 'failures' => $failures];
    }

    /**
     * @param array{timestamp: string, level: LogLevel, channel: string, body: list<string>} $current
     */
    private function finish(array $current, string $fallbackChannel, int &$failures): LogRecord
    {
        $body = implode("\n", $current['body']);
        $extracted = $this->extractException($body);
        $message = TextSanitizer::sanitize($this->primaryMessage($body, $extracted['exception_message'])) ?? 'Log entry';

        if ($message === 'Log entry' && $body === '') {
            $failures++;
        }

        return new LogRecord(
            timestamp: $this->toUtc($current['timestamp']),
            level: $current['level'],
            channel: $current['channel'] !== '' ? $current['channel'] : $fallbackChannel,
            message: $message,
            exceptionClass: $extracted['exception_class'],
            exceptionMessage: $extracted['exception_message'],
            file: $extracted['file'],
            line: $extracted['line'],
            tracePresent: $extracted['trace_present'],
            traceTruncated: $extracted['trace_truncated'],
            traceFrames: $extracted['trace_frames'],
        );
    }

    /**
     * Laravel file logs omit a timezone. Interpret the stamp in the process
     * timezone (Laravel sets that from app.timezone) and emit UTC.
     */
    private function toUtc(string $timestamp): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp);

        if ($parsed === false) {
            return str_replace(' ', 'T', $timestamp) . '+00:00';
        }

        return $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:sP');
    }

    /**
     * @return array{
     *     exception_class: ?string,
     *     exception_message: ?string,
     *     file: ?string,
     *     line: ?int,
     *     trace_present: bool,
     *     trace_truncated: bool,
     *     trace_frames: list<string>
     * }
     */
    private function extractException(string $body): array
    {
        $class = null;
        $message = null;
        $file = null;
        $line = null;

        if (preg_match('/([A-Za-z0-9_\\\\]+(?:Exception|Error))\s*(?:\(code:\s*\d+\))?:\s*(.+?)(?:\s+at\s+| in )(.+?):(\d+)/s', $body, $match) === 1) {
            $class = $this->className($match[1]);
            $message = TextSanitizer::sanitize($match[2]);
            $file = $this->basename($match[3]);
            $line = $this->lineNumber($match[4]);
        } elseif (preg_match('/([A-Za-z0-9_\\\\]+(?:Exception|Error)):\s*(.+)/', $body, $match) === 1) {
            $class = $this->className($match[1]);
            $message = TextSanitizer::sanitize($match[2]);
        }

        $frames = [];
        $tracePresent = false;
        $truncated = false;
        $count = preg_match_all('/^#\d+\s+(.+)$/m', $body, $matches);

        if (is_int($count) && $count > 0) {
            $raw = $matches[1];
            $tracePresent = true;
            $truncated = count($raw) > self::MAX_FRAMES;

            foreach (array_slice($raw, 0, self::MAX_FRAMES) as $frame) {
                $safe = TextSanitizer::sanitize($this->frame($frame), 160);

                if ($safe !== null) {
                    $frames[] = $safe;
                }
            }
        }

        return [
            'exception_class' => $class,
            'exception_message' => $message,
            'file' => $file,
            'line' => $line,
            'trace_present' => $tracePresent,
            'trace_truncated' => $truncated,
            'trace_frames' => $frames,
        ];
    }

    private function primaryMessage(string $body, ?string $exceptionMessage): string
    {
        $first = strtok($body, "\n");

        if (! is_string($first)) {
            return $exceptionMessage ?? '';
        }

        $first = preg_replace('/\s*\{"exception":.*$/s', '', $first) ?? $first;

        return $first;
    }

    private function className(string $value): ?string
    {
        $value = ltrim($value, '\\');

        return preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,190}$/', $value) === 1 ? $value : null;
    }

    private function basename(string $path): ?string
    {
        $name = basename(str_replace('\\', '/', $path));

        return preg_match('/^[A-Za-z0-9._-]{1,128}$/', $name) === 1 ? $name : null;
    }

    private function lineNumber(string $value): ?int
    {
        if (! ctype_digit($value)) {
            return null;
        }

        $line = (int) $value;

        return $line > 0 && $line < 1_000_000 ? $line : null;
    }

    private function frame(string $frame): string
    {
        $frame = preg_replace('/\([^)]*\)/', '()', $frame) ?? $frame;
        $frame = preg_replace_callback(
            '#(?:[A-Za-z]:)?(?:/|\\\\)[^\s:]+#',
            static fn (array $match): string => basename(str_replace('\\', '/', $match[0])),
            $frame,
        ) ?? $frame;

        return $frame;
    }
}
