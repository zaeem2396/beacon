<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Core\Payload;
use Beacon\Data\HealthCheckResult;
use Beacon\Data\HealthInspection;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Exceptions\InvalidHealthQueryException;
use Beacon\Health\CacheProbe;
use Beacon\Health\Checks\ApplicationCheck;
use Beacon\Health\Checks\CacheCheck;
use Beacon\Health\Checks\DatabaseCheck;
use Beacon\Health\Checks\QueueCheck;
use Beacon\Health\DatabaseProbe;
use Beacon\Health\HealthStatus;
use Beacon\Health\LaravelCacheProbe;
use Beacon\Inspectors\HealthInspector;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\RecordingArrayStore;
use Beacon\Tests\Fixtures\RecordingDatabaseProbe;
use Beacon\Tests\Fixtures\ThrowingCacheProbe;
use Beacon\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

final class HealthInspectorTest extends TestCase
{
    private const PASSWORD = 'database-password-not-in-output';

    #[Test]
    public function a_default_report_passes_the_application_and_skips_optional_probes(): void
    {
        Http::fake();
        Mail::fake();
        Queue::fake();
        $probe = new RecordingDatabaseProbe();
        $this->application()->instance(DatabaseProbe::class, $probe);
        config()->set('database.connections.sqlite.password', self::PASSWORD);
        config()->set('cache.stores.array.key', 'cache-secret-not-in-output');
        config()->set('queue.connections.sync.password', 'queue-password-not-in-output');

        $report = $this->inspect();
        $encoded = $this->encoded($report);
        $payload = $this->application()->make(Beacon::class)->inspect(Principal::local(), HealthInspector::NAME);
        $this->assertInstanceOf(Payload::class, $payload);
        $encoded .= json_encode($payload->toArray());

        $this->assertSame(HealthStatus::Healthy, $report->status);
        $this->assertSame('Every completed health check passed.', $report->summary);
        $this->assertSame(
            [ApplicationCheck::NAME, DatabaseCheck::NAME, CacheCheck::NAME, QueueCheck::NAME],
            array_map(static fn (HealthCheckResult $check): string => $check->name, $report->checks),
        );
        $this->assertSame(HealthStatus::Healthy, $report->checks[0]->status);
        $this->assertSame(HealthStatus::Skipped, $report->checks[1]->status);
        $this->assertSame(HealthStatus::Skipped, $report->checks[2]->status);
        $this->assertSame(HealthStatus::Skipped, $report->checks[3]->status);
        $this->assertSame(1, $report->counts->healthy);
        $this->assertSame(3, $report->counts->skipped);
        $this->assertSame(0, $probe->calls);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $report->assessedAt);

