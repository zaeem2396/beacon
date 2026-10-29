<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Database;

use Beacon\Database\DatabaseVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DatabaseVersionTest extends TestCase
{
    #[Test]
    #[DataProvider('versions')]
    public function it_keeps_a_short_server_version(string $raw, ?string $expected): void
    {
        $this->assertSame($expected, DatabaseVersion::sanitize($raw));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function versions(): array
    {
        return [
            'mysql' => ['8.0.36', '8.0.36'],
            'mysql packaging' => ['8.0.36-0ubuntu0.22.04.1', '8.0.36'],
            'mariadb' => ['10.11.6-MariaDB', '10.11.6-MariaDB'],
            'mariadb packaging' => ['10.11.6-MariaDB-1:10.11.6+maria~ubu2204', '10.11.6-MariaDB'],
            'mariadb compatibility prefix' => ['5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu2204', '10.11.6-MariaDB'],
            'sqlite' => ['3.45.1', '3.45.1'],
            'address' => ['mysql://beacon:database-password-not-in-output@db.internal/app', null],
            'empty' => ['', null],
            'too long' => [str_repeat('8.0.36-', 20), null],
        ];
    }
}
