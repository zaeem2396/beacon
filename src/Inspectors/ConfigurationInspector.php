<?php

declare(strict_types=1);

namespace Beacon\Inspectors;

use Beacon\Contracts\Data;
use Beacon\Contracts\Inspector;
use Beacon\Data\ConfigurationCatalog;
use Beacon\Data\ConfigurationInspection;
use Beacon\Data\ConfigurationPage;
use Beacon\Data\ConfigurationSetting;
use Beacon\Inspectors\Configuration\ConfigurationAnalysis;
use Beacon\Inspectors\Configuration\ConfigurationAnalyzer;
use Beacon\Inspectors\Configuration\ConfigurationQuery;
use Beacon\Inspectors\Configuration\ConfigurationReader;
use Beacon\Security\Capability;
use Beacon\Security\InspectionCapability;

/**
 * Collects allowlisted configuration settings and evaluates them with {@see ConfigurationAnalyzer}.
 *
 * The reader uses Laravel's effective configuration repository. It does not
 * accept arbitrary configuration paths, open the .env file, connect to a
 * service, or mutate configuration. Findings describe the full allowlist,
 * not only the page returned to the caller.
 *
 * @implements Inspector<ConfigurationInspection>
 */
final class ConfigurationInspector implements Inspector
{
    public const NAME = 'config';

    public function __construct(
        private readonly ConfigurationReader $reader,
        private readonly ConfigurationAnalyzer $analyzer,
    ) {
    }

    public function capability(): Capability
    {
        return InspectionCapability::Config->capability();
    }

    public function inspect(array $parameters = []): Data
    {
        $query = ConfigurationQuery::from($parameters);
        $catalog = $this->reader->read();
        $analysis = $this->analyzer->analyze($catalog);

        if (! $analysis instanceof ConfigurationAnalysis) {
            throw new \RuntimeException('Configuration analysis did not return a configuration analysis.');
        }

        $page = $this->page($catalog, $query);

        return new ConfigurationInspection(
            new ConfigurationPage($query->page, $query->limit, count($page['matched']), $page['pages'], count($page['settings'])),
            $page['settings'],
            $analysis->findings,
        );
    }

    /**
     * @return array{matched: list<ConfigurationSetting>, settings: list<ConfigurationSetting>, pages: int}
     */
    private function page(ConfigurationCatalog $catalog, ConfigurationQuery $query): array
    {
        $matched = [];

        foreach ($catalog->settings as $setting) {
            if ($query->matches($setting)) {
                $matched[] = $setting;
            }
        }

        $total = count($matched);
        $pages = $total === 0 ? 0 : (int) ceil($total / $query->limit);
        $settings = array_values(array_slice($matched, ($query->page - 1) * $query->limit, $query->limit));

        return ['matched' => $matched, 'settings' => $settings, 'pages' => $pages];
    }
}
