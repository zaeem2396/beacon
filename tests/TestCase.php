<?php

declare(strict_types=1);

namespace Beacon\Tests;

use Beacon\BeaconServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BeaconServiceProvider::class];
    }

    protected function application(): Application
    {
        return $this->app ?? throw new \LogicException('The application has not been booted.');
    }
}
