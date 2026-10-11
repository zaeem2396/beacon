<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Security;

use Beacon\Core\BeaconConfig;
use Beacon\Security\AuthenticationFailure;
use Beacon\Security\AuthenticationResult;
use Beacon\Security\BearerTokenAuthenticator;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BearerTokenAuthenticatorTest extends TestCase
{
    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private const PREVIOUS = 'fedcba9876543210fedcba9876543210';

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function unusableConfigurations(): iterable
    {
        yield 'no token' => [null, null];
        yield 'blank token' => ['   ', null];
        yield 'short token' => ['short', null];
        yield 'short previous token only' => [null, 'short'];
    }

    #[Test]
    #[DataProvider('unusableConfigurations')]
    public function it_fails_closed_without_a_usable_token(?string $token, ?string $previous): void
    {
        $result = $this->authenticate($this->request('Bearer ' . self::TOKEN), $token, $previous);

        $this->assertSame(AuthenticationFailure::NotConfigured, $result->failure);
    }

    #[Test]
    public function short_tokens_are_never_accepted(): void
    {
        $result = $this->authenticate($this->request('Bearer short'), self::TOKEN, 'short');

        $this->assertSame(AuthenticationFailure::Invalid, $result->failure);
    }

    #[Test]
    public function it_reports_missing_credentials(): void
    {
        $this->assertSame(AuthenticationFailure::Missing, $this->authenticate($this->request(null))->failure);
        $this->assertSame(AuthenticationFailure::Missing, $this->authenticate($this->request('  '))->failure);
    }

    #[Test]
    public function multiple_authorization_headers_are_malformed(): void
    {
        $request = $this->request('Bearer ' . self::TOKEN);
        $request->headers->set('Authorization', ['Bearer ' . self::TOKEN, 'Bearer ' . self::TOKEN]);

        $this->assertSame(AuthenticationFailure::Malformed, $this->authenticate($request)->failure);
    }

    #[Test]
    public function query_string_tokens_are_malformed(): void
    {
        $request = Request::create('https://localhost/beacon/mcp?access_token=' . self::TOKEN, 'POST');

        $this->assertSame(AuthenticationFailure::Malformed, $this->authenticate($request)->failure);
    }

    #[Test]
    public function it_identifies_which_token_matched_without_exposing_it(): void
    {
        $current = $this->authenticate($this->request('Bearer ' . self::TOKEN), self::TOKEN, self::PREVIOUS);
        $previous = $this->authenticate($this->request('Bearer ' . self::PREVIOUS), self::TOKEN, self::PREVIOUS);

        $this->assertTrue($current->authenticated());
        $this->assertSame('beacon-token:current', $current->principal->id);
        $this->assertSame(BearerTokenAuthenticator::METHOD, $current->principal->method);

        $this->assertTrue($previous->authenticated());
        $this->assertSame('beacon-token:previous', $previous->principal->id);

        $this->assertStringNotContainsString(self::TOKEN, serialize($current));
        $this->assertStringNotContainsString(self::PREVIOUS, serialize($previous));
    }

    #[Test]
    public function configured_tokens_are_trimmed(): void
    {
        $this->assertTrue($this->authenticate($this->request('Bearer ' . self::TOKEN), ' ' . self::TOKEN . "\n")->authenticated());
    }

    private function request(?string $authorization): Request
    {
        $server = $authorization === null ? [] : ['HTTP_AUTHORIZATION' => $authorization];

        return Request::create('https://localhost/beacon/mcp', 'POST', server: $server);
    }

    private function authenticate(Request $request, ?string $token = self::TOKEN, ?string $previous = null): AuthenticationResult
    {
        $config = new BeaconConfig(new Repository(['beacon' => ['auth' => [
            'token' => $token,
            'previous_token' => $previous,
        ]]]), 'testing');

        return (new BearerTokenAuthenticator($config))->authenticate($request);
    }
}
