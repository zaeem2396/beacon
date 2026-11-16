<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Investigation;

use Beacon\Data\InvestigationEvidenceItem;
use Beacon\Data\InvestigationSubject;
use Beacon\Investigation\CorrelationEngine;
use Beacon\Investigation\CorrelationType;
use Beacon\Investigation\SubjectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CorrelationEngineTest extends TestCase
{
    #[Test]
    public function exact_request_id_is_direct(): void
    {
        $engine = new CorrelationEngine();
        $subject = new InvestigationSubject(
            SubjectKind::Request,
            'request-id-12345678',
            'request-id-12345678',
            null,
            null,
            null,
            'Primary subject is a recorded HTTP request.',
        );
        $item = new InvestigationEvidenceItem(
            'req_1',
            'requests',
            'request',
            '2026-11-14T12:00:00.000+00:00',
            'HTTP request was recorded for orders.',
            CorrelationType::Uncorrelated,
            'Pending correlation.',
            ['request_id' => 'request-id-12345678'],
        );

        $result = $engine->correlate($subject, [$item]);

        $this->assertSame(CorrelationType::Direct, $result['items'][0]->correlation);
        $this->assertSame('Exact request id match.', $result['items'][0]->correlationReason);
    }

    #[Test]
    public function temporal_proximity_is_never_direct(): void
    {
        $engine = new CorrelationEngine();
        $subject = new InvestigationSubject(
            SubjectKind::Request,
            'request-id-12345678',
            null,
            null,
            null,
            null,
            'Primary subject is a recorded HTTP request.',
        );
        $item = new InvestigationEvidenceItem(
            'log_1',
            'logs',
            'log',
            '2026-11-14T12:00:01.000+00:00',
            'Log error observation was collected.',
            CorrelationType::Uncorrelated,
            'Pending correlation.',
            ['level' => 'error'],
        );

        $result = $engine->correlate($subject, [$item]);

        $this->assertSame(CorrelationType::Inferred, $result['items'][0]->correlation);
        $this->assertSame('Temporal proximity within the investigation window.', $result['items'][0]->correlationReason);
    }

    #[Test]
    public function shared_exception_class_is_inferred_not_direct(): void
    {
        $engine = new CorrelationEngine();
        $subject = new InvestigationSubject(
            SubjectKind::TimeWindow,
            null,
            null,
            null,
            null,
            null,
            'Primary subject is recent application activity in a bounded time window.',
        );
        $job = new InvestigationEvidenceItem(
            'job_1',
            'failed_jobs',
            'failed_job',
            '2026-11-14T12:00:00.000+00:00',
            'Failed job was recorded without payload contents.',
            CorrelationType::Uncorrelated,
            'Pending correlation.',
            ['exception_class' => 'RuntimeException'],
        );
        $exception = new InvestigationEvidenceItem(
            'exc_1',
            'exceptions',
            'exception',
            '2026-11-14T12:00:01.000+00:00',
            'Exception RuntimeException was observed.',
            CorrelationType::Uncorrelated,
            'Pending correlation.',
            ['exception_class' => 'RuntimeException', 'exception_id' => 'abcd1234abcd1234'],
        );

        $result = $engine->correlate($subject, [$job, $exception]);

        $this->assertCount(1, $result['correlations']);
        $this->assertSame(CorrelationType::Inferred, $result['correlations'][0]->type);
        $this->assertSame('shared_exception_class', $result['correlations'][0]->method);
    }

    #[Test]
    public function duplicate_relationships_are_not_emitted(): void
    {
        $engine = new CorrelationEngine();
        $subject = new InvestigationSubject(
            SubjectKind::Correlation,
            null,
            'corr-id-1234567890',
            null,
            null,
            null,
            'Primary subject is a correlation id across observations.',
        );
        $left = new InvestigationEvidenceItem(
            'req_1',
            'requests',
            'request',
            '2026-11-14T12:00:00.000+00:00',
            'HTTP request was recorded for orders.',
            CorrelationType::Uncorrelated,
            'Pending correlation.',
            ['request_id' => 'req-1', 'correlation_id' => 'corr-id-1234567890'],
        );
        $right = new InvestigationEvidenceItem(
            'req_2',
            'requests',
            'request',
            '2026-11-14T12:00:01.000+00:00',
            'HTTP request was recorded for orders.',
            CorrelationType::Uncorrelated,
            'Pending correlation.',
            ['request_id' => 'req-2', 'correlation_id' => 'corr-id-1234567890'],
        );

        $result = $engine->correlate($subject, [$left, $right]);

        $this->assertCount(1, $result['correlations']);
        $this->assertSame('exact_correlation_id', $result['correlations'][0]->method);
    }
}
