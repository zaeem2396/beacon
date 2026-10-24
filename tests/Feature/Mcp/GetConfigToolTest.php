<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Mcp;

use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationInspection;
use Beacon\Data\ConfigurationSetting;
use Beacon\Events\AuthorizationEvaluated;
use Beacon\Inspectors\ConfigurationInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Mcp\Tools\GetConfigTool;
use Beacon\Security\Principal;
use Beacon\Tests\Fixtures\FailingConfigurationInspector;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response as McpResponse;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;

/**
 * The configuration tool through the real, authenticated MCP HTTP lifecycle.
 */
#[Group('mcp')]
final class GetConfigToolTest extends McpTestCase
{
    private const URL = 'https://localhost/beacon/mcp';

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function it_is_listed_as_a_read_only_tool_with_documented_filters_and_output(): void
    {
        $tools = (array) $this->rpc('tools/list')->assertOk()->json('result.tools');
        $tool = collect($tools)->firstWhere('name', 'beacon.get_config');

        $this->assertIsArray($tool);
        $this->assertSame('beacon.get_config', $tool['name']);
        $this->assertSame('Get configuration inspection', $tool['title']);

        $annotations = $tool['annotations'];
        $this->assertIsArray($annotations);
        $this->assertSame(
            ['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            array_diff_key($annotations, ['title' => true]),
        );

        if (array_key_exists('title', $annotations)) {
            $this->assertSame('Get configuration inspection', $annotations['title']);
        }

        $properties = (array) data_get($tool, 'inputSchema.properties');
        $this->assertSame(['category', 'id', 'page', 'limit'], array_keys($properties));
        $limitDescription = data_get($properties, 'limit.description');
        $this->assertIsString($limitDescription);
        $this->assertStringContainsString('Defaults to 50', $limitDescription);
        $this->assertSame(1, data_get($properties, 'page.minimum'));
        $this->assertSame(10000, data_get($properties, 'page.maximum'));
        $this->assertSame(1, data_get($properties, 'limit.minimum'));
        $this->assertSame(100, data_get($properties, 'limit.maximum'));
        $this->assertSame(
            ['application', 'database', 'cache', 'queue', 'session', 'mail', 'filesystem', 'logging'],
            data_get($properties, 'category.enum'),
        );
        $this->assertContains('database.driver', (array) data_get($properties, 'id.enum'));
        $this->assertNotContains('database.connections.mysql.password', (array) data_get($properties, 'id.enum'));

        $this->assertSame(
            ['schema_version', 'pagination', 'settings', 'findings'],
            array_keys((array) data_get($tool, 'outputSchema.properties')),
        );
        $this->assertSame(['schema_version', 'pagination', 'settings', 'findings'], data_get($tool, 'outputSchema.required'));
        $this->assertSame(
            ['category', 'id', 'state', 'value'],
            array_keys((array) data_get($tool, 'outputSchema.properties.settings.items.properties')),
        );

        $description = $tool['description'];
        $this->assertIsString($description);
        $description = preg_replace('/\s+/', ' ', $description);
        $this->assertIsString($description);

        foreach ([
            'app_cipher',
            'database_connection',
            'cache_store',
            'queue_connection',
            'mail_mailer',
            'filesystem_disk',
            'logging_channel',
            'session_driver',
            'session_secure_cookie',
            'full approved set',
            'Does not connect',
            '"config" capability',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $description);
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function an_authorized_client_receives_safe_configuration_metadata(): void
    {
        config()->set('app.key', 'application-key-not-in-output');
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.password', 'database-password-not-in-output');
        config()->set('database.connections.mysql.url', 'mysql://beacon:connection-secret-not-in-output@db.internal/app');
        config()->set('mail.mailers.smtp.password', 'mail-password-not-in-output');
        config()->set('services.stripe.secret', 'stripe-secret-not-in-output');

        $response = $this->callTool()->assertOk();
        $response->assertJsonPath('result.isError', false);

        $structured = (array) $response->json('result.structuredContent');

        $this->assertSame($this->expected(), $structured);

        $body = (string) $response->getContent();

        foreach ([
            'application-key-not-in-output',
            'database-password-not-in-output',
            'connection-secret-not-in-output',
            'mail-password-not-in-output',
            'stripe-secret-not-in-output',
            '[REDACTED]',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $this->assertNull(data_get($structured, 'settings.2.value'));
        $this->assertSame('app.key', data_get($structured, 'settings.2.id'));
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function filters_select_an_approved_setting_while_findings_cover_the_catalog(): void
    {
        config()->set('app.cipher', 'not-a-cipher');

        $response = $this->callTool(['category' => 'session', 'limit' => 1])->assertOk();
        $structured = (array) $response->json('result.structuredContent');

        $this->assertSame('session', data_get($structured, 'settings.0.category'));
        $this->assertSame('session.driver', data_get($structured, 'settings.0.id'));
        $this->assertSame(1, data_get($structured, 'pagination.count'));
        $this->assertGreaterThan(1, data_get($structured, 'pagination.total'));
        $this->assertSame('fail', data_get($structured, 'findings.0.status'));
        $this->assertSame('app_cipher', data_get($structured, 'findings.0.id'));
        $this->assertStringNotContainsString('not-a-cipher', (string) $response->getContent());
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function invalid_filters_return_a_fixed_message_without_the_rejected_value(): void
    {
        $logged = $this->captureLog();

        $response = $this->callTool([
            'category' => 'services',
            'id' => 'database.connections.mysql.password',
            'path' => 'app.key',
            'limit' => 101,
        ])->assertOk();

        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath(
            'result.content.0.text',
            'Unknown configuration filter. Allowed filters are category, id, page and limit.',
        );
        $response->assertJsonMissingPath('result.structuredContent');

        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('services', $body);
        $this->assertStringNotContainsString('database.connections.mysql.password', $body);
        $this->assertStringNotContainsString('app.key', $body);
        $this->assertStringNotContainsString('101', $body);
        $this->assertStringNotContainsString('InvalidConfigurationQueryException', $logged());
        $this->assertStringNotContainsString('GetConfigTool.php', $logged());
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_non_scalar_filter_is_rejected_by_name(): void
    {
        $response = $this->callTool(['id' => ['database.password']])->assertOk();

        $response->assertJsonPath('result.isError', true);
        $response->assertJsonPath('result.content.0.text', 'The id filter has an unsupported type.');
        $this->assertStringNotContainsString('database.password', (string) $response->getContent());
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutConfigCapability')]
    public function the_execution_gate_rejects_a_caller_without_the_config_capability(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $response = $this->callTool();

        $response->assertJsonPath('error.code', ExecutionGate::PERMISSION_DENIED);
        $response->assertJsonPath('error.message', ExecutionGate::DENIED_MESSAGE);
        $response->assertJsonMissingPath('result');

        Event::assertDispatched(AuthorizationEvaluated::class, static fn (AuthorizationEvaluated $event): bool => ! $event->allowed
            && $event->capability === 'config:read');
    }

    #[Test]
    #[DefineEnvironment('enableHttpWithoutConfigCapability')]
    public function the_tool_enforces_its_capability_even_if_the_execution_gate_is_bypassed(): void
    {
        $response = $this->invokeDirectly(new Principal('beacon-token:current', 'bearer-token'));

        $this->assertTrue($response->isError());
        $this->assertSame(ExecutionGate::DENIED_MESSAGE, (string) $response->content());
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_successful_call_is_authorized_twice(): void
    {
        Event::fake([AuthorizationEvaluated::class]);

        $this->callTool()->assertOk()->assertJsonPath('result.isError', false);

        Event::assertDispatchedTimes(AuthorizationEvaluated::class, 2);
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function unauthenticated_clients_are_rejected_before_the_tool_runs(): void
    {
        $this->postJson(self::URL, $this->message())->assertStatus(401)->assertJsonMissingPath('result');
        $this->postJson(self::URL, $this->message(), ['Authorization' => 'Bearer ' . str_repeat('b', 32)])
            ->assertStatus(401)
            ->assertJsonMissingPath('result');
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function inspection_failures_return_a_generic_error_without_internal_details(): void
    {
        $logged = $this->captureLog();
        $this->application()->make(InspectorRegistry::class)->register(ConfigurationInspector::NAME, FailingConfigurationInspector::class);

        foreach ([true, false] as $debug) {
            config()->set('app.debug', $debug);

            $response = $this->callTool()->assertOk();

            $response->assertJsonPath('result.isError', true);
            $response->assertJsonPath('result.content.0.text', GetConfigTool::UNAVAILABLE_MESSAGE);
            $response->assertJsonMissingPath('result.structuredContent');

            $body = (string) $response->getContent();

            foreach ([...FailingConfigurationInspector::DETAILS, 'configuration-inspector-secret', 'db.internal', 'RuntimeException', 'FailingConfigurationInspector'] as $detail) {
                $this->assertStringNotContainsString($detail, $body);
                $this->assertStringNotContainsString($detail, $logged());
            }
        }
    }

    #[Test]
    #[DefineEnvironment('enableHttp')]
    public function a_disabled_inspector_removes_only_this_tool(): void
    {
        config()->set('beacon.inspectors.config.enabled', false);

        $names = array_column((array) $this->rpc('tools/list')->assertOk()->json('result.tools'), 'name');

        $this->assertNotContains('beacon.get_config', $names);
        $this->assertContains('beacon.get_application_info', $names);
        $this->assertContains('beacon.get_environment', $names);
        $this->assertContains('beacon.get_routes', $names);
        $this->callTool()->assertJsonMissingPath('result.structuredContent');
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttp($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['config']);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function enableHttpWithoutConfigCapability($app): void
    {
        parent::enableHttp($app);
        $app['config']->set('beacon.security.allowed_capabilities', ['application']);
    }

    /**
     * @return \Closure(): string
     */
    private function captureLog(): \Closure
    {
        $logged = '';

        Log::listen(function (MessageLogged $message) use (&$logged): void {
            $logged .= $message->message . ' ' . json_encode($message->context);
        });

        return static function () use (&$logged): string {
            return $logged;
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function expected(): array
    {
        $inspection = $this->application()->make(ConfigurationInspector::class)->inspect();
        $this->assertInstanceOf(ConfigurationInspection::class, $inspection);
        $ids = array_map(static fn (ConfigurationSetting $setting): string => $setting->id, $inspection->settings);
        $this->assertSame(ConfigurationCatalog::IDS, $ids);

        return $inspection->toArray();
    }

    private function invokeDirectly(Principal $principal): McpResponse
    {
        $response = (new GetConfigTool())->handle(
            $this->application()->make(Beacon::class),
            $principal,
            new Request([]),
        );
        $this->assertInstanceOf(McpResponse::class, $response);

        return $response;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return TestResponse<Response>
     */
    private function callTool(array $arguments = []): TestResponse
    {
        return $this->postJson(self::URL, $this->message('tools/call', [
            'name' => 'beacon.get_config',
            'arguments' => $arguments,
        ]), ['Authorization' => 'Bearer ' . self::TOKEN]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return TestResponse<Response>
     */
    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson(self::URL, $this->message($method, $params), ['Authorization' => 'Bearer ' . self::TOKEN]);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function message(string $method = 'tools/call', array $params = ['name' => 'beacon.get_config', 'arguments' => []]): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => (object) $params,
        ];
    }
}
