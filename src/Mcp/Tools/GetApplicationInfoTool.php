<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Inspectors\ApplicationInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Exposes the {@see ApplicationInspector} over MCP.
 *
 * Client arguments are never forwarded to the inspector, and failures are
 * reported with a fixed message so no exception detail reaches the client.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetApplicationInfoTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_application_info';

    public const UNAVAILABLE_MESSAGE = 'Application information is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get application information';

    protected string $description = <<<'MARKDOWN'
        Get safe metadata about this Laravel application and the PHP runtime serving it.

        Use this tool first when investigating the application, to learn which Laravel and PHP
        versions are running, which environment this is, whether debug mode or maintenance mode
        is on, and how the application is addressed (URL, timezone, locales).

        Returns: name, environment, debug, url, timezone, locale, fallback_locale,
        maintenance_mode, laravel_version, php_version, beacon_version and schema_version.
        The url contains only the scheme, host, port and path. Any value that is missing,
        invalid or cannot be determined is null; null means "unknown", not "off".

        Does not return configuration values, environment variables, .env contents, application
        keys, credentials, tokens, filesystem paths, server variables or request headers.
        It does not diagnose problems: use beacon.get_environment for configuration risks,
        and other Beacon tools for routes, database, queues and errors when they are available.

        php_version describes the process serving this MCP connection. Over stdio that is the
        PHP CLI, whose version and settings can differ from PHP-FPM serving web traffic.

        Takes no parameters. Read-only. Requires the "application" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Application->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(ApplicationInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'schema_version' => $schema->integer()->required()
                ->description('Version of this response schema. Fields may be added within a version but are never removed or redefined.'),
            'name' => $schema->string()->nullable()->required()
                ->description('Application name (app.name). Null when missing or invalid.'),
            'environment' => $schema->string()->required()
                ->description('Application environment as reported by Laravel, e.g. "production".'),
            'debug' => $schema->boolean()->nullable()->required()
                ->description('Whether debug mode (app.debug) is enabled. Null when it is not configured as a boolean.'),
            'url' => $schema->string()->nullable()->required()
                ->description('Application URL (app.url) reduced to scheme, host, port and path. Null when missing or not an absolute http(s) URL.'),
            'timezone' => $schema->string()->nullable()->required()
                ->description('Application timezone (app.timezone). Null when missing or invalid.'),
            'locale' => $schema->string()->nullable()->required()
                ->description('Default locale (app.locale). Null when missing or invalid.'),
            'fallback_locale' => $schema->string()->nullable()->required()
                ->description('Fallback locale (app.fallback_locale). Null when missing or invalid.'),
            'maintenance_mode' => $schema->boolean()->nullable()->required()
                ->description('Whether the application is down for maintenance. Null when the maintenance-mode driver cannot be read.'),
            'laravel_version' => $schema->string()->required()
                ->description('Installed Laravel framework version.'),
            'php_version' => $schema->string()->required()
                ->description('PHP version of the process serving this MCP connection.'),
            'beacon_version' => $schema->string()->nullable()->required()
                ->description('Installed Beacon version from Composer metadata. Null when it cannot be determined.'),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, ApplicationInspector::NAME)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
    }
}
