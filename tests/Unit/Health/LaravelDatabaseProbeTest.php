<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Health;

use Beacon\Health\LaravelDatabaseProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LaravelDatabaseProbeTest extends TestCase
{
    #[Test]
    public function the_timeout_keeps_its_pdo_key_and_the_original_configuration_is_unchanged(): void
    {
        $original = [
            'driver' => 'mysql',
            'password' => 'database-password-not-in-output',
            'options' => [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        ];

        $prepared = LaravelDatabaseProbe::prepare($original, 2);

        $this->assertSame($original, [
            'driver' => 'mysql',
            'password' => 'database-password-not-in-output',
            'options' => [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        ]);
        $this->assertIsArray($prepared['options']);
        $this->assertSame(2, $prepared['options'][\PDO::ATTR_TIMEOUT]);
        $this->assertSame(\PDO::ERRMODE_EXCEPTION, $prepared['options'][\PDO::ATTR_ERRMODE]);
        $this->assertArrayNotHasKey(0, $prepared['options']);
    }

    #[Test]
    public function sqlite_does_not_receive_a_timeout_attribute(): void
    {
        $prepared = LaravelDatabaseProbe::prepare(['driver' => 'sqlite', 'database' => ':memory:'], 2);

        $this->assertSame(['driver' => 'sqlite', 'database' => ':memory:'], $prepared);
    }

    #[Test]
    public function an_sqlite_file_is_opened_read_only_when_php_provides_the_flag(): void
    {
        $prepared = LaravelDatabaseProbe::prepare(['driver' => 'sqlite', 'database' => 'beacon.sqlite'], 2);
        $options = $prepared['options'] ?? null;

        if (defined('PDO::SQLITE_ATTR_OPEN_FLAGS') && defined('SQLITE_OPEN_READONLY')) {
            $this->assertIsArray($options);
            $this->assertSame(\SQLITE_OPEN_READONLY, $options[\PDO::SQLITE_ATTR_OPEN_FLAGS]);
        } else {
            $this->assertNull($options);
        }

        $this->assertArrayNotHasKey(\PDO::ATTR_TIMEOUT, is_array($options) ? $options : []);
    }
}
