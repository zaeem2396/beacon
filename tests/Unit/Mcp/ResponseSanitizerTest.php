<?php

declare(strict_types=1);

namespace Beacon\Tests\Unit\Mcp;

use Beacon\Mcp\ResponseSanitizer;
use Beacon\Security\KeyPatternRedactor;
use Laravel\Mcp\Transport\JsonRpcResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('mcp')]
final class ResponseSanitizerTest extends TestCase
{
    #[Test]
    public function it_redacts_secret_values_anywhere_in_a_message(): void
    {
        $response = JsonRpcResponse::result(1, [
            'content' => [['type' => 'text', 'text' => 'Failed: Authorization: Bearer abcdefghijklmnopqrstuvwxyz012345']],
            'structuredContent' => (object) ['dsn' => 'pgsql://app:s3cr3t-pass@db/app', 'host' => 'db'],
        ]);

        $json = (new ResponseSanitizer(new KeyPatternRedactor()))->sanitize($response)->toJson();

        $this->assertStringNotContainsString('abcdefghijklmnopqrstuvwxyz012345', $json);
        $this->assertStringNotContainsString('s3cr3t-pass', $json);
        $this->assertStringContainsString('"host":"db"', $json);
    }

    #[Test]
    public function it_preserves_the_protocol_structure(): void
    {
        $response = JsonRpcResponse::result(7, [
            'capabilities' => ['tools' => ['listChanged' => false], 'logging' => (object) []],
            'protocolVersion' => '2025-06-18',
            'count' => 3,
        ]);

        $sanitized = (new ResponseSanitizer(new KeyPatternRedactor()))->sanitize($response);

        $this->assertSame(
            '{"jsonrpc":"2.0","id":7,"result":{"capabilities":{"tools":{"listChanged":false},"logging":{}},"protocolVersion":"2025-06-18","count":3}}',
            $sanitized->toJson(JSON_UNESCAPED_SLASHES),
        );
        $this->assertIsArray($sanitized->content['result']);
    }
}
