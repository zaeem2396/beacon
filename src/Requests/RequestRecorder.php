<?php

declare(strict_types=1);

namespace Beacon\Requests;

use Beacon\Core\BeaconConfig;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds sanitized observations and writes them when collection is enabled.
 */
final class RequestRecorder
{
    public const ATTR_REQUEST_ID = 'beacon.request_id';

    public const ATTR_STARTED = 'beacon.request_started_hr';

    public const ATTR_STARTED_AT = 'beacon.request_started_at';

    public const ATTR_EXCEPTION = 'beacon.request_exception';

    public function __construct(
        private readonly BeaconConfig $config,
        private readonly RequestStore $store,
        private readonly RouteNormalizer $routes,
        private readonly Application $app,
    ) {
    }

    public function collectionEnabled(): bool
    {
        return $this->config->requestCollectionEnabled();
    }

    public function bootstrap(Request $request): void
    {
        if (! $this->collectionEnabled()) {
            return;
        }

        $headers = [];

        foreach (CorrelationId::HEADER_CANDIDATES as $header) {
            $headers[$header] = $request->headers->get($header);
        }

        $requestId = CorrelationId::fromHeaders($headers);
        $request->attributes->set(self::ATTR_REQUEST_ID, $requestId);
        $request->attributes->set(self::ATTR_STARTED, hrtime(true));
        $request->attributes->set(
            self::ATTR_STARTED_AT,
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.vP'),
        );
    }

    public function captureException(Request $request, \Throwable $exception): void
    {
        if (! $this->collectionEnabled()) {
            return;
        }

        $request->attributes->set(self::ATTR_EXCEPTION, $exception);
    }

    public function finish(Request $request, ?Response $response): void
    {
        if (! $this->collectionEnabled()) {
            return;
        }

        $requestId = $request->attributes->get(self::ATTR_REQUEST_ID);

        if (! is_string($requestId) || ! CorrelationId::isValid($requestId)) {
            return;
        }

        $startedHr = $request->attributes->get(self::ATTR_STARTED);
        $startedAt = $request->attributes->get(self::ATTR_STARTED_AT);
        $exception = $request->attributes->get(self::ATTR_EXCEPTION);
        $route = $request->route();
        $finishedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.vP');
        $duration = is_int($startedHr) ? (int) max(0, (hrtime(true) - $startedHr) / 1_000_000) : null;
        $status = $response?->getStatusCode();
        $hadException = $exception instanceof \Throwable;
        $exceptionClass = null;
        $exceptionId = null;

        if ($exception instanceof \Throwable) {
            $class = $exception::class;
            $exceptionClass = preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]{0,190}$/', $class) === 1 ? $class : null;
            $file = basename(str_replace('\\', '/', $exception->getFile()));
            $exceptionId = substr(hash('sha256', $requestId . '|' . ($exceptionClass ?? 'unknown') . '|' . $file . '|' . $exception->getLine()), 0, 16);
            $status ??= 500;
        } elseif (is_int($status) && $status >= 500) {
            $hadException = true;
        }

        $environment = $this->app->environment();
        $environment = is_string($environment) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $environment) === 1
            ? $environment
            : null;

        $this->store->write(new RequestObservation(
            requestId: $requestId,
            correlationId: $requestId,
            method: $this->routes->method($request),
            routeName: $this->routes->name($route),
            routeUri: $this->routes->uri($request, $route),
            action: $this->routes->action($route),
            status: is_int($status) && $status >= 100 && $status <= 599 ? $status : null,
            startedAt: is_string($startedAt) ? $startedAt : $finishedAt,
            finishedAt: $finishedAt,
            durationMs: $duration !== null && $duration <= 3_600_000 ? $duration : null,
            environment: $environment,
            hadException: $hadException,
            exceptionId: $exceptionId,
            exceptionClass: $exceptionClass,
        ));
    }
}
