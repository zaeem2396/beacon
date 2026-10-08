<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InspectorNotFoundException;
use Beacon\Tests\Fixtures\ApplicationInspector;
use Beacon\Tests\Fixtures\LeakyInspector;
use Beacon\Tests\Fixtures\MutatingInspector;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BeaconInspectionTest extends TestCase
{
    #[Test]
    public function it_runs_a_registered_inspector_and_returns_a_payload(): void
    {
        $this->registry()->register('application', ApplicationInspector::class);

        $payload = $this->beacon()->inspect('application', ['detail' => 'full']);

        $this->assertSame([
            'laravel_version' => '13.0.0',
            'php_version' => '8.3.6',
            'environment' => 'testing',
            'application_name' => 'Beacon Test',
        ], $payload->toArray());
        $this->assertSame(['detail' => 'full'], ApplicationInspector::$lastParameters);
    }

    #[Test]
    public function it_lists_enabled_inspectors_in_registration_order(): void
    {
        config()->set('beacon.inspectors.leaky.enabled', false);

        $this->registry()->register('application', ApplicationInspector::class);
        $this->registry()->register('leaky', LeakyInspector::class);

        $this->assertTrue($this->registry()->has('leaky'));
        $this->assertFalse($this->registry()->enabled('leaky'));
        $this->assertSame(['application'], $this->registry()->names());
    }

    #[Test]
    public function it_rejects_unknown_inspectors(): void
    {
        $this->expectException(InspectorNotFoundException::class);

        $this->beacon()->inspect('missing');
    }

    #[Test]
    public function it_rejects_disabled_inspectors(): void
    {
        config()->set('beacon.inspectors.application.enabled', false);
        $this->registry()->register('application', ApplicationInspector::class);

        $this->expectException(InspectorNotFoundException::class);
        $this->expectExceptionMessage('disabled');

        $this->beacon()->inspect('application');
    }

    #[Test]
    public function it_only_registers_classes_implementing_the_inspector_contract(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // @phpstan-ignore argument.type
        $this->registry()->register('invalid', \stdClass::class);
    }

    #[Test]
    public function it_rejects_invalid_inspector_names(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->registry()->register('Not Valid', ApplicationInspector::class);
    }

    #[Test]
    public function it_never_runs_mutating_inspectors(): void
    {
        $this->registry()->register('cache', MutatingInspector::class);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('read-only');

        $this->beacon()->inspect('cache');
    }

    #[Test]
    public function it_denies_capabilities_that_are_not_allowed(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['routes']);
        $this->registry()->register('application', ApplicationInspector::class);

        $this->expectException(AuthorizationException::class);

        $this->beacon()->inspect('application');
    }

    #[Test]
    public function it_denies_everything_when_beacon_is_disabled(): void
    {
        config()->set('beacon.enabled', false);
        $this->registry()->register('application', ApplicationInspector::class);

        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('Beacon is disabled');

        $this->beacon()->inspect('application');
    }

    #[Test]
    public function secrets_leaked_by_an_inspector_never_reach_the_payload(): void
    {
        $this->registry()->register('leaky', LeakyInspector::class);

        $payload = $this->beacon()->inspect('leaky');
        $json = $payload->toJson();

        foreach (LeakyInspector::SECRETS as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        $data = $payload->toArray();

        $this->assertSame('Beacon Test', $data['application_name']);
        $this->assertSame('[REDACTED]', $data['db_password']);
        $this->assertSame('[REDACTED]', $data['app_key']);
        $this->assertSame('[REDACTED]', $data['exception_message']);
        $this->assertSame(['Accept' => 'application/json', 'Authorization' => '[REDACTED]', 'Cookie' => '[REDACTED]'], $data['headers']);
        $this->assertSame(['name' => 'Beacon Test', 'key' => '[REDACTED]', 'debug' => false], data_get($data, 'config.app'));
        $this->assertSame('eu-west-1', data_get($data, 'config.filesystems.disks.s3.region'));
        $this->assertSame('[REDACTED]', data_get($data, 'config.filesystems.disks.s3.secret'));
        $this->assertSame('smtp.example.com', data_get($data, 'config.mail.mailers.smtp.host'));
    }

    private function beacon(): Beacon
    {
        return $this->application()->make(Beacon::class);
    }

    private function registry(): InspectorRegistry
    {
        return $this->application()->make(InspectorRegistry::class);
    }
}