        foreach ($report->checks as $check) {
            $this->assertIsInt($check->durationMs);
            $this->assertGreaterThanOrEqual(0, $check->durationMs);
            $this->assertLessThanOrEqual(120000, $check->durationMs);
        }

        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString('cache-secret-not-in-output', $encoded);
        $this->assertStringNotContainsString('queue-password-not-in-output', $encoded);
        $this->assertStringNotContainsString($this->application()->basePath(), $encoded);
        $this->assertStringNotContainsString('app_cipher', $encoded);
        $this->assertStringNotContainsString('session_secure_cookie', $encoded);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    }

    #[Test]
    public function maintenance_mode_degrades_the_report(): void
    {
        $this->application()->instance(MaintenanceMode::class, new class () implements MaintenanceMode {
            /**
             * @param array<string, mixed> $payload
             */
            public function activate(array $payload): void
            {
            }

            public function deactivate(): void
            {
            }

            public function active(): bool
            {
                return true;
            }

            /**
             * @return array<string, string>
             */
            public function data(): array
            {
                return ['secret' => 'maintenance-secret-not-in-output'];
            }
        });

        $report = $this->inspect();

        $this->assertSame(HealthStatus::Warning, $report->status);
        $this->assertSame(HealthStatus::Warning, $report->checks[0]->status);
        $this->assertStringNotContainsString('maintenance-secret-not-in-output', $this->encoded($report));
    }

    #[Test]
    public function an_enabled_sqlite_probe_is_read_only_and_leaves_configuration_unchanged(): void
    {
        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The sqlite probe needs the pdo_sqlite extension.');
        }

        $before = config('database.connections.sqlite');
        config()->set('beacon.health.database.enabled', true);
        config()->set('beacon.health.database.timeout_seconds', 2);

        $report = $this->inspect();

        $this->assertSame(HealthStatus::Healthy, $report->checks[1]->status);
        $this->assertSame('The default database connection answered a read-only probe.', $report->checks[1]->summary);
        $this->assertSame($before, config('database.connections.sqlite'));
    }

    #[Test]
    public function a_database_connection_failure_stays_inside_the_report_without_credentials(): void
    {
        $logged = $this->captureLog();
        $default = config('database.default');
        config()->set('beacon.health.database.enabled', true);
        config()->set('beacon.health.database.timeout_seconds', 1);
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'beacon',
            'username' => 'beacon',
            'password' => self::PASSWORD,
            'url' => 'mysql://beacon:' . self::PASSWORD . '@db.internal/app',
        ]);

        try {
            $report = $this->inspect();
        } finally {
            config()->set('database.default', $default);
        }

        $encoded = $this->encoded($report);
        $this->assertSame(HealthStatus::Critical, $report->status);
        $this->assertSame(HealthStatus::Critical, $report->checks[1]->status);
        $this->assertSame(HealthStatus::Healthy, $report->checks[0]->status);
        $this->assertSame(HealthStatus::Skipped, $report->checks[2]->status);
        $this->assertSame(HealthStatus::Skipped, $report->checks[3]->status);
        $this->assertStringNotContainsString(self::PASSWORD, $encoded);
        $this->assertStringNotContainsString('db.internal', $encoded);
        $this->assertStringNotContainsString('SQLSTATE', $encoded);
        $this->assertStringNotContainsString(self::PASSWORD, $logged());
        $this->assertStringNotContainsString('db.internal', $logged());
    }

    #[Test]
    public function a_cache_probe_reads_without_writing_and_discards_the_value(): void
    {
        config()->set('cache.default', 'array');
        config()->set('cache.stores.array', ['driver' => 'array']);
        $store = new RecordingArrayStore();
        Cache::extend('array', fn (): Repository => new Repository($store));
        $this->application()->make('cache')->purge('array');
        Cache::store('array')->put(LaravelCacheProbe::KEY, 'cache-secret-not-in-output', 60);
        $writes = $store->writes;
        config()->set('beacon.health.cache.enabled', true);

        $report = $this->inspect();

        $this->assertSame(HealthStatus::Healthy, $report->checks[2]->status);
        $this->assertSame($writes, $store->writes);
        $this->assertSame('cache-secret-not-in-output', Cache::store('array')->get(LaravelCacheProbe::KEY));
        $this->assertStringNotContainsString('cache-secret-not-in-output', $this->encoded($report));
    }

    #[Test]
    public function a_cache_failure_does_not_hide_the_other_checks(): void
    {
        config()->set('beacon.health.cache.enabled', true);
        $this->application()->instance(CacheProbe::class, new ThrowingCacheProbe());

        $report = $this->inspect();

        $this->assertSame(HealthStatus::Critical, $report->status);
        $this->assertSame(HealthStatus::Healthy, $report->checks[0]->status);
        $this->assertSame(HealthStatus::Skipped, $report->checks[1]->status);
        $this->assertSame(HealthStatus::Critical, $report->checks[2]->status);
        $this->assertSame(HealthStatus::Skipped, $report->checks[3]->status);
        $this->assertStringNotContainsString('cache-secret-not-in-output', $this->encoded($report));
    }

    #[Test]
    public function the_queue_check_does_not_dispatch_or_call_the_queue_healthy(): void
    {
        Queue::fake();
        config()->set('beacon.health.queue.enabled', true);
        config()->set('queue.connections.sync.password', 'queue-password-not-in-output');

        $report = $this->inspect();

        $default = config('queue.default');
        $this->assertIsString($default);
        $driver = config('queue.connections.' . $default . '.driver');
        $this->assertIsString($driver);
        $this->assertSame(HealthStatus::Unknown, $report->status);
        $this->assertSame(HealthStatus::Unknown, $report->checks[3]->status);
        $this->assertStringContainsString($driver, $report->checks[3]->summary);
        $this->assertStringNotContainsString('queue-password-not-in-output', $this->encoded($report));
        Queue::assertNothingPushed();
    }

    #[Test]
    public function arguments_are_rejected_without_repeating_them(): void
    {
        try {
            $this->application()->make(HealthInspector::class)->inspect([
                'probe' => 'probe-secret-not-in-output',
            ]);
            $this->fail('Arguments were accepted.');
        } catch (InvalidHealthQueryException $exception) {
            $this->assertSame('Health inspection does not accept arguments.', $exception->getMessage());
            $this->assertStringNotContainsString('probe-secret-not-in-output', $exception->getMessage());
        }
    }

    #[Test]
    public function the_health_capability_is_required(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), HealthInspector::NAME);
    }

    #[Test]
    public function a_disabled_inspector_is_not_available(): void
    {
        config()->set('beacon.inspectors.health.enabled', false);
        config()->set('beacon.security.allowed_capabilities', ['health']);

        $this->expectException(InspectorNotFoundException::class);

        $this->application()->make(Beacon::class)->inspect(Principal::local(), HealthInspector::NAME);
    }

    private function inspect(): HealthInspection
    {
        config()->set('beacon.security.allowed_capabilities', ['health']);
        $inspection = $this->application()->make(HealthInspector::class)->inspect();
        $this->assertInstanceOf(HealthInspection::class, $inspection);

        return $inspection;
    }

    private function encoded(HealthInspection $inspection): string
    {
        $encoded = json_encode($inspection->toArray());
        $this->assertIsString($encoded);

        return $encoded;
    }

    /**
     * @return \Closure(): string
     */
    private function captureLog(): \Closure
    {
        $logged = '';

        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged .= $message->message . ' ' . json_encode($message->context);
        });

        return static function () use (&$logged): string {
            return $logged;
        };
    }
}
