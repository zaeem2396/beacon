<?php

declare(strict_types=1);

namespace Beacon\Logs;

/**
 * Deterministic exception categorization from class and message evidence.
 */
final class ExceptionClassifier
{
    public function classify(?string $class, ?string $message): ExceptionCategory
    {
        $haystack = strtolower(($class ?? '') . ' ' . ($message ?? ''));

        if ($haystack === ' ' || $haystack === '') {
            return ExceptionCategory::Unknown;
        }

        if (str_contains($haystack, 'allowed memory size') || str_contains($haystack, 'memory exhausted')) {
            return ExceptionCategory::Memory;
        }

        if (str_contains($haystack, 'maximum execution time') || str_contains($haystack, 'timed out') || str_contains($haystack, 'timeout')) {
            return ExceptionCategory::Timeout;
        }

        if ($this->matches($haystack, ['queryexception', 'pdoexception', 'uniqueconstraint', 'sqlstate', 'database'])) {
            return ExceptionCategory::Database;
        }

        if ($this->matches($haystack, ['connection refused', 'connection timed out', 'could not connect', 'connectionexception'])) {
            return ExceptionCategory::Connection;
        }

        if ($this->matches($haystack, ['authenticationexception', 'unauthenticated'])) {
            return ExceptionCategory::Authentication;
        }

        if ($this->matches($haystack, ['authorizationexception', 'accessdeniedhttpexception', 'forbidden', 'unauthorized'])) {
            return ExceptionCategory::Authorization;
        }

        if ($this->matches($haystack, ['validationexception', 'the given data was invalid'])) {
            return ExceptionCategory::Validation;
        }

        if ($this->matches($haystack, ['httpexception', 'notfoundhttpexception', 'methodnotallowed', 'symfony\\component\\httpkernel'])) {
            return ExceptionCategory::Http;
        }

        if ($this->matches($haystack, ['maxattemptsexceededexception', 'jobattemptsexceeded', 'queue', 'worker'])) {
            return ExceptionCategory::Queue;
        }

        if ($this->matches($haystack, ['filenotfoundexception', 'filesystem', 'league\\flysystem', 'no such file', 'permission denied'])) {
            return ExceptionCategory::Filesystem;
        }

        if ($this->matches($haystack, ['guzzle', 'connectionexception', 'curl error', 'transferexception', 'http client'])) {
            return ExceptionCategory::External;
        }

        if ($this->matches($haystack, ['errorexception', 'typeerror', 'parseerror', 'argumentcounterror', 'divisionbyzeroerror'])) {
            return ExceptionCategory::Php;
        }

        return ExceptionCategory::Unknown;
    }

    /**
     * @param list<string> $needles
     */
    private function matches(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
