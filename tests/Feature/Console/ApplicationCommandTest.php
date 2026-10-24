<?php

declare(strict_types=1);

namespace Beacon\Tests\Feature\Console;

use Beacon\Console\Commands\ApplicationCommand;
use Beacon\Core\InspectorRegistry;
use Beacon\Core\Version;
use Beacon\Inspectors\ApplicationInspector;
use Beacon\Tests\Fixtures\FailingApplicationInspector;
use Beacon\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

final class ApplicationCommandTest extends TestCase
{
    private const SECRET_URL = 'mysql://beacon:cli-secret@db.internal/app';

    private const SECRET_PASSWORD = 'cli-db-password-never-printed';

    #[Test]
    public function it_is_denied_until_the_application_capability_is_allowed(): void
    {
        config()->set('app.name', 'Checkout');

        $exitCode = Artisan::call('beacon:application');
        $output = Artisan::output();

        $this->assertSame(ApplicationCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('not allowed', $output);
        $this->assertStringContainsString('BEACON_ALLOWED_CAPABILITIES=application', $output);
        $this->assertStringNotContainsString('Checkout', $output);
    }

    #[Test]
    public function it_prints_the_application_information(): void
    {
        $this->allow();
        config()->set('app.name', 'Checkout');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://shop.example.com');
        config()->set('app.timezone', 'UTC');
        config()->set('app.locale', 'en');
        config()->set('app.fallback_locale', 'nl');

        $this->assertSame(0, Artisan::call('beacon:application'));
        $output = Artisan::output();

        $this->assertStringContainsString('Checkout', $output);
        $this->assertStringContainsString('testing', $output);
        $this->assertStringContainsString('off', $output);
        $this->assertStringContainsString('https://shop.example.com', $output);
        $this->assertStringContainsString('UTC', $output);
        $this->assertStringContainsString('nl', $output);
        $this->assertStringContainsString($this->application()->version(), $output);
        $this->assertStringContainsString(PHP_VERSION, $output);
        $this->assertStringContainsString(Version::current(), $output);
    }

    #[Test]
    public function json_output_matches_the_inspector_schema(): void
    {
        $this->allow();
        config()->set('app.name', 'Checkout');
        config()->set('app.url', 'https://deploy:hunter2@shop.example.com/store/?signature=abc');

        $exitCode = Artisan::call('beacon:application', ['--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame(
            $this->application()->make(ApplicationInspector::class)->inspect()->toArray(),
            $decoded,
        );
        $this->assertSame('https://shop.example.com/store', $decoded['url']);
        $this->assertStringNotContainsString('hunter2', $output);
        $this->assertDoesNotMatchRegularExpression('/\e/', $output);
    }

    #[Test]
    public function unavailable_values_are_shown_as_unknown_and_null_in_json(): void
    {
        $this->allow();
        config()->set('app.name', '');
        config()->set('app.debug', 'false');
        config()->set('app.url', 'not a url');
        config()->set('app.locale', null);

        $this->assertSame(0, Artisan::call('beacon:application'));
        $human = Artisan::output();

        $this->assertStringContainsString('unknown', $human);
        $this->assertStringNotContainsString('not a url', $human);

        Artisan::call('beacon:application', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertNull($decoded['name']);
        $this->assertNull($decoded['debug']);
        $this->assertNull($decoded['url']);
        $this->assertNull($decoded['locale']);
    }

    #[Test]
    public function secrets_never_appear_in_either_output(): void
    {
        $this->allow();
        config()->set('app.name', self::SECRET_URL);
        config()->set('database.connections.mysql.password', self::SECRET_PASSWORD);
        config()->set('app.key', 'app-key-never-printed');

        foreach ([false, true] as $json) {
            Artisan::call('beacon:application', ['--json' => $json]);
            $output = Artisan::output();

            $this->assertStringNotContainsString('cli-secret', $output);
            $this->assertStringNotContainsString('db.internal', $output);
            $this->assertStringNotContainsString(self::SECRET_PASSWORD, $output);
            $this->assertStringNotContainsString('app-key-never-printed', $output);
            $this->assertStringNotContainsString($this->application()->basePath(), $output);
        }
    }

    #[Test]
    public function a_disabled_beacon_or_inspector_is_rejected(): void
    {
        $this->allow();
        config()->set('beacon.enabled', false);

        $this->assertSame(ApplicationCommand::EXIT_DENIED, Artisan::call('beacon:application', ['--json' => true]));
        $denied = json_decode(Artisan::output(), true);
        $this->assertIsArray($denied);
        $this->assertSame('not_allowed', $denied['error']);

        config()->set('beacon.enabled', true);
        config()->set('beacon.inspectors.application.enabled', false);

        $exitCode = Artisan::call('beacon:application');

        $this->assertSame(ApplicationCommand::EXIT_DENIED, $exitCode);
        $this->assertStringContainsString('disabled', Artisan::output());
    }

    #[Test]
    public function an_inspection_failure_returns_a_generic_error_without_internal_details(): void
    {
        $this->allow();
        $this->application()->make(InspectorRegistry::class)->register(ApplicationInspector::NAME, FailingApplicationInspector::class);

        foreach ([false, true] as $json) {
            $exitCode = Artisan::call('beacon:application', ['--json' => $json]);
            $output = Artisan::output();

            $this->assertSame(ApplicationCommand::EXIT_FAILED, $exitCode);
            $this->assertStringContainsString('unavailable', $output);

            foreach (FailingApplicationInspector::DETAILS as $detail) {
                $this->assertStringNotContainsString($detail, $output);
            }

            $this->assertStringNotContainsString('inspector-secret', $output);
            $this->assertStringNotContainsString('FailingApplicationInspector', $output);
        }
    }

    private function allow(): void
    {
        config()->set('beacon.security.allowed_capabilities', ['application']);
    }
}
