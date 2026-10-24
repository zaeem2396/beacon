<?php

declare(strict_types=1);

namespace Beacon\Tests\Fixtures;

final class RouteSpyController
{
    public static int $constructed = 0;

    public static int $invoked = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function show(): string
    {
        self::$invoked++;

        return 'handler-body-not-in-output';
    }
}
