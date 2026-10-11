<?php

declare(strict_types=1);

namespace Beacon\Security;

use Beacon\Contracts\Authenticator;
use Beacon\Core\BeaconConfig;
use Illuminate\Http\Request;

/**
 * Authenticates requests carrying "Authorization: Bearer <token>" against the
 * configured static tokens (RFC 6750 header form only).
 */
final class BearerTokenAuthenticator implements Authenticator
{
    public const METHOD = 'bearer-token';

    /**
     * RFC 6750 "b64token" credentials.
     */
    private const HEADER_PATTERN = '/^Bearer +([A-Za-z0-9\-._~+\/]+=*)$/i';

    public function __construct(
        private readonly BeaconConfig $config,
    ) {
    }

    public function authenticate(Request $request): AuthenticationResult
    {
        $accepted = $this->config->acceptedTokens();

        if ($accepted === []) {
            return AuthenticationResult::failure(AuthenticationFailure::NotConfigured);
        }

        // Tokens in URLs end up in access logs, proxies and browser history.
        if ($request->query->has('access_token')) {
            return AuthenticationResult::failure(AuthenticationFailure::Malformed);
        }

        $headers = array_values(array_filter(
            $request->headers->all('authorization'),
            static fn (?string $value): bool => $value !== null && trim($value) !== '',
        ));

        if ($headers === []) {
            return AuthenticationResult::failure(AuthenticationFailure::Missing);
        }

        if (count($headers) > 1 || preg_match(self::HEADER_PATTERN, trim($headers[0]), $matches) !== 1) {
            return AuthenticationResult::failure(AuthenticationFailure::Malformed);
        }

        // Comparing fixed-length digests keeps the comparison constant-time
        // and independent of the presented token's length. Every accepted
        // token is checked so timing does not reveal which one matched.
        $presented = hash('sha256', $matches[1], true);
        $matched = null;

        foreach ($accepted as $label => $token) {
            if (hash_equals(hash('sha256', $token, true), $presented) && $matched === null) {
                $matched = $label;
            }
        }

        if ($matched === null) {
            return AuthenticationResult::failure(AuthenticationFailure::Invalid);
        }

        return AuthenticationResult::success(new Principal('beacon-token:' . $matched, self::METHOD));
    }
}
