<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Investigation;

use Beacon\Exceptions\InvalidInvestigationQueryException;
use Beacon\Investigation\InvestigationLimits;
use Beacon\Investigation\InvestigationQuery;
use Beacon\Investigation\InvestigationScope;
use Beacon\Investigation\SubjectKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class InvestigationQueryTest extends TestCase
{
    #[Test]
    public function it_rejects_unknown_parameters(): void
    {
        $this->expectException(InvalidInvestigationQueryException::class);
        InvestigationQuery::from(['sql' => 'select 1']);
    }

    #[Test]
    public function it_rejects_malformed_identifiers(): void
    {
        $this->expectException(InvalidInvestigationQueryException::class);
        InvestigationQuery::from(['request_id' => 'bad']);
    }

    #[Test]
    public function it_rejects_inverted_time_windows(): void
    {
        $this->expectException(InvalidInvestigationQueryException::class);
        InvestigationQuery::from([
            'since' => '2026-11-15T00:00:00Z',
            'until' => '2026-11-14T00:00:00Z',
        ]);
    }

    #[Test]
    public function it_prioritizes_request_id_as_primary_subject(): void
    {
        $query = InvestigationQuery::from([
            'request_id' => 'request-id-12345678',
            'fingerprint' => str_repeat('a', 32),
            'endpoint' => '/orders',
        ]);
        $scope = InvestigationScope::from($query, InvestigationLimits::defaults());

        $this->assertSame(SubjectKind::Request, $scope->subjectKind);
        $this->assertSame('request-id-12345678', $scope->query->requestId);
        $this->assertSame(str_repeat('a', 32), $scope->query->fingerprint);
    }

    #[Test]
    public function it_applies_a_default_time_window_when_scope_is_missing(): void
    {
        $scope = InvestigationScope::from(InvestigationQuery::from([]), InvestigationLimits::defaults());

        $this->assertTrue($scope->usedDefaultWindow);
        $this->assertSame(SubjectKind::TimeWindow, $scope->subjectKind);
        $this->assertNotSame('', $scope->requestedSince);
        $this->assertNotSame('', $scope->requestedUntil);
    }
}
