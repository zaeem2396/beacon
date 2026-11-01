<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Risk;

use Beacon\Data\MigrationRiskInspection;
use Beacon\Exceptions\InvalidRiskQueryException;
use Beacon\Migrations\MigrationStatus;
use Beacon\Risk\Confidence;
use Beacon\Risk\ExtractedMigration;
use Beacon\Risk\FindingFactory;
use Beacon\Risk\MigrationFacts;
use Beacon\Risk\OperationExtractor;
use Beacon\Risk\PhpParserSyntax;
use Beacon\Risk\RiskQuery;
use Beacon\Risk\Rules\RuleCatalog;
use Beacon\Risk\Severity;
use Beacon\Risk\SqlClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MigrationRiskAnalysisTest extends TestCase
{
    #[Test]
    public function filters_reject_paths_sql_and_php_without_repeating_them(): void
    {
        $rejected = '../secret-risk-path';

        try {
            RiskQuery::from(['path' => $rejected, 'sql' => 'drop table orders']);
            $this->fail('A traversal path should be rejected.');
        } catch (InvalidRiskQueryException $exception) {
            $this->assertStringNotContainsString($rejected, $exception->getMessage());
            $this->assertStringNotContainsString('drop table', $exception->getMessage());
        }

        $this->expectException(InvalidRiskQueryException::class);
        RiskQuery::from(['pending' => false, 'include_executed' => false]);
    }

    #[Test]
    public function a_new_table_is_not_classified_as_critical(): void
    {
        $findings = $this->findings(<<<'PHP'
            public function up(): void
            {
                Schema::create('orders', function (Blueprint $table) {
                    $table->id();
                    $table->string('email')->unique();
                    $table->index('email');
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('orders');
            }
            PHP);

        $this->assertContains('MIG005', $this->ids($findings));
        $this->assertContains('MIG010', $this->ids($findings));
        $this->assertContains('MIG001', $this->ids($findings));
        $this->assertSame(Severity::Low, $this->rule($findings, 'MIG005')->severity);
        $this->assertSame(Severity::Informational, $this->rule($findings, 'MIG010')->severity);
        $this->assertSame(Severity::Informational, $this->rule($findings, 'MIG001')->severity);
        $this->assertNotContains(Severity::Critical, $this->severities($findings));
        $this->assertNotContains(Severity::High, $this->severities($findings));
    }

    #[Test]
    public function destructive_and_compatibility_patterns_keep_their_rule_ids(): void
    {
        $findings = $this->findings(<<<'PHP'
            public function up(): void
            {
                Schema::dropIfExists('orders');
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropColumn('legacy');
                    $table->renameColumn('old_name', 'new_name');
                    $table->string('name', 20)->change();
                    $table->decimal('amount', 8, 2)->change();
                    $table->string('email');
                    $table->string('note')->nullable();
                    $table->string('code')->unique();
                    $table->index('note');
                    $table->dropIndex('orders_note_index');
                    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                });
                Schema::rename('orders', 'purchases');
                DB::table('orders')->truncate();
                DB::statement('DROP TABLE payments');
                DB::statement('OPTIMIZE TABLE orders');
                DB::statement('DELETE FROM ' . $name);
            }

            public function down(): void
            {
            }
            PHP);

        $this->assertSame(Severity::Critical, $this->rule($findings, 'MIG001')->severity);
        $this->assertSame(Confidence::High, $this->rule($findings, 'MIG001')->confidence);
        $this->assertGreaterThan(0, $this->rule($findings, 'MIG001')->line ?? 0);
        $this->assertSame(Severity::High, $this->rule($findings, 'MIG002')->severity);
        $this->assertSame(Severity::High, $this->rule($findings, 'MIG008')->severity);
        $this->assertSame(Severity::High, $this->rule($findings, 'MIG007')->severity);
        $this->assertSame(Severity::High, $this->rule($findings, 'MIG012')->severity);
        $this->assertSame(Severity::High, $this->rule($findings, 'MIG004')->severity);
        $this->assertSame(Severity::Medium, $this->rule($findings, 'MIG005')->severity);
        $this->assertSame(Severity::Low, $this->rule($findings, 'MIG010')->severity);
        $this->assertSame(Severity::Medium, $this->rule($findings, 'MIG011')->severity);
        $this->assertSame(Severity::Medium, $this->rule($findings, 'MIG006')->severity);
        $this->assertSame(Severity::High, $this->rule($findings, 'MIG014')->severity);
        $this->assertSame(Severity::High, $this->rule($findings, 'MIG009')->severity);
        $this->assertSame(Severity::Critical, $this->rule($findings, 'MIG003')->severity);
        $this->assertSame(Severity::Critical, $this->rule($findings, 'MIG015')->severity);
        $this->assertSame(Severity::Medium, $this->rule($findings, 'MIG021')->severity);
        $this->assertSame(Confidence::Low, $this->rule($findings, 'MIG021')->confidence);
        $this->assertSame(Confidence::Low, $this->rule($findings, 'MIG016')->confidence);
        $this->assertStringContainsString('Unable to statically determine', $this->rule($findings, 'MIG016')->explanation);
        $this->assertSame(Severity::Medium, $this->rule($findings, 'MIG018')->severity);
        $this->assertNotContains('MIG004', $this->idsForTitle($findings, 'note'));
    }

    #[Test]
    public function backfills_distinguish_set_based_row_loops_and_chunks(): void
    {
        $update = $this->rule($this->findings(<<<'PHP'
            public function up(): void
            {
                DB::table('orders')->where('status', 'open')->update(['status' => 'paid']);
            }
            public function down(): void {}
            PHP), 'MIG013');
        $this->assertSame(Severity::Medium, $update->severity);

        $loop = $this->rule($this->findings(<<<'PHP'
            public function up(): void
            {
                DB::table('orders')->orderBy('id')->get();
            }
            public function down(): void {}
            PHP), 'MIG013');
        $this->assertSame(Severity::High, $loop->severity);

        $chunk = $this->rule($this->findings(<<<'PHP'
            public function up(): void
            {
                DB::table('orders')->orderBy('id')->chunk(100, function ($rows) {
                    DB::table('orders')->update(['seen' => 1]);
                });
            }
            public function down(): void {}
            PHP), 'MIG013');
        $this->assertSame(Severity::Low, $chunk->severity);
    }

    #[Test]
    public function comments_strings_other_methods_and_branches_are_not_blind_matches(): void
    {
        $quiet = $this->findings(<<<'PHP'
            public function up(): void
            {
                // Schema::dropIfExists('orders');
                /* Schema::drop('orders'); */
                $note = 'Schema::dropIfExists("orders")';
            }

            public function danger(): void
            {
                Schema::dropIfExists('orders');
            }

            public function down(): void
            {
            }
            PHP);

        $this->assertNotContains('MIG001', $this->ids($quiet));

        $branch = $this->rule($this->findings(<<<'PHP'
            public function up(): void
            {
                if (false) {
                    Schema::dropIfExists('orders');
                }
            }
            public function down(): void {}
            PHP), 'MIG001');

        $this->assertSame(Confidence::Medium, $branch->confidence);
        $this->assertNotSame(Confidence::High, $branch->confidence);
    }

    #[Test]
    public function schema_and_data_changes_in_one_method_are_reported(): void
    {
        $findings = $this->findings(<<<'PHP'
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('note')->nullable();
                });
                DB::table('orders')->update(['note' => 'ready']);
            }
            public function down(): void {}
            PHP);

        $this->assertContains('MIG019', $this->ids($findings));
        $this->assertContains('MIG013', $this->ids($findings));
    }

    #[Test]
    public function several_existing_table_changes_note_transactional_limits(): void
    {
        $findings = $this->findings(<<<'PHP'
            public function up(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->index('email');
                });
                Schema::table('orders', function (Blueprint $table) {
                    $table->index('name');
                });
            }
            public function down(): void
            {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropIndex('orders_email_index');
                });
            }
            PHP);

        $this->assertSame(Severity::Informational, $this->rule($findings, 'MIG017')->severity);
    }

    #[Test]
    public function sql_classification_never_executes_and_stays_conservative(): void
    {
        $this->assertSame('drop_table', SqlClassifier::effect('/* note */ DROP TABLE orders'));
        $this->assertSame('drop_column', SqlClassifier::effect('ALTER TABLE orders DROP COLUMN legacy'));
        $this->assertSame('unrecognized', SqlClassifier::effect('OPTIMIZE TABLE orders'));
        $this->assertSame('orders', SqlClassifier::table('drop table orders'));
    }

    #[Test]
    public function findings_serialize_with_evidence_fields(): void
    {
        $finding = $this->rule($this->findings(<<<'PHP'
            public function up(): void
            {
                Schema::dropIfExists('orders');
            }
            public function down(): void {}
            PHP), 'MIG001');

        $encoded = $finding->toArray();
        $this->assertSame('MIG001', $encoded['rule_id']);
        $this->assertSame('data_loss', $encoded['category']);
        $this->assertSame('critical', $encoded['severity']);
        $this->assertSame('high', $encoded['confidence']);
        $this->assertSame('2026_01_01_000000_probe_migration', $encoded['migration']);
        $this->assertIsString($encoded['relative_path']);
        $this->assertStringEndsWith('.php', $encoded['relative_path']);
        $this->assertIsInt($encoded['line']);
        $this->assertIsString($encoded['snippet']);
        $this->assertStringContainsString('Schema::dropIfExists', $encoded['snippet']);
        $this->assertTrue($encoded['manual_review']);
        $this->assertSame(1, MigrationRiskInspection::SCHEMA_VERSION);
    }

    /**
     * @return list<\Beacon\Data\RiskFinding>
     */
    private function findings(string $methods): array
    {
        $facts = $this->facts($this->extract($methods));
        $found = [];

        foreach ((new RuleCatalog(new FindingFactory()))->rules() as $rule) {
            foreach ($rule->evaluate($facts) as $finding) {
                $found[] = $finding;
            }
        }

        return $found;
    }

    private function extract(string $methods): ExtractedMigration
    {
        $syntax = (new PhpParserSyntax())->parse("<?php\nclass Probe\n{\n" . $methods . "\n}\n");
        $this->assertTrue($syntax['parsed']);

        return (new OperationExtractor())->extract($syntax['statements']);
    }

    private function facts(ExtractedMigration $extracted): MigrationFacts
    {
        return new MigrationFacts(
            '2026_01_01_000000_probe_migration',
            'database/migrations/2026_01_01_000000_probe_migration.php',
            MigrationStatus::Pending,
            $extracted->operations,
            $extracted->sawInclude,
            $extracted->sawEval,
            $extracted->sawTrait,
            $extracted->upLine,
            $extracted->downLine,
            $extracted->upPresent,
            $extracted->downPresent,
            [],
            false,
        );
    }

    /**
     * @param list<\Beacon\Data\RiskFinding> $findings
     *
     * @return list<string>
     */
    private function ids(array $findings): array
    {
        $ids = [];

        foreach ($findings as $finding) {
            $ids[] = $finding->ruleId;
        }

        return $ids;
    }

    /**
     * @param list<\Beacon\Data\RiskFinding> $findings
     */
    private function rule(array $findings, string $id): \Beacon\Data\RiskFinding
    {
        foreach ($findings as $finding) {
            if ($finding->ruleId === $id) {
                return $finding;
            }
        }

        $this->fail('Missing rule ' . $id);
    }

    /**
     * @param list<\Beacon\Data\RiskFinding> $findings
     *
     * @return list<Severity>
     */
    private function severities(array $findings): array
    {
        $severities = [];

        foreach ($findings as $finding) {
            $severities[] = $finding->severity;
        }

        return $severities;
    }

    /**
     * @param list<\Beacon\Data\RiskFinding> $findings
     *
     * @return list<string>
     */
    private function idsForTitle(array $findings, string $column): array
    {
        $ids = [];

        foreach ($findings as $finding) {
            if (str_contains($finding->snippet, '(' . $column . ')')) {
                $ids[] = $finding->ruleId;
            }
        }

        return $ids;
    }
}
