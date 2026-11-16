<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Investigation;

use Beacon\Data\InvestigationEvidenceItem;
use Beacon\Investigation\CorrelationType;
use Beacon\Investigation\TimelineBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimelineBuilderTest extends TestCase
{
    #[Test]
    public function it_orders_events_chronologically_and_deduplicates(): void
    {
        $builder = new TimelineBuilder();
        $items = [
            new InvestigationEvidenceItem('b', 'exceptions', 'exception', '2026-11-14T12:00:02.000+00:00', 'Exception RuntimeException was observed.', CorrelationType::Direct, 'Exact exception id match.', []),
            new InvestigationEvidenceItem('a', 'requests', 'request', '2026-11-14T12:00:01.000+00:00', 'HTTP request was recorded for orders.', CorrelationType::Direct, 'Exact request id match.', []),
            new InvestigationEvidenceItem('a', 'requests', 'request', '2026-11-14T12:00:01.000+00:00', 'HTTP request was recorded for orders.', CorrelationType::Direct, 'Exact request id match.', []),
            new InvestigationEvidenceItem('c', 'runtime', 'runtime', null, 'Current runtime facts were collected for general context.', CorrelationType::Uncorrelated, 'General application context without a reliable association.', []),
        ];

        $result = $builder->build($items, 10);

        $this->assertCount(3, $result['events']);
        $this->assertSame('evt_a', $result['events'][0]->eventId);
        $this->assertSame('evt_b', $result['events'][1]->eventId);
        $this->assertSame('evt_c', $result['events'][2]->eventId);
        $this->assertFalse($result['truncated']);
    }

    #[Test]
    public function it_reports_timeline_truncation(): void
    {
        $builder = new TimelineBuilder();
        $items = [];

        for ($i = 0; $i < 5; $i++) {
            $items[] = new InvestigationEvidenceItem(
                'e' . $i,
                'logs',
                'log',
                sprintf('2026-11-14T12:00:0%d.000+00:00', $i),
                'Log error observation was collected.',
                CorrelationType::Inferred,
                'Temporal proximity within the investigation window.',
                [],
            );
        }

        $result = $builder->build($items, 2);

        $this->assertCount(2, $result['events']);
        $this->assertTrue($result['truncated']);
    }
}
