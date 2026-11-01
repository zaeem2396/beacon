<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Comparison\SnapshotFiles;
use Beacon\Console\Commands\SchemaCompareCommand;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class SchemaCompareCommandTest extends TestCase
{
    #[Test]
    public function it_is_denied_before_snapshot_files_are_read(): void
    {
        $exitCode = Artisan::call('beacon:schema-compare', [
            'baseline' => '../.env',
            'candidate' => 'secret.json',
        ]);
        $output = Artisan::output();

        $this->assertSame(SchemaCompareCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('schema_compare', $output);
        $this->assertStringNotContainsString('.env', $output);
        $this->assertStringNotContainsString('secret.json', $output);
    }

    #[Test]
    public function a_path_is_rejected_and_a_safe_file_pair_can_be_compared(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['schema_compare']);
        $files = new SnapshotFiles(storage_path('beacon/snapshots'), storage_path());
        $document = [
            'format_version' => 1,
            'metadata' => [
                'beacon_version' => 'unknown',
                'laravel_version' => '13.0.0',
                'driver' => 'mysql',
                'server_version' => '8.4.0',
                'database' => 'app',
                'source' => 'snapshot',
                'identifier' => '0123456789abcdef',
                'created_at' => '2026-01-01T00:00:00+00:00',
                'complete' => true,
                'columns_included' => true,
                'indexes_included' => true,
                'references_included' => true,
                'views_included' => true,
            ],
            'objects' => [],
        ];

        try {
            $denied = Artisan::call('beacon:schema-compare', [
                'baseline' => '../.env',
                'candidate' => 'baseline.json',
            ]);
            $deniedOutput = Artisan::output();
            $files->write('baseline.json', (string) json_encode($document));
            $document['metadata']['database'] = 'other';
            $document['metadata']['created_at'] = '2026-02-01T00:00:00+00:00';
            $files->write('candidate.json', (string) json_encode($document));
            $exitCode = Artisan::call('beacon:schema-compare', [
                'baseline' => 'baseline.json',
                'candidate' => 'candidate.json',
                '--json' => true,
            ]);
            $decoded = json_decode(Artisan::output(), true);

            $this->assertSame(SchemaCompareCommand::EXIT_DENIED, $denied);
            $this->assertStringNotContainsString('.env', $deniedOutput);

            $missing = Artisan::call('beacon:schema-compare', [
                'baseline' => 'missing-snapshot.json',
                'candidate' => 'baseline.json',
            ]);
            $missingOutput = Artisan::output();
            $this->assertSame(SchemaCompareCommand::EXIT_DENIED, $missing);
            $this->assertStringContainsString('was not found', $missingOutput);
            $this->assertStringNotContainsString('missing-snapshot.json', $missingOutput);
            $this->assertSame(0, $exitCode);
            $this->assertIsArray($decoded);
            $summary = $decoded['summary'] ?? null;
            $this->assertIsArray($summary);
            $this->assertSame(0, $summary['differences']);
            $this->assertTrue($summary['complete']);
        } finally {
            @unlink(storage_path('beacon/snapshots/baseline.json'));
            @unlink(storage_path('beacon/snapshots/candidate.json'));
        }
    }
}
