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
        $result = new HealthCheckResult(
            'database',
            'A read-only connectivity probe of the default database connection, when enabled.',
            HealthStatus::Warning,
            'The database probe is slow.',
            12,
            'Check the default database.',
            [new \Beacon\Data\FindingEvidence('database.probe', \Beacon\Data\Observation::Enabled)],
        );

        $this->assertSame([
            'name' => 'database',
            'description' => 'A read-only connectivity probe of the default database connection, when enabled.',
            'status' => 'warning',
            'summary' => 'The database probe is slow.',
            'duration_ms' => 12,
            'remediation' => 'Check the default database.',
            'evidence' => [
                ['name' => 'database.probe', 'observation' => 'enabled'],
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
