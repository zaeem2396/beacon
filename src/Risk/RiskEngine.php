<?php

declare(strict_types=1);

namespace Beacon\Risk;

use Beacon\Data\MigrationEntry;
use Beacon\Data\RiskFinding;
use Beacon\Data\RiskLimitation;
use Beacon\Migrations\DiscoveredFile;
use Beacon\Risk\Rules\RuleCatalog;

/**
 * Parses selected migration files and evaluates the rule catalog.
 *
 * Files are read as text. They are not included, instantiated, or executed.
 */
final class RiskEngine
{
    public const FINDING_LIMIT = 300;

    public function __construct(
        private readonly MigrationSourceReader $sources,
        private readonly PhpParserSyntax $syntax,
        private readonly OperationExtractor $extractor,
        private readonly RuleCatalog $rules,
        private readonly SchemaContext $schema,
    ) {
    }

    /**
     * @param list<MigrationEntry> $entries
     */
    public function analyze(string $basePath, array $entries, ?string $connection, bool $enrich): RiskEngineResult
    {
        $limitations = [];

        if (! class_exists(\PhpParser\ParserFactory::class)) {
            $this->limitation($limitations, null, 'The PHP parser is unavailable. Migration source was not treated as safe.');

            return new RiskEngineResult([], $limitations, 0, false, false, false, false);
        }

        /** @var list<ParsedMigration> $parsed */
        $parsed = [];
        $tables = [];

        foreach ($entries as $entry) {
            if (! $entry->fileExists || $entry->relativePath === null) {
                continue;
            }

            $read = $this->sources->read($basePath, $this->file($entry));

            if (! $read->available() || $read->contents === null || $read->relativePath === null) {
                $this->limitation($limitations, $entry->name, $this->problem($read->problem));

                continue;
            }

            $syntax = $this->syntax->parse($read->contents);

            if (! $syntax['available']) {
                $this->limitation($limitations, null, 'The PHP parser is unavailable. Migration source was not treated as safe.');

                return new RiskEngineResult([], $limitations, 0, false, false, false, false);
            }

            if (! $syntax['parsed']) {
                $this->limitation($limitations, $entry->name, 'The migration source could not be parsed. It was not treated as safe.');

                continue;
            }

            $extracted = $this->extractor->extract($syntax['statements']);
            $parsed[] = new ParsedMigration($entry, $read->relativePath, $extracted);

            if ($extracted->sawInclude) {
                $this->limitation($limitations, $entry->name, 'A migration contains include or require. Those files were not loaded.');
            }

            if ($extracted->sawEval) {
                $this->limitation($limitations, $entry->name, 'A migration contains eval. It was not executed.');
            }

            if ($extracted->sawTrait) {
                $this->limitation($limitations, $entry->name, 'A trait was not expanded, so its methods were not analyzed.');
            }

            foreach ($extracted->operations as $operation) {
                if ($operation->table !== null) {
                    $tables[] = $operation->table;
                }
            }
        }

        $lookup = $this->schema->lookup($connection, $tables, $enrich);
        $findings = [];

        foreach ($parsed as $item) {
            $entry = $item->entry;
            $extracted = $item->extracted;
            $facts = new MigrationFacts(
                $entry->name,
                $item->relativePath,
                $entry->status,
                $extracted->operations,
                $extracted->sawInclude,
                $extracted->sawEval,
                $extracted->sawTrait,
                $extracted->upLine,
                $extracted->downLine,
                $extracted->upPresent,
                $extracted->downPresent,
                $lookup->tables,
                $lookup->applied,
            );

            foreach ($this->rules->rules() as $rule) {
                foreach ($rule->evaluate($facts) as $finding) {
                    $findings[] = $finding;
                }
            }
        }

        usort($findings, static fn (RiskFinding $left, RiskFinding $right): int => [
            $left->migration,
            $left->line ?? PHP_INT_MAX,
            $left->ruleId,
            $left->phase->value,
        ] <=> [
            $right->migration,
            $right->line ?? PHP_INT_MAX,
            $right->ruleId,
            $right->phase->value,
        ]);

        $truncated = count($findings) > self::FINDING_LIMIT;

        if ($truncated) {
            $findings = array_slice($findings, 0, self::FINDING_LIMIT);
        }

        return new RiskEngineResult($findings, $limitations, count($parsed), $truncated, $lookup->applied, $lookup->failed, $lookup->truncated);
    }

    private function file(MigrationEntry $entry): DiscoveredFile
    {
        return new DiscoveredFile(
            $entry->name,
            $entry->relativePath,
            $entry->relativePath === null ? '' : dirname($entry->relativePath),
            $entry->source,
            $entry->modifiedAt,
            $entry->filenameAt,
        );
    }

    /**
     * @param list<RiskLimitation> $limitations
     */
    private function limitation(array &$limitations, ?string $migration, string $message): void
    {
        if (count($limitations) >= 12) {
            return;
        }

        $limitations[] = new RiskLimitation($migration, $message);
    }

    private function problem(?string $problem): string
    {
        return match ($problem) {
            'too_large' => 'A migration file was too large to analyze. It was not treated as safe.',
            'outside' => 'A migration file was skipped because it is outside the application.',
            default => 'A migration file could not be read. It was not treated as safe.',
        };
    }
}
