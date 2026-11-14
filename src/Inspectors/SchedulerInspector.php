<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\ScheduledTask;
use Beacon\Data\SchedulerInspection;
use Beacon\Exceptions\InvalidRuntimeQueryException;
use Beacon\Runtime\TextSanitizer;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Inspects registered scheduled task definitions.
 *
 * @implements Inspector<SchedulerInspection>
 */
final class SchedulerInspector implements Inspector
{
    public const NAME = 'scheduler';

    public const MAX_TASKS = 100;

    public function __construct(
        private readonly Schedule $schedule,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Scheduler->capability();
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public function inspect(array $parameters = []): Data
    {
        if ($parameters !== []) {
            throw new InvalidRuntimeQueryException('Scheduler inspection does not accept arguments.');
        }

        $tasks = [];
        $warnings = [];

        try {
            $events = $this->schedule->events();
        } catch (\Throwable) {
            return new SchedulerInspection(
                tasks: [],
                returned: 0,
                summary: 'Scheduler definitions were unavailable.',
                warnings: ['The Laravel scheduler could not be read.'],
            );
        }

        foreach ($events as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            $tasks[] = new ScheduledTask(
                description: TextSanitizer::sanitize($this->description($event), 160) ?? 'Scheduled task',
                expression: $this->expression($event),
                timezone: $this->timezone($event),
                withoutOverlapping: $event->withoutOverlapping,
                runInBackground: $event->runInBackground,
                evenInMaintenanceMode: $event->evenInMaintenanceMode,
                environments: $this->environments($event),
                executionHistory: 'Execution history was not available.',
            );

            if (count($tasks) >= self::MAX_TASKS) {
                $warnings[] = 'Scheduler output stopped at the task limit.';

                break;
            }
        }

        return new SchedulerInspection(
            tasks: $tasks,
            returned: count($tasks),
            summary: count($tasks) === 0
                ? 'No scheduled tasks were registered.'
                : 'Scheduled task definitions were listed. Tasks were not executed.',
            warnings: $warnings,
        );
    }

    private function description(Event $event): string
    {
        if ($event instanceof CallbackEvent) {
            $description = $event->getSummaryForDisplay();

            return is_string($description) && $description !== '' ? $description : 'Callback task';
        }

        $command = $event->command;

        return is_string($command) && $command !== '' ? $command : $event->getSummaryForDisplay();
    }

    private function expression(Event $event): ?string
    {
        $expression = $event->expression;

        return is_string($expression) && preg_match('/^[0-9*,\/\- ]{1,64}$/', $expression) === 1
            ? $expression
            : null;
    }

    private function timezone(Event $event): ?string
    {
        $timezone = $event->timezone;

        if (! is_string($timezone) || $timezone === '') {
            return null;
        }

        try {
            return (new \DateTimeZone($timezone))->getName();
        } catch (\Throwable) {
            return null;
        }
    }

    private function environments(Event $event): ?string
    {
        $environments = $event->environments;

        if (! is_array($environments) || $environments === []) {
            return null;
        }

        $safe = [];

        foreach ($environments as $environment) {
            if (is_string($environment) && preg_match('/^[A-Za-z0-9_-]{1,32}$/', $environment) === 1) {
                $safe[] = $environment;
            }
        }

        return $safe === [] ? null : implode(',', $safe);
    }
}
