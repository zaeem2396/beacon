<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Core\Beacon;
use Beacon\Data\RuntimeInspection;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Inspectors\RuntimeInspector;
use Beacon\Security\Principal;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RuntimeInspectorTest extends TestCase
{
    #[Test]
    public function it_reports_allowlisted_runtime_facts(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['runtime']);

        $inspection = $this->application()->make(RuntimeInspector::class)->inspect();
        $this->assertInstanceOf(RuntimeInspection::class, $inspection);
        $payload = $this->application()->make(Beacon::class)->inspect(Principal::local(), RuntimeInspector::NAME)->toArray();
        $encoded = (string) json_encode($payload);

        $this->assertSame(PHP_VERSION, $inspection->php->version);
        $this->assertSame(PHP_SAPI, $inspection->php->sapi);
        $this->assertNotSame('', $inspection->laravel->version);
        $this->assertSame('testing', $inspection->laravel->environment);
        $this->assertStringContainsString('Configured drivers are reported', $inspection->summary);
        $this->assertSame(PHP_VERSION, data_get($payload, 'php.version'));
        $this->assertStringNotContainsString('base64:', $encoded);
        $this->assertStringNotContainsString('password', $encoded);
    }

    #[Test]
    public function it_rejects_arguments_and_denies_without_capability(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['runtime']);

        $this->expectException(\Beacon\Exceptions\InvalidRuntimeQueryException::class);
        $this->application()->make(RuntimeInspector::class)->inspect(['detail' => 'full']);
    }

    #[Test]
    public function unauthorized_callers_are_denied(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['application']);

        $this->expectException(AuthorizationException::class);
        $this->application()->make(Beacon::class)->inspect(Principal::local(), RuntimeInspector::NAME);
    }
}
