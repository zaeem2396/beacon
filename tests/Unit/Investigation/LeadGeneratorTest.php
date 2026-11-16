<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Investigation;

use Beacon\Data\InvestigationEvidenceItem;
use Beacon\Data\InvestigationMissingEvidence;
use Beacon\Data\InvestigationSubject;
use Beacon\Investigation\CorrelationType;
use Beacon\Investigation\LeadGenerator;
use Beacon\Investigation\SubjectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LeadGeneratorTest extends TestCase
{
    #[Test]
    public function it_emits_repeated_fingerprint_and_unavailable_history_leads(): void
    {
        $generator = new LeadGenerator();
        $subject = new InvestigationSubject(
            SubjectKind::Fingerprint,
            null,
            null,
            null,
            str_repeat('b', 32),
            null,
            'Primary subject is a recurring exception fingerprint.',
        );
        $items = [
            new InvestigationEvidenceItem(
                'agg_1',
                'error_aggregate',
                'error_pattern',
                '2026-11-14T12:00:00.000+00:00',
                'Exception pattern RuntimeException occurred 3 times.',
                CorrelationType::Direct,
                'Exact exception fingerprint match.',
                ['fingerprint' => str_repeat('b', 32), 'occurrences' => 3],
            ),
        ];
        $missing = [
            new InvestigationMissingEvidence('deployment_history_unavailable', 'deployment', 'Deployment history is not configured.'),
            new InvestigationMissingEvidence('configuration_change_history_unavailable', 'configuration', 'Configuration change history is not available.'),
        ];

        $leads = $generator->generate($subject, $items, [], $missing, 10);
        $ruleIds = array_map(static fn ($lead): string => $lead->ruleId, $leads);

        $this->assertContains(LeadGenerator::RULE_REPEATED_FINGERPRINT, $ruleIds);
        $this->assertContains(LeadGenerator::RULE_DEPLOYMENT_UNAVAILABLE, $ruleIds);
        $this->assertContains(LeadGenerator::RULE_CONFIG_CHANGE_UNAVAILABLE, $ruleIds);

        foreach ($leads as $lead) {
            $this->assertStringNotContainsString('root cause', strtolower($lead->explanation));
            $this->assertStringNotContainsString('caused', strtolower($lead->explanation));
        }
    }
}
