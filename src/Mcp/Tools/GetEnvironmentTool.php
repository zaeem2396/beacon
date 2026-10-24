<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\ConfigurationState;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Inspectors\EnvironmentInspector;
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
 * Exposes the {@see EnvironmentInspector} over MCP.
 *
 * Client arguments are never forwarded. The tool cannot be asked for an
 * arbitrary configuration key, and failures use a fixed message.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetEnvironmentTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_environment';

    public const UNAVAILABLE_MESSAGE = 'Environment inspection is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get environment inspection';

    protected string $description = <<<'MARKDOWN'
        Inspect safe environment metadata and deterministic configuration checks for this Laravel application.

        Use this tool to learn the active environment, whether debug mode is a real boolean, the
        timezone and locale, whether configuration and routes are cached, and the state of a fixed
        allowlist of settings. It also returns findings for checks that can be evaluated with
        confidence. It does not identify the application: use beacon.get_application_info for the
        name, versions, sanitized URL and maintenance mode.

        metadata.environment is Laravel's environment name. metadata.debug, timezone and locale
        are null when missing or invalid. metadata.configuration_cached and metadata.routes_cached
        are null when they cannot be determined. They are booleans only for the process serving
        this MCP connection. Over stdio that is the PHP CLI, which can differ from PHP-FPM.

        Each setting has a state of present, missing, invalid or sensitive. present carries a
        safe boolean or a short classification such as a timezone, locale, URL scheme (http or
        https) or SameSite policy. sensitive means a value exists and is withheld. app.key is
        always sensitive or missing, never the key. app.url's value is only the scheme.

        Each finding has a stable id, a status of pass, fail, risk or unknown, and a severity of
        none, low, medium or high. fail is a confirmed misconfiguration. risk is a potential
        problem with evidence, not a confirmed misconfiguration. unknown means the check could
        not be evaluated. pass and unknown use severity none. Production-only checks pass when
        the environment is not exactly "production" and still cite the observed setting.
        Remediation is null on pass and a fixed instruction otherwise. Evidence cites classified
        observations, never raw configuration values.

        Checks: debug_enabled_in_production, app_key_configured, app_url_scheme_in_production,
        session_secure_cookie_in_production, session_http_only_in_production,
        session_same_site_in_production.

        Does not return .env contents, environment variables, application keys, passwords,
        connection strings, tokens, mail or cloud credentials, session secrets, private keys,
        filesystem paths, or any configuration outside the allowlist. Takes no parameters.
        Arguments are ignored. Read-only. Requires the "environment" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Environment->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(EnvironmentInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $setting = $schema->object([
            'name' => $schema->string()->required()
                ->description('Allowlisted configuration name, such as app.debug or app.key.'),
            'state' => $schema->string()->enum($this->values(ConfigurationState::cases()))->required()
                ->description('present, missing, invalid, or sensitive. Unsafe is reported as a finding, not a state.'),
            'value' => $schema->union(['boolean', 'string'])->nullable()->required()
                ->description('Safe boolean or classification when state is present. Null when missing, invalid, or sensitive.'),
        ]);

        $evidence = $schema->object([
            'name' => $schema->string()->required()
                ->description('Allowlisted fact the check observed, such as app.debug or environment.'),
            'observation' => $schema->string()->enum($this->values(Observation::cases()))->required()
                ->description('Classification of what was observed. Never a raw configuration value.'),
        ]);

        return [
            'schema_version' => $schema->integer()->required()
                ->description('Version of this response schema. Fields may be added within a version but are never removed or redefined.'),
            'metadata' => $schema->object([
                'environment' => $schema->string()->required()
                    ->description('Active Laravel environment. Production checks run only when this is exactly "production".'),
                'debug' => $schema->boolean()->nullable()->required()
                    ->description('app.debug when it is a real boolean. Null when missing or not a boolean.'),
                'timezone' => $schema->string()->nullable()->required()
                    ->description('app.timezone when PHP recognizes it. Null when missing or invalid.'),
                'locale' => $schema->string()->nullable()->required()
                    ->description('app.locale when it is a language tag. Null when missing or invalid.'),
                'configuration_cached' => $schema->boolean()->nullable()->required()
                    ->description('Whether this process is using a cached configuration. Null when it cannot be determined.'),
                'routes_cached' => $schema->boolean()->nullable()->required()
                    ->description('Whether this process is using a cached route list. Null when it cannot be determined.'),
                'settings' => $schema->array()->items($setting)->required()
                    ->description('Allowlisted settings in a stable order. No other configuration keys are accepted or returned.'),
            ])->required()->description('Safe environment facts. This is not the application identity report.'),
            'findings' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required()
                    ->description('Stable check identifier.'),
                'status' => $schema->string()->enum($this->values(FindingStatus::cases()))->required()
                    ->description('pass, fail (confirmed misconfiguration), risk (potential problem), or unknown (not evaluated).'),
                'severity' => $schema->string()->enum($this->values(Severity::cases()))->required()
                    ->description('none, low, medium, or high. Pass and unknown are always none.'),
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'evidence' => $schema->array()->items($evidence)->required()
                    ->description('Classified observations that support the status.'),
                'remediation' => $schema->string()->nullable()->required()
                    ->description('Fixed guidance when the status is not pass. Null on pass.'),
            ]))->required()->description('Deterministic checks, in a stable order. No model is consulted.'),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, EnvironmentInspector::NAME)->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
    }

    /**
     * @template T of \BackedEnum
     *
     * @param list<T> $cases
     *
     * @return list<int|string>
     */
    private function values(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $cases);
    }
}
