<?php

declare(strict_types=1);

namespace Beacon\Diagnostics;

use Beacon\Core\Version;

final readonly class DiagnosticReport
{
    public const SCHEMA_VERSION = 1;

    public const STATUS_OK = 'ok';

    public const STATUS_WARN = 'warn';

    public const STATUS_MISCONFIGURED = 'misconfigured';

    public const STATUS_FAIL = 'fail';

    /** Every check passed, or is disabled, not implemented or only a warning. */
    public const EXIT_OK = 0;

    /** At least one check is misconfigured and none failed. */
    public const EXIT_MISCONFIGURED = 1;

    /** At least one check failed or could not run. */
    public const EXIT_FAILED = 2;

    /**
     * @param list<CheckResult> $checks
     */
    public function __construct(
        public array $checks,
    ) {
    }

    public function status(): string
    {
        return match (true) {
            $this->has(DiagnosticStatus::Fail) => self::STATUS_FAIL,
            $this->has(DiagnosticStatus::Misconfigured) => self::STATUS_MISCONFIGURED,
            $this->has(DiagnosticStatus::Warn) => self::STATUS_WARN,
            default => self::STATUS_OK,
        };
    }

    public function exitCode(): int
    {
        return match ($this->status()) {
            self::STATUS_FAIL => self::EXIT_FAILED,
            self::STATUS_MISCONFIGURED => self::EXIT_MISCONFIGURED,
            default => self::EXIT_OK,
        };
    }

    /**
     * @return array{
     *     schema_version: int,
     *     beacon_version: string,
     *     status: string,
     *     exit_code: int,
     *     summary: array<string, int>,
     *     checks: list<array<array-key, mixed>>
     * }
     */
    public function toArray(): array
    {
        $summary = [];

        foreach (DiagnosticStatus::cases() as $status) {
            $summary[$status->value] = count(array_filter(
                $this->checks,
                static fn (CheckResult $check): bool => $check->status === $status,
            ));
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'beacon_version' => Version::current(),
            'status' => $this->status(),
            'exit_code' => $this->exitCode(),
            'summary' => $summary,
            'checks' => array_map(static fn (CheckResult $check): array => $check->toArray(), $this->checks),
        ];
    }

    private function has(DiagnosticStatus $status): bool
    {
        foreach ($this->checks as $check) {
            if ($check->status === $status) {
                return true;
            }
        }

        return false;
    }
}
