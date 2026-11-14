<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Logs;

use Beacon\Logs\ExceptionCategory;
use Beacon\Logs\ExceptionClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExceptionClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{0: ?string, 1: ?string, 2: ExceptionCategory}>
     */
    public static function cases(): iterable
    {
        yield 'database' => ['Illuminate\\Database\\QueryException', 'SQLSTATE connection failed', ExceptionCategory::Database];
        yield 'timeout' => ['RuntimeException', 'Maximum execution time exceeded', ExceptionCategory::Timeout];
        yield 'memory' => [null, 'Allowed memory size exhausted', ExceptionCategory::Memory];
        yield 'auth' => ['Illuminate\\Auth\\AuthenticationException', 'Unauthenticated', ExceptionCategory::Authentication];
        yield 'validation' => ['Illuminate\\Validation\\ValidationException', 'The given data was invalid', ExceptionCategory::Validation];
        yield 'unknown' => ['App\\Support\\CustomThing', 'Something odd happened', ExceptionCategory::Unknown];
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_classifies_from_class_and_message(?string $class, ?string $message, ExceptionCategory $expected): void
    {
        $this->assertSame($expected, (new ExceptionClassifier())->classify($class, $message));
    }
}
