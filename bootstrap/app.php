<?php

use App\Http\Middleware\EnsureLoopbackRequest;
use App\Support\DatabaseUnavailability;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(EnsureLoopbackRequest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (QueryException $e) {
            // Laravel's SQLiteConnector throws SQLiteDatabaseDoesNotExistException
            // (an InvalidArgumentException, not a QueryException) when the file is
            // missing at connect time; Connection::runQueryCallback() catches that
            // generic \Exception and rewraps it as a QueryException, which is why
            // this message shows up here rather than in an InvalidArgumentException
            // handler. If a future Laravel version stops rewrapping connector
            // exceptions this way, the missing-file branch below stops matching.
            $isUnavailable = str_contains($e->getMessage(), 'unable to open database file')
                || str_contains($e->getMessage(), 'does not exist. Ensure this is an absolute path');

            if ($e->getConnectionName() !== 'sqlite' || ! $isUnavailable) {
                return null;
            }

            $configured = (string) config('database.connections.sqlite.database');
            $path = str_starts_with($configured, '/') ? $configured : base_path($configured);

            // A long-lived FPM worker caches stat() results across requests.
            clearstatcache(true, $path);

            $unavailability = new DatabaseUnavailability($configured, missing: ! file_exists($path));

            return response()->view('errors.database-unavailable', [
                'path' => $unavailability->path,
                'missing' => $unavailability->missing,
            ], 503);
        });
    })->create();
