<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Security;

use Beacon\Contracts\Redactor;
use Beacon\Security\KeyPatternRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class KeyPatternRedactorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveKeys(): iterable
    {
        foreach ([
            'APP_KEY', 'DB_PASSWORD', 'AWS_SECRET_ACCESS_KEY', 'AWS_ACCESS_KEY_ID', 'MAIL_PASSWORD',
            'REDIS_PASSWORD', 'PUSHER_APP_SECRET', 'STRIPE_SECRET', 'GITHUB_TOKEN', 'Authorization',
            'HTTP_AUTHORIZATION', 'Cookie', 'Set-Cookie', 'x-api-key', 'apiKey', 'clientSecret',
            'oauth_client_secret', 'private_key', 'PASSPORT_PRIVATE_KEY', 'session_id', 'app.key',
            'app.previous_keys', 'database.connections.mysql.password', 'services.stripe.secret',
            'filesystems.disks.s3.key', 'queue.connections.sqs.secret', 'credentials', 'DBPASSWORD',
            'SENTRY_LARAVEL_DSN', 'remember_token', 'X-CSRF-TOKEN',
        ] as $key) {
            yield $key => [$key];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeKeys(): iterable
    {
        foreach ([
            'APP_NAME', 'APP_ENV', 'APP_DEBUG', 'APP_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
            'DB_CONNECTION', 'QUEUE_CONNECTION', 'app.name', 'app.env', 'session.lifetime',
            'session.driver', 'queue.default', 'cache.prefix', 'mail.mailers.smtp.host', 'monkey',
            'keyboard_layout', 'name', 'present', 'sensitive', 'value',
        ] as $key) {
            yield $key => [$key];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sensitiveValues(): iterable
    {
        yield 'pem private key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEow...\n-----END RSA PRIVATE KEY-----"];
        yield 'openssh private key' => ['-----BEGIN OPENSSH PRIVATE KEY-----'];
        yield 'database url' => ['mysql://forge:s3cr3t@127.0.0.1:3306/forge'];
        yield 'redis url with empty user' => ['redis://:s3cr3t@redis.internal:6379'];
        yield 'laravel app key' => ['base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10='];
        yield 'bearer header' => ['Bearer abc.def.ghi'];
        yield 'basic header' => ['Basic dXNlcjpwYXNz'];
        yield 'jwt in message' => ['Invalid token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2ln given'];
        yield 'aws access key id' => ['AKIAIOSFODNN7EXAMPLE'];
        yield 'github token' => ['ghp_' . str_repeat('a', 36)];
        yield 'slack token' => ['xoxb-1234567890-abcdef'];
        yield 'stripe key' => ['sk_live_' . str_repeat('a', 24)];
        yield 'api key' => ['sk-' . str_repeat('A', 32)];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function safeValues(): iterable
    {
        yield 'name' => ['Laravel'];
        yield 'url without credentials' => ['https://example.com/path?query=1'];
        yield 'host' => ['127.0.0.1'];
        yield 'integer' => [3306];
        yield 'boolean' => [true];
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    #[Test]
    #[DataProvider('sensitiveKeys')]
    public function it_detects_sensitive_keys(string $key): void
    {
        $this->assertTrue((new KeyPatternRedactor())->isSensitiveKey($key));
    }

    #[Test]
    #[DataProvider('safeKeys')]
    public function it_does_not_flag_safe_keys(string $key): void
    {
        $this->assertFalse((new KeyPatternRedactor())->isSensitiveKey($key));
    }

    #[Test]
    #[DataProvider('sensitiveValues')]
    public function it_detects_secret_looking_values(string $value): void
    {
        $this->assertTrue((new KeyPatternRedactor())->isSensitiveValue($value));
    }

    #[Test]
    #[DataProvider('safeValues')]
    public function it_does_not_flag_safe_values(mixed $value): void
    {
        $this->assertFalse((new KeyPatternRedactor())->isSensitiveValue($value));
    }

    #[Test]
    public function it_redacts_nested_arrays_by_key_and_value(): void
    {
        $redacted = (new KeyPatternRedactor())->redact([
            'name' => 'Laravel',
            'debug' => false,
            'key' => 'base64:abc',
            'connections' => [
                'mysql' => ['host' => '127.0.0.1', 'port' => 3306, 'password' => 'secret', 'url' => 'mysql://u:p@h/db'],
            ],
            'previous_keys' => ['old-key-1', 'old-key-2'],
            'tokens' => null,
            'has_api_key' => true,
            'list' => ['plain', 'Bearer abc'],
        ]);

        $this->assertSame([
            'name' => 'Laravel',
            'debug' => false,
            'key' => Redactor::REPLACEMENT,
            'connections' => [
                'mysql' => ['host' => '127.0.0.1', 'port' => 3306, 'password' => Redactor::REPLACEMENT, 'url' => Redactor::REPLACEMENT],
            ],
            'previous_keys' => Redactor::REPLACEMENT,
            'tokens' => null,
            'has_api_key' => true,
            'list' => ['plain', Redactor::REPLACEMENT],
        ], $redacted);
    }

    #[Test]
    public function it_supports_additional_configured_keys(): void
    {
        $redactor = new KeyPatternRedactor(['iban', 'services.acme', '__', '']);

        $this->assertTrue($redactor->isSensitiveKey('customer_IBAN'));
        $this->assertTrue($redactor->isSensitiveKey('services.acme'));
        $this->assertTrue($redactor->isSensitiveKey('services.acme.endpoint'));
        $this->assertFalse($redactor->isSensitiveKey('services.acmecorp'));
        $this->assertFalse($redactor->isSensitiveKey('APP_NAME'));
    }

    #[Test]
    public function built_in_rules_cannot_be_removed_by_configuration(): void
    {
        $this->assertTrue((new KeyPatternRedactor([]))->isSensitiveKey('DB_PASSWORD'));
    }

    #[Test]
    public function it_describes_sensitive_values_without_exposing_them(): void
    {
        $value = (new KeyPatternRedactor())->describe('DB_PASSWORD', 'actual-password');

        $this->assertSame([
            'name' => 'DB_PASSWORD',
            'present' => true,
            'sensitive' => true,
            'value' => '[REDACTED]',
        ], $value->toArray());
    }

    #[Test]
    public function it_describes_missing_sensitive_values(): void
    {
        $value = (new KeyPatternRedactor())->describe('APP_KEY', '');

        $this->assertFalse($value->present);
        $this->assertTrue($value->sensitive);
        $this->assertNull($value->value);
    }

    #[Test]
    public function it_describes_safe_values_as_is(): void
    {
        $value = (new KeyPatternRedactor())->describe('APP_ENV', 'production');

        $this->assertSame(['name' => 'APP_ENV', 'present' => true, 'sensitive' => false, 'value' => 'production'], $value->toArray());
    }

    #[Test]
    public function it_flags_secret_values_under_innocent_keys(): void
    {
        $value = (new KeyPatternRedactor())->describe('DATABASE_URL', 'pgsql://app:hunter2@db/app');

        $this->assertTrue($value->sensitive);
        $this->assertSame('[REDACTED]', $value->value);
    }

    #[Test]
    public function it_redacts_nested_secrets_inside_described_arrays(): void
    {
        $value = (new KeyPatternRedactor())->describe('database.connections.mysql', [
            'driver' => 'mysql',
            'password' => 'hunter2',
        ]);

        $this->assertFalse($value->sensitive);
        $this->assertSame(['driver' => 'mysql', 'password' => '[REDACTED]'], $value->value);
    }
}
