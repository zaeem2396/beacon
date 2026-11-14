<?php

declare(strict_types=1);

namespace Beacon\Runtime;

/**
 * Truncates and strips secret-shaped fragments from untrusted diagnostic text.
 */
final class TextSanitizer
{
    public const MAX_LENGTH = 240;

    /**
     * @var list<string>
     */
    private const SECRET_PATTERNS = [
        '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----.*?-----END [A-Z0-9 ]*PRIVATE KEY-----/s',
        '#\b[a-z][a-z0-9+.-]*://[^/\s:@]*:[^/\s@]+@#i',
        '/\bbase64:[A-Za-z0-9+\/]{32,}={0,2}/',
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]{16,}=*/i',
        '/\bBasic\s+[A-Za-z0-9+\/]{16,}={0,2}/',
        '/(?<![a-z])(password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|client[_-]?secret|private[_-]?key|authorization)["\']?\s*[:=]\s*["\']?[^\s"\'&;,()]{4,}/i',
        '/\beyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]*/',
        '/\b(AKIA|ASIA)[0-9A-Z]{16}\b/',
        '/\b(gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{22,})/',
        '/\bxox[abprs]-[A-Za-z0-9-]{10,}/',
        '/\b[rs]k_(live|test)_[A-Za-z0-9]{16,}/',
        '/\bsk-[A-Za-z0-9_-]{20,}/',
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/',
    ];

    public static function sanitize(?string $value, int $max = self::MAX_LENGTH): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if ($value === '') {
            return null;
        }

        foreach (self::SECRET_PATTERNS as $pattern) {
            $value = preg_replace($pattern, 'redacted', $value) ?? $value;
        }

        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max - 1) . '…';
        }

        return $value === '' ? null : $value;
    }

    public static function normalizeForFingerprint(?string $value): string
    {
        $value = self::sanitize($value) ?? '';
        $value = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '{id}', $value) ?? $value;
        $value = preg_replace('/\b\d{4,}\b/', '{n}', $value) ?? $value;

        return strtolower(trim($value));
    }
}
