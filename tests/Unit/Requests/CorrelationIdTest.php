<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Requests;

use Beacon\Requests\CorrelationId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CorrelationIdTest extends TestCase
{
    #[Test]
    #[DataProvider('validIds')]
    public function it_accepts_valid_identifiers(string $value): void
    {
        $this->assertTrue(CorrelationId::isValid($value));
    }

    #[Test]
    #[DataProvider('invalidIds')]
    public function it_rejects_malformed_or_oversized_identifiers(string $value): void
    {
        $this->assertFalse(CorrelationId::isValid($value));
    }

    #[Test]
    public function it_generates_a_valid_identifier(): void
    {
        $value = CorrelationId::generate();

        $this->assertTrue(CorrelationId::isValid($value));
        $this->assertSame(32, strlen($value));
    }

    #[Test]
    public function it_prefers_a_valid_incoming_header(): void
    {
        $value = CorrelationId::fromHeaders([
            'Authorization' => 'authorization-header-not-stored',
            'X-Request-Id' => 'incoming-request-id-01',
        ]);

        $this->assertSame('incoming-request-id-01', $value);
    }

    #[Test]
    public function it_generates_when_headers_are_invalid(): void
    {
        $value = CorrelationId::fromHeaders([
            'X-Request-Id' => 'bad id!',
        ]);

        $this->assertTrue(CorrelationId::isValid($value));
        $this->assertNotSame('bad id!', $value);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validIds(): iterable
    {
        yield 'minimum length' => ['abcdefgh'];
        yield 'alphanumeric' => ['abcDEF0123_-'];
        yield 'max length' => [str_repeat('a', CorrelationId::MAX_LENGTH)];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'too short' => ['abcdefg'];
        yield 'too long' => [str_repeat('a', CorrelationId::MAX_LENGTH + 1)];
        yield 'spaces' => ['bad request id'];
        yield 'slash' => ['bad/request'];
        yield 'empty' => [''];
    }
}
