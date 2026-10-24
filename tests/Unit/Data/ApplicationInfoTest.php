<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Core\Payload;
use Beacon\Data\ApplicationInfo;
use Beacon\Security\KeyPatternRedactor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationInfoTest extends TestCase
{
    private const KEYS = [
        'schema_version',
        'name',
        'environment',
        'debug',
        'url',
        'timezone',
        'locale',
        'fallback_locale',
        'maintenance_mode',
        'laravel_version',
        'php_version',
        'beacon_version',
    ];

    #[Test]
    public function it_serializes_every_field_in_a_stable_order_with_snake_case_keys(): void
    {
        $this->assertSame([
            'schema_version' => 1,
            'name' => 'Checkout',
            'environment' => 'production',
            'debug' => false,
            'url' => 'https://shop.example.com',
            'timezone' => 'Europe/Amsterdam',
            'locale' => 'nl',
            'fallback_locale' => 'en',
            'maintenance_mode' => false,
            'laravel_version' => '13.35.0',
            'php_version' => '8.3.6',
            'beacon_version' => 'v0.1.0',
        ], $this->complete()->toArray());
    }

    #[Test]
    public function unavailable_values_are_serialized_as_null_and_never_omitted(): void
    {
        $data = (new ApplicationInfo(null, 'local', null, null, null, null, null, null, '13.35.0', '8.3.6', null))->toArray();

        $this->assertSame(self::KEYS, array_keys($data));

        foreach (['name', 'debug', 'url', 'timezone', 'locale', 'fallback_locale', 'maintenance_mode', 'beacon_version'] as $key) {
            $this->assertNull($data[$key], $key);
        }
    }

    #[Test]
    public function it_crosses_the_payload_boundary_unchanged(): void
    {
        $payload = Payload::fromData($this->complete(), new KeyPatternRedactor());

        $this->assertSame($this->complete()->toArray(), $payload->toArray());
        $this->assertSame(
            '{"schema_version":1,"name":"Checkout","environment":"production","debug":false,"url":"https://shop.example.com",'
            . '"timezone":"Europe/Amsterdam","locale":"nl","fallback_locale":"en","maintenance_mode":false,'
            . '"laravel_version":"13.35.0","php_version":"8.3.6","beacon_version":"v0.1.0"}',
            $payload->toJson(),
        );
    }

    #[Test]
    public function secrets_placed_in_a_field_by_mistake_are_redacted_by_the_payload(): void
    {
        $info = new ApplicationInfo(
            'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=',
            'production',
            false,
            'https://admin:hunter2@shop.example.com',
            null,
            null,
            null,
            null,
            '13.35.0',
            '8.3.6',
            null,
        );

        $json = Payload::fromData($info, new KeyPatternRedactor())->toJson();

        $this->assertStringNotContainsString('2fl+Ktvkfl', $json);
        $this->assertStringNotContainsString('hunter2', $json);
    }

    private function complete(): ApplicationInfo
    {
        return new ApplicationInfo(
            name: 'Checkout',
            environment: 'production',
            debug: false,
            url: 'https://shop.example.com',
            timezone: 'Europe/Amsterdam',
            locale: 'nl',
            fallbackLocale: 'en',
            maintenanceMode: false,
            laravelVersion: '13.35.0',
            phpVersion: '8.3.6',
            beaconVersion: 'v0.1.0',
        );
    }
}
