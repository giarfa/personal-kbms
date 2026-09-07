<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Database unavailable - {{ config('app.name') }}</title>
    </head>
    <body style="font-family: ui-monospace, monospace; max-width: 40rem; margin: 4rem auto; padding: 0 1rem;">
        @if ($missing)
            <h1>SQLite database file is missing</h1>
            <p><code>{{ $path }}</code> does not exist.</p>
            <p>Fix it: <code>touch {{ $path }}</code>, then <code>php artisan migrate</code>.</p>
        @else
            <h1>SQLite database file is unreadable</h1>
            <p><code>{{ $path }}</code> exists but the process cannot read it — check its file permissions.</p>
            <p>Fix it: restore read/write access for the process, e.g. <code>chmod 644 {{ $path }}</code>.</p>
        @endif
    </body>
</html>
