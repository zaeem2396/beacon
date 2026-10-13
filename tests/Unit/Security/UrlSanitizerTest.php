<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Security;

use Beacon\Security\UrlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UrlSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validUrls(): iterable
    {
        yield 'https host' => ['https://app.example.com', 'https://app.example.com'];
        yield 'trailing slash' => ['https://app.example.com/', 'https://app.example.com'];
        yield 'http with port' => ['http://localhost:8000', 'http://localhost:8000'];
        yield 'sub-directory' => ['https://example.com/shop/', 'https://example.com/shop'];
        yield 'uppercase scheme and host' => ['HTTPS://App.Example.COM/Shop', 'https://app.example.com/Shop'];
        yield 'ipv4' => ['http://10.0.0.5:8080', 'http://10.0.0.5:8080'];
        yield 'ipv6' => ['http://[::1]:8000/', 'http://[::1]:8000'];
        yield 'container host name' => ['http://beacon_app', 'http://beacon_app'];
        yield 'surrounding whitespace' => ['  https://example.com  ', 'https://example.com'];
        yield 'user and password' => ['https://admin:hunter2@example.com/app', 'https://example.com/app'];
        yield 'user only' => ['https://deploy@example.com', 'https://example.com'];
        yield 'query string' => ['https://example.com/?token=abc123&signature=xyz', 'https://example.com'];
        yield 'fragment' => ['https://example.com/app#access_token=abc123', 'https://example.com/app'];
        yield 'everything at once' => ['https://u:p@example.com:8443/app/?key=v#frag', 'https://example.com:8443/app'];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidUrls(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'whitespace' => ['   '];
        yield 'integer' => [8000];
        yield 'boolean' => [true];
        yield 'array' => [['https://example.com']];
        yield 'host only' => ['example.com'];
        yield 'scheme relative' => ['//example.com'];
        yield 'path only' => ['/app'];
        yield 'missing host' => ['https://'];
        yield 'ftp' => ['ftp://files.example.com'];
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'invalid port' => ['http://example.com:99999'];
        yield 'inner whitespace' => ['https://exa mple.com'];
        yield 'control character' => ["https://example.com/\napp"];
        yield 'invalid host characters' => ['https://exa$mple.com'];
        yield 'unencoded unicode host' => ['https://exämple.com'];
        yield 'too long' => ['https://example.com/' . str_repeat('a', UrlSanitizer::MAX_LENGTH)];
    }

    #[Test]
    #[DataProvider('validUrls')]
    public function it_keeps_only_scheme_host_port_and_path(string $url, string $expected): void
    {
        $this->assertSame($expected, (new UrlSanitizer())->sanitize($url));
    }

    #[Test]
    #[DataProvider('invalidUrls')]
    public function it_rejects_values_that_are_not_absolute_http_urls(mixed $url): void
    {
        $this->assertNull((new UrlSanitizer())->sanitize($url));
    }

    #[Test]
    public function credentials_and_tokens_never_survive(): void
    {
        $sanitized = (string) (new UrlSanitizer())->sanitize('https://beacon:s3cr3t-pass@example.com/app?api_key=k3y#t0ken');

        foreach (['beacon', 's3cr3t-pass', 'api_key', 'k3y', 't0ken', '@', '?', '#'] as $fragment) {
            $this->assertStringNotContainsString($fragment, $sanitized);
        }
    }
}
