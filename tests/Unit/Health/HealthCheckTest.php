<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Health;

use Beacon\Core\BeaconConfig;
use Beacon\Health\Checks\ApplicationCheck;
use Beacon\Health\Checks\CacheCheck;
use Beacon\Health\Checks\DatabaseCheck;
use Beacon\Health\Checks\QueueCheck;
use Beacon\Health\HealthStatus;
use Beacon\Tests\Fixtures\RecordingDatabaseProbe;
use Beacon\Tests\Fixtures\ThrowingCacheProbe;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HealthCheckTest extends TestCase
{
    #[Test]
    public function an_unbooted_application_is_a_confirmed_failure(): void
    {
        $app = $this->createMock(Application::class);
        $app->expects($this->once())->method('isBooted')->willReturn(false);
        $app->expects($this->never())->method('version');

        $result = (new ApplicationCheck($app))->check();

        $this->assertSame(HealthStatus::Critical, $result->status);
        $this->assertSame('application', $result->name);
    }

    #[Test]
    public function maintenance_mode_is_degraded(): void
    {
        $app = $this->application(true, '13.0.0', 'testing', true);

        $result = (new ApplicationCheck($app))->check();

        $this->assertSame(HealthStatus::Warning, $result->status);
        $this->assertSame('enabled', $result->evidence[0]->observation->value);
    }

    #[Test]
    public function a_missing_version_is_unknown(): void
    {
        $app = $this->application(true, '', 'testing', false);

        $result = (new ApplicationCheck($app))->check();

        $this->assertSame(HealthStatus::Unknown, $result->status);
    }

    #[Test]
    public function the_database_probe_stays_off_and_does_not_read_a_secret(): void
    {
        $probe = new RecordingDatabaseProbe();
        $repository = new Repository([
            'database' => [
                'default' => 'mysql',
                'connections' => [
                    'mysql' => [
                        'password' => 'database-password-not-in-output',
                        'url' => 'mysql://beacon:database-password-not-in-output@db.internal/app',
                    ],
                ],
            ],
        ]);

        $result = (new DatabaseCheck($this->config($repository), $repository, $probe))->check();

        $this->assertSame(HealthStatus::Skipped, $result->status);
        $this->assertSame(0, $probe->calls);
        $this->assertStringNotContainsString('database-password-not-in-output', (string) json_encode($result->toArray()));
    }

    #[Test]
    public function an_invalid_database_timeout_does_not_run_the_probe(): void
    {
        $probe = new RecordingDatabaseProbe();
        $repository = new Repository([
            'beacon' => ['health' => ['database' => ['enabled' => true, 'timeout_seconds' => 100]]],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite']]],
        ]);

        $result = (new DatabaseCheck($this->config($repository), $repository, $probe))->check();

        $this->assertSame(HealthStatus::Unknown, $result->status);
        $this->assertSame(0, $probe->calls);
    }

    #[Test]
    public function a_string_timeout_is_bounded_before_the_probe_runs(): void
    {
        $probe = new RecordingDatabaseProbe();
        $repository = new Repository([
            'beacon' => ['health' => ['database' => ['enabled' => 'true', 'timeout_seconds' => '4']]],
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => ['driver' => 'mysql']]],
        ]);

        $result = (new DatabaseCheck($this->config($repository), $repository, $probe))->check();

        $this->assertSame(HealthStatus::Healthy, $result->status);
        $this->assertSame(1, $probe->calls);
        $this->assertSame(4, $probe->timeout);
    }

    #[Test]
    public function a_missing_sqlite_file_is_not_created(): void
    {
        $path = sys_get_temp_dir() . '/beacon-health-missing.sqlite';
        @unlink($path);
        $probe = new RecordingDatabaseProbe();
        $repository = new Repository([
            'beacon' => ['health' => ['database' => ['enabled' => true, 'timeout_seconds' => 2]]],
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => $path]],
            ],
        ]);

        $result = (new DatabaseCheck($this->config($repository), $repository, $probe))->check();

        $this->assertSame(HealthStatus::Unknown, $result->status);
        $this->assertSame(0, $probe->calls);
        $this->assertFileDoesNotExist($path);
        $this->assertStringNotContainsString('beacon-health-missing.sqlite', (string) json_encode($result->toArray()));
    }

    #[Test]
    public function a_dotted_database_name_is_not_followed(): void
    {
        $probe = new RecordingDatabaseProbe();
        $repository = new Repository([
            'beacon' => ['health' => ['database' => ['enabled' => true, 'timeout_seconds' => 2]]],
            'database' => ['default' => 'reports.primary', 'connections' => []],
        ]);

        $result = (new DatabaseCheck($this->config($repository), $repository, $probe))->check();

        $this->assertSame(HealthStatus::Unknown, $result->status);
        $this->assertSame(0, $probe->calls);
        $this->assertStringNotContainsString('reports.primary', (string) json_encode($result->toArray()));
    }

    #[Test]
    public function a_database_failure_is_critical_and_drops_the_driver_error(): void
    {
        $probe = new RecordingDatabaseProbe();
        $probe->fail = true;
        $repository = new Repository([
            'beacon' => ['health' => ['database' => ['enabled' => true, 'timeout_seconds' => 1]]],
            'database' => [
                'default' => 'mysql',
                'connections' => ['mysql' => ['password' => 'database-password-not-in-output']],
            ],
        ]);

        $result = (new DatabaseCheck($this->config($repository), $repository, $probe))->check();
        $encoded = (string) json_encode($result->toArray());

        $this->assertSame(HealthStatus::Critical, $result->status);
        $this->assertStringNotContainsString('database-password-not-in-output', $encoded);
        $this->assertStringNotContainsString('db.internal', $encoded);
    }

    #[Test]
    public function a_cache_driver_without_a_safe_read_is_skipped(): void
    {
        $probe = new ThrowingCacheProbe();
        $repository = new Repository([
            'beacon' => ['health' => ['cache' => ['enabled' => true]]],
            'cache' => [
                'default' => 'array',
                'stores' => ['array' => ['driver' => 'dynamodb', 'key' => 'cache-secret-not-in-output']],
            ],
        ]);

        $result = (new CacheCheck($this->config($repository), $repository, $probe))->check();

        $this->assertSame(HealthStatus::Skipped, $result->status);
        $this->assertSame(0, $probe->calls);
        $this->assertStringNotContainsString('dynamodb', (string) json_encode($result->toArray()));
        $this->assertStringNotContainsString('cache-secret-not-in-output', (string) json_encode($result->toArray()));
    }

    #[Test]
    public function the_null_cache_driver_is_skipped(): void
    {
        $probe = new ThrowingCacheProbe();
        $repository = new Repository([
            'beacon' => ['health' => ['cache' => ['enabled' => true]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'null']]],
        ]);

        $result = (new CacheCheck($this->config($repository), $repository, $probe))->check();

        $this->assertSame(HealthStatus::Skipped, $result->status);
        $this->assertSame('The null cache driver has no dependency to probe.', $result->summary);
        $this->assertSame(0, $probe->calls);
    }

    #[Test]
    public function a_cache_read_failure_is_critical_without_the_exception_text(): void
    {
        $probe = new ThrowingCacheProbe();
        $repository = new Repository([
            'beacon' => ['health' => ['cache' => ['enabled' => true]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
        ]);

        $result = (new CacheCheck($this->config($repository), $repository, $probe))->check();

        $this->assertSame(HealthStatus::Critical, $result->status);
        $this->assertSame(1, $probe->calls);
        $this->assertStringNotContainsString('cache-secret-not-in-output', (string) json_encode($result->toArray()));
    }

    #[Test]
    public function a_recognised_queue_driver_is_not_reported_healthy(): void
    {
        $repository = new Repository([
            'beacon' => ['health' => ['queue' => ['enabled' => true]]],
            'queue' => [
                'default' => 'redis',
                'connections' => [
                    'redis' => [
                        'driver' => 'redis',
                        'password' => 'queue-password-not-in-output',
                    ],
                ],
            ],
        ]);

        $result = (new QueueCheck($this->config($repository), $repository))->check();
        $encoded = (string) json_encode($result->toArray());

        $this->assertSame(HealthStatus::Unknown, $result->status);
        $this->assertStringContainsString('redis', $result->summary);
        $this->assertStringNotContainsString('queue-password-not-in-output', $encoded);
    }

    #[Test]
    public function an_unreadable_queue_driver_is_unknown_and_not_echoed(): void
    {
        $repository = new Repository([
            'beacon' => ['health' => ['queue' => ['enabled' => true]]],
            'queue' => [
                'default' => 'jobs',
                'connections' => ['jobs' => ['driver' => 'queue-secret-not-in-output']],
            ],
        ]);

        $result = (new QueueCheck($this->config($repository), $repository))->check();

        $this->assertSame(HealthStatus::Unknown, $result->status);
        $this->assertStringNotContainsString('queue-secret-not-in-output', (string) json_encode($result->toArray()));
    }

    private function config(Repository $repository): BeaconConfig
    {
        return new BeaconConfig($repository, 'testing');
    }

    private function application(bool $booted, string $version, string $environment, bool $maintenance): Application
    {
        $app = $this->createStub(Application::class);
        $app->method('isBooted')->willReturn($booted);
        $app->method('version')->willReturn($version);
        $app->method('environment')->willReturn($environment);
        $app->method('isDownForMaintenance')->willReturn($maintenance);

        return $app;
    }
}
