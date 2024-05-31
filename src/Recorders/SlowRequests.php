<?php

namespace Laravel\Pulse\Recorders;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Laravel\Pulse\Concerns\ConfiguresAfterResolving;
use Laravel\Pulse\Pulse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @internal
 */
class SlowRequests
{
    use Concerns\Ignores,
        Concerns\LivewireRoutes,
        Concerns\Sampling,
        Concerns\Thresholds,
        ConfiguresAfterResolving;

    /**
     * The queries that have been executed.
     *
     * @var array<int, array{connectionName: string, time: float, sql: string, bindings: array}>
     */
    protected array $queries = [];

    /**
     * Create a new recorder instance.
     */
    public function __construct(
        protected Pulse $pulse,
    ) {
        //
    }

    /**
     * Register the recorder.
     */
    public function register(callable $record, Application $app): void
    {
        $this->afterResolving(
            $app,
            Kernel::class,
            fn (Kernel $kernel) => $kernel->whenRequestLifecycleIsLongerThan(-1, $record) // @phpstan-ignore method.notFound
        );

        $this->afterResolving(
            $app,
            Dispatcher::class,
            fn (Dispatcher $events) => $events->listen(fn (QueryExecuted $event) => $this->onQueryExecuted($event))
        );
    }

    /**
     * Record the request.
     */
    public function record(Carbon $startedAt, Request $request, Response $response): void
    {
        if (! $request->route() instanceof Route || ! $this->shouldSample()) {
            return;
        }

        [$path, $via] = $this->resolveRoutePath($request);

        if (
            $this->shouldIgnore($path) ||
            $this->underThreshold($duration = ((int) $startedAt->diffInMilliseconds()), $path)
        ) {
            return;
        }

        $this->pulse->record(
            type: 'slow_request',
            key: json_encode([$request->method(), $path, $via], flags: JSON_THROW_ON_ERROR),
            value: $duration,
            timestamp: $startedAt,
        )->max()->count();

        if ($userId = $this->pulse->resolveAuthenticatedUserId()) {
            $this->pulse->record(
                type: 'slow_user_request',
                key: (string) $userId,
                timestamp: $startedAt,
            )->count();
        }

        // this would be configurable...
        $this->pulse->set('slow_request_report', json_encode([$request->method(), $path, $via], flags: JSON_THROW_ON_ERROR), json_encode([
            'duration' => $duration,
            'queries' => $this->queries,
        ], flags: JSON_THROW_ON_ERROR), $startedAt);
    }

    public function onQueryExecuted(QueryExecuted $event): void
    {
        if (count($this->queries) === 100) {
            return;
        }

        $this->queries[] = [
            'connectionName' => $event->connectionName,
            'time' => $event->time,
            'sql' => $event->sql,
            'bindings' => $event->bindings,
        ];
    }
}
