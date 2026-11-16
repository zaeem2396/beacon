<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Inspectors;

use Beacon\Data\InvestigationContext;
use Beacon\Inspectors\InvestigationContextInspector;
use Beacon\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Optional investigation-context checks against CommerceOps when configured.
 *
 * Does not mutate CommerceOps business data. Asserts honest missing-history
 * reporting and that investigation context remains available when optional
 * sources are limited.
 */
#[Group('commerceops')]
final class CommerceOpsInvestigationTest extends TestCase
{
    #[Test]
    public function commerceops_investigation_context_reports_missing_history_honestly(): void
    {
        $host = $this->env('BEACON_COMMERCEOPS_HOST');
        $root = $this->env('BEACON_COMMERCEOPS_PATH');

        if ($host === null || $root === null || ! is_dir($root)) {
            $this->markTestSkipped('CommerceOps is not available for investigation context.');
        }

        $previous = $this->application()->basePath();

        try {
            $this->application()->setBasePath($root);
            config()->set('beacon.security.allowed_capabilities', ['investigation_context']);

            $context = $this->application()->make(InvestigationContextInspector::class)->inspect([
                'window_minutes' => 60,
                'max_results' => 10,
            ]);

            $this->assertInstanceOf(InvestigationContext::class, $context);
            $this->assertSame(1, $context->schemaVersion);

            $codes = array_map(static fn ($note): string => $note->code, $context->missingEvidence);
            $this->assertContains('deployment_history_unavailable', $codes);
            $this->assertContains('configuration_change_history_unavailable', $codes);

            foreach ($context->leads as $lead) {
                $this->assertStringNotContainsString('root cause', strtolower($lead->explanation));
            }

            $payload = $context->toArray();
            $encoded = (string) json_encode($payload, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('Authorization', $encoded);
            $this->assertStringNotContainsString('"payload"', $encoded);
        } finally {
            $this->application()->setBasePath($previous);
        }
    }

    private function env(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
