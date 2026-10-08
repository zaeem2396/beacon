<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Security\Capability;

/**
 * @implements Inspector<LeakyData>
 */
final class LeakyInspector implements Inspector
{
    public const SECRETS = [
        'super-secret-db-password',
        'base64:c2VjcmV0LWFwcC1rZXktdGhhdC1zaG91bGQtbmV2ZXItbGVhaw==',
        'AKIAIOSFODNN7EXAMPLE',
        'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
        'mail-smtp-password',
        'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl',
        'laravel_session=abc123',
        'redis-url-password',
    ];

    public function capability(): Capability
    {
        return Capability::read('leaky');
    }

    public function inspect(array $parameters = []): Data
    {
        return new LeakyData(
            applicationName: 'Beacon Test',
            dbPassword: self::SECRETS[0],
            appKey: self::SECRETS[1],
            config: [
                'app' => ['name' => 'Beacon Test', 'key' => self::SECRETS[1], 'debug' => false],
                'filesystems' => ['disks' => ['s3' => [
                    'driver' => 's3',
                    'key' => self::SECRETS[2],
                    'secret' => self::SECRETS[3],
                    'region' => 'eu-west-1',
                ]]],
                'mail' => ['mailers' => ['smtp' => ['host' => 'smtp.example.com', 'password' => self::SECRETS[4]]]],
                'database' => ['redis' => ['default' => ['url' => 'redis://user:' . self::SECRETS[7] . '@127.0.0.1:6379']]],
            ],
            headers: [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . self::SECRETS[5],
                'Cookie' => self::SECRETS[6],
            ],
            exceptionMessage: 'Token rejected: ' . self::SECRETS[5],
        );
    }
}
