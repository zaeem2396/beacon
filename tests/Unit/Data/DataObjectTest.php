<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Data;

use Beacon\Contracts\Data;
use Beacon\Data\DataObject;
use Beacon\Data\HealthCheckResult;
use Beacon\Data\SafeValue;
use Beacon\Health\HealthStatus;
use Beacon\Tests\Fixtures\ApplicationInfo;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DataObjectTest extends TestCase
{
    #[Test]
    public function it_serializes_public_properties_in_declaration_order_with_snake_case_keys(): void
    {
        $info = new ApplicationInfo('13.0.0', '8.3.6', 'production', 'Shop');

        $this->assertSame([
            'laravel_version' => '13.0.0',
            'php_version' => '8.3.6',
            'environment' => 'production',
            'application_name' => 'Shop',
        ], $info->toArray());
    }

    #[Test]
    public function serialization_is_deterministic(): void
    {
        $a = new ApplicationInfo('13.0.0', '8.3.6', 'production', 'Shop');
        $b = new ApplicationInfo('13.0.0', '8.3.6', 'production', 'Shop');

        $this->assertSame(json_encode($a->toArray()), json_encode($b->toArray()));
    }

    #[Test]
    public function it_serializes_enums_and_nested_data(): void
    {
        $result = new HealthCheckResult('database', HealthStatus::Warning, 'Slow connection', [
            'latency_ms' => 812.5,
            'value' => new SafeValue('DB_HOST', true, false, '127.0.0.1'),
        ]);

        $this->assertSame([
            'name' => 'database',
            'status' => 'warning',
            'summary' => 'Slow connection',
            'details' => [
                'latency_ms' => 812.5,
                'value' => ['name' => 'DB_HOST', 'present' => true, 'sensitive' => false, 'value' => '127.0.0.1'],
            ],
        ], $result->toArray());
    }

    #[Test]
    public function data_objects_are_not_directly_json_serializable(): void
    {
        $base = new \ReflectionClass(DataObject::class);

        $this->assertTrue($base->implementsInterface(Data::class));
        $this->assertFalse($base->implementsInterface(\JsonSerializable::class));
    }

    #[Test]
    public function safe_values_refuse_to_carry_sensitive_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SafeValue('DB_PASSWORD', true, true, 'actual-password');
    }

    #[Test]
    public function safe_values_accept_the_redaction_marker(): void
    {
        $value = new SafeValue('DB_PASSWORD', true, true, '[REDACTED]');

        $this->assertSame('[REDACTED]', $value->value);
    }
}
