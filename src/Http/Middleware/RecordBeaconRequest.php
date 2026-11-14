<?php

declare(strict_types=1);

namespace Beacon\Http\Middleware;

use Beacon\Requests\RequestRecorder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opt-in request collector. Disabled collection is a no-op.
 *
 * Does not change responses, swallow exceptions, or read bodies, cookies,
 * authorization headers, or query strings.
 */
final class RecordBeaconRequest
{
    public function __construct(
        private readonly RequestRecorder $recorder,
    ) {
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        if (! $this->recorder->collectionEnabled()) {
            return $next($request);
        }

        $this->recorder->bootstrap($request);

        try {
            return $next($request);
        } catch (\Throwable $exception) {
            $this->recorder->captureException($request, $exception);

            throw $exception;
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->recorder->finish($request, $response);
    }
}
