<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class InvestigateCommandTest extends TestCase
{
    #[Test]
    public function it_denies_execution_without_the_capability(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['requests']);

        $exit = Artisan::call('beacon:investigate', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('not_allowed', $payload['error'] ?? null);
    }

    #[Test]
    public function it_prints_json_context_when_allowed(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['investigation_context']);

        $exit = Artisan::call('beacon:investigate', [
            '--json' => true,
            '--since' => '2026-11-14T00:00:00.000+00:00',
            '--until' => '2026-11-15T00:00:00.000+00:00',
            '--max-results' => '5',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($payload);
        $this->assertSame(1, $payload['schema_version'] ?? null);
        $this->assertArrayHasKey('missing_evidence', $payload);
        $this->assertArrayHasKey('timeline', $payload);
        $this->assertArrayHasKey('leads', $payload);
    }

    #[Test]
    public function it_rejects_invalid_identifiers(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['investigation_context']);

        $exit = Artisan::call('beacon:investigate', [
            '--json' => true,
            '--request-id' => 'bad',
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('not_allowed', $payload['error'] ?? null);
    }
}
