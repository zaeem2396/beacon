<?php

declare(strict_types=1);

namespace Beacon\Security;

use Beacon\Contracts\Redactor;
use Beacon\Data\Normalizer;
use Beacon\Data\SafeValue;

/**
 * Redacts values by key name and by recognisable secret value formats.
 *
 * Built-in rules always apply; configured keys can only add to them.
 */
final class KeyPatternRedactor implements Redactor
{
    /**
     * Matched anywhere within a key segment once separators are removed,
     * e.g. "DB_PASSWORD", "x-api-key", "clientSecret".
     */
    private const SENSITIVE_FRAGMENTS = [
        'password',
        'passwd',
        'passphrase',
        'secret',
        'token',
        'credential',
        'apikey',
        'accesskey',
        'privatekey',
        'cookie',
        'authorization',
        'bearer',
        'sessionid',
    ];

    /**
     * Matched only as whole words within a key segment, e.g. "APP_KEY",
     * "services.stripe.key", "app.previous_keys".
     */
    private const SENSITIVE_WORDS = [
        'key',
        'keys',
        'pwd',
        'salt',
        'dsn',
        'jwt',
        'otp',
    ];

    /**
     * Matched anywhere in a string, so a secret embedded in a longer text such
     * as an exception message causes the whole string to be redacted.
     */
    private const SENSITIVE_VALUE_PATTERNS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----/',
        '#\b[a-z][a-z0-9+.-]*://[^/\s:@]*:[^/\s@]+@#i',
        '/\bbase64:[A-Za-z0-9+\/]{32,}={0,2}/',
        '/^(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*$/i',
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]{16,}=*/i',
        '/\bBasic\s+[A-Za-z0-9+\/]{16,}={0,2}/',
        '/(?<![a-z])(password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|client[_-]?secret|private[_-]?key)["\']?\s*[:=]\s*["\']?[^\s"\'&;,()]{4,}/i',
        '/\beyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/',
        '/\b(AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/\b(gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{22,})/',
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        '/\b[rs]k_(live|test)_[A-Za-z0-9]{16,}/',
        '/\bsk-[A-Za-z0-9_-]{20,}/',
    ];

    /**
     * Configured dotted paths, e.g. "services.acme", matched as a key prefix.
     *
     * @var list<string>
     */
    private readonly array $additionalPaths;

    /**
     * Configured names with separators removed, matched within a key segment.
     *
     * @var list<string>
     */
    private readonly array $additionalFragments;

    /**
     * @param list<string> $additionalKeys
     */
    public function __construct(array $additionalKeys = [])
    {
        $paths = [];
        $fragments = [];

        foreach ($additionalKeys as $key) {
            $key = strtolower(trim($key));

            if (str_contains($key, '.')) {
                $paths[] = trim($key, '.');
            } elseif (($fragment = implode('', $this->words($key))) !== '') {
                $fragments[] = $fragment;
            }
        }

        $this->additionalPaths = array_values(array_filter($paths));
        $this->additionalFragments = $fragments;
    }

    public function isSensitiveKey(string $key): bool
    {
        $lowerKey = strtolower($key);

        foreach ($this->additionalPaths as $path) {
            if ($lowerKey === $path || str_starts_with($lowerKey, "{$path}.")) {
                return true;
            }
        }

        foreach (explode('.', $key) as $segment) {
            if ($this->isSensitiveSegment($segment)) {
                return true;
            }
        }

        return false;
    }

    public function isSensitiveValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->isSensitiveValue($item)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($value) || $value === '') {
            return false;
        }

        foreach (self::SENSITIVE_VALUE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    public function redact(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = $this->redactValue($value, is_string($key) && $this->isSensitiveKey($key));
        }

        return $result;
    }

    public function describe(string $name, mixed $value): SafeValue
    {
        $normalized = Normalizer::normalize($value);
        $present = ! ($normalized === null || $normalized === '' || $normalized === []);
        $sensitive = $this->isSensitiveKey($name) || $this->isSensitiveValue($normalized);

        if ($sensitive) {
            return new SafeValue($name, $present, true, $present ? self::REPLACEMENT : null);
        }

        return new SafeValue(
            $name,
            $present,
            false,
            is_array($normalized) ? $this->redact($normalized) : $normalized,
        );
    }

    private function redactValue(mixed $value, bool $sensitiveKey): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $sensitiveKey ? self::REPLACEMENT : $this->redact($value);
        }

        if (! is_scalar($value)) {
            return self::REPLACEMENT;
        }

        return $sensitiveKey || $this->isSensitiveValue($value) ? self::REPLACEMENT : $value;
    }

    private function isSensitiveSegment(string $segment): bool
    {
        $words = $this->words($segment);

        if ($words === []) {
            return false;
        }

        $compact = implode('', $words);

        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($compact, $fragment)) {
                return true;
            }
        }

        if (array_intersect($words, self::SENSITIVE_WORDS) !== []) {
            return true;
        }

        foreach ($this->additionalFragments as $fragment) {
            if (str_contains($compact, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split a key segment into lowercase words on separators and camelCase.
     *
     * @return list<string>
     */
    private function words(string $segment): array
    {
        $spaced = (string) preg_replace(['/([a-z0-9])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'], '$1 $2', $segment);
        $words = preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }
}
