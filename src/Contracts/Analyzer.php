<?php

declare(strict_types=1);

namespace Beacon\Contracts;

/**
 * Derives a deterministic assessment (risk, findings, ...) from inspector data.
 *
 * Analyzers consume Beacon data only and never query Laravel internals.
 *
 * @template TSubject of Data
 *
 * @template-covariant TResult of Data
 */
interface Analyzer
{
    /**
     * @param TSubject $subject
     *
     * @return TResult
     */
    public function analyze(Data $subject): Data;
}
