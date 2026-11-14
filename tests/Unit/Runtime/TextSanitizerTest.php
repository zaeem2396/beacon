<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Runtime;

use Beacon\Runtime\TextSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextSanitizerTest extends TestCase
{
    #[Test]
    public function it_redacts_secret_shaped_fragments_and_emails(): void
    {
        $secret = 'sk_live_51BeaconSyntheticSecretValue';
        $email = 'ops@example.com';
        $input = 'Failed for ' . $email . ' with token=' . $secret . ' and Bearer abcdefghijklmnop';

        $sanitized = TextSanitizer::sanitize($input);

        $this->assertIsString($sanitized);
        $this->assertStringNotContainsString($secret, $sanitized);
        $this->assertStringNotContainsString($email, $sanitized);
        $this->assertStringNotContainsString('Bearer abcdefghijklmnop', $sanitized);
        $this->assertStringContainsString('redacted', $sanitized);
    }

    #[Test]
    public function it_normalizes_variable_fragments_for_fingerprints(): void
    {
        $left = TextSanitizer::normalizeForFingerprint('Order 12345 failed for user 550e8400-e29b-41d4-a716-446655440000');
        $right = TextSanitizer::normalizeForFingerprint('Order 99999 failed for user 11111111-2222-3333-4444-555555555555');

        $this->assertSame($left, $right);
        $this->assertStringContainsString('{n}', $left);
        $this->assertStringContainsString('{id}', $left);
    }
}
