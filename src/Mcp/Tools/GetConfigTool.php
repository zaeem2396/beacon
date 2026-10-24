<?php

declare(strict_types=1);

namespace Beacon\Mcp\Tools;

use Beacon\Contracts\RequiresCapability;
use Beacon\Core\Beacon;
use Beacon\Core\InspectorRegistry;
use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationCategory;
use Beacon\Data\ConfigurationState;
use Beacon\Data\FindingStatus;
use Beacon\Data\Observation;
use Beacon\Data\Severity;
use Beacon\Exceptions\AuthorizationException;
use Beacon\Exceptions\InvalidConfigurationQueryException;
use Beacon\Inspectors\Configuration\ConfigurationQuery;
use Beacon\Inspectors\ConfigurationInspector;
use Beacon\Mcp\ExecutionGate;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;
use Beacon\Security\Principal;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Exposes the {@see ConfigurationInspector} over MCP.
 *
 * Arguments select an approved category or setting. The tool does not accept
 * configuration paths, and failures use a fixed message that does not repeat
 * the rejected input.
 */
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetConfigTool extends Tool implements RequiresCapability
{
    public const NAME = 'beacon.get_config';

    public const UNAVAILABLE_MESSAGE = 'Configuration inspection is currently unavailable.';

    protected string $name = self::NAME;

    protected string $title = 'Get configuration inspection';

    protected string $description = <<<'MARKDOWN'
        Inspect approved Laravel configuration metadata and deterministic findings for this application.

        Returns one page of settings and findings for the full approved set, not only the current page
        or category. Each setting has category, id, state and value. state is present, missing, invalid
        or sensitive. value is a boolean or a short classification when state is present, and null
        otherwise. Null means the value is missing, invalid, or withheld. It does not mean the value
        was guessed, and it is not a masked copy of a secret.

        Categories: application, database, cache, queue, session, mail, filesystem and logging.
        Filters are optional. category is one of those categories. id is one approved setting identifier,
        such as database.driver or session.secure. page defaults to 1 and must be an integer from 1 to
        10000. limit defaults to 50 and must be an integer from 1 to 100. Values above the maximum are
        rejected. There is no configuration-path, wildcard, or regular-expression mode. Nested paths
        such as database.connections.mysql.password are rejected.

        Checks, in order: app_cipher, app_maintenance_driver, database_connection, cache_store,
        queue_connection, mail_mailer, filesystem_disk, logging_channel, session_driver,
        session_secure_cookie. pass, fail, risk and unknown are the statuses. fail is a confirmed
        misconfiguration. risk is a potential issue supported by evidence. unknown means the check
        could not be evaluated. pass and unknown use severity none. session_secure_cookie fails only
        when the environment is exactly production and the application URL scheme is https.

        Does not return passwords, application keys, tokens, connection strings, private keys, mail
        credentials, webhook URLs, filesystem paths, hosts, or arbitrary configuration. Does not read
        .env files or the published namespace list as a dump switch. Does not connect to databases,
        caches, queues, mail servers, or other networks, and does not change configuration. Read-only.
        Requires the "config" capability.
        MARKDOWN;

    public function capability(): Capability
    {
        return InspectionCapability::Config->capability();
    }

    public function shouldRegister(InspectorRegistry $inspectors): bool
    {
        return $inspectors->enabled(ConfigurationInspector::NAME);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->enum($this->values(ConfigurationCategory::cases()))
                ->description('Approved category. Compared case-insensitively. Not a Laravel configuration path.'),
            'id' => $schema->string()->enum(ConfigurationCatalog::IDS)->min(1)->max(64)
                ->description('Approved setting identifier, such as database.driver. Not a nested configuration path.'),
            'page' => $schema->integer()->min(1)->max(ConfigurationQuery::MAX_PAGE)
                ->description('Page number. Defaults to 1.'),
            'limit' => $schema->integer()->min(1)->max(ConfigurationQuery::MAX_LIMIT)
                ->description('Page size. Defaults to 50. Larger values are rejected.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        $evidence = $schema->object([
            'name' => $schema->string()->required()
                ->description('Fact the check observed, such as database.driver or session.secure.'),
            'observation' => $schema->string()->enum($this->values(Observation::cases()))->required()
                ->description('Classification of what was observed. Never a raw configuration value.'),
        ]);

        return [
            'schema_version' => $schema->integer()->required()
                ->description('Version of this response schema. Fields may be added within a version but are never removed or redefined.'),
            'pagination' => $schema->object([
                'page' => $schema->integer()->required()->description('Requested page, starting at 1.'),
                'per_page' => $schema->integer()->required()->description('Requested page size after validation.'),
                'total' => $schema->integer()->required()->description('Approved settings that matched the filter, before this page was sliced.'),
                'pages' => $schema->integer()->required()->description('Number of pages. Zero when nothing matched.'),
                'count' => $schema->integer()->required()->description('Settings returned in this page.'),
            ])->required()->description('Pagination for the settings. Findings are not paginated.'),
            'settings' => $schema->array()->items($schema->object([
                'category' => $schema->string()->enum($this->values(ConfigurationCategory::cases()))->required()
                    ->description('Approved category.'),
                'id' => $schema->string()->enum(ConfigurationCatalog::IDS)->required()
                    ->description('Approved setting identifier.'),
                'state' => $schema->string()->enum($this->values(ConfigurationState::cases()))->required()
                    ->description('present, missing, invalid, or sensitive.'),
                'value' => $schema->union(['boolean', 'string'])->nullable()->required()
                    ->description('Safe boolean or classification when state is present. Null when missing, invalid, or sensitive.'),
            ]))->required()->description('The requested page of approved settings, in a stable order.'),
            'findings' => $schema->array()->items($schema->object([
                'id' => $schema->string()->required()->description('Stable check identifier.'),
                'status' => $schema->string()->enum($this->values(FindingStatus::cases()))->required()
                    ->description('pass, fail, risk, or unknown.'),
                'severity' => $schema->string()->enum($this->values(Severity::cases()))->required()
                    ->description('none, low, medium, or high. Pass and unknown are always none.'),
                'title' => $schema->string()->required(),
                'description' => $schema->string()->required(),
                'evidence' => $schema->array()->items($evidence)->required()
                    ->description('Classified observations that support the status.'),
                'remediation' => $schema->string()->nullable()->required()
                    ->description('Fixed guidance when the status is not pass. Null on pass.'),
            ]))->required()->description('Deterministic checks over the full approved set, in a stable order.'),
        ];
    }

    public function handle(Beacon $beacon, Principal $caller, Request $request): Response|ResponseFactory
    {
        try {
            return Response::structured($beacon->inspect($caller, ConfigurationInspector::NAME, $this->parameters($request))->toArray());
        } catch (AuthorizationException) {
            return Response::error(ExecutionGate::DENIED_MESSAGE);
        } catch (InvalidConfigurationQueryException $exception) {
            return Response::error($exception->getMessage());
        } catch (\Throwable) {
            return Response::error(self::UNAVAILABLE_MESSAGE);
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function parameters(Request $request): array
    {
        $parameters = [];

        foreach ($request->all() as $key => $value) {
            if (! is_string($key) || ! in_array($key, ConfigurationQuery::PARAMETERS, true)) {
                throw InvalidConfigurationQueryException::unknownParameter();
            }

            if ($value !== null && ! is_scalar($value)) {
                throw InvalidConfigurationQueryException::type($key);
            }

            $parameters[$key] = $value;
        }

        return $parameters;
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
