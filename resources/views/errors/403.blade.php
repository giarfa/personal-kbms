<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>403 - {{ config('app.name') }}</title>
    </head>
    <body style="font-family: ui-monospace, monospace; max-width: 40rem; margin: 4rem auto; padding: 0 1rem;">
        <h1>This application serves loopback only</h1>
        <p>The request did not come from 127.0.0.1 or ::1, so it was rejected.</p>
        <p>
            This is a deliberate override, not an error: set
            <code>KBMS_ALLOW_NON_LOOPBACK=true</code> in <code>.env</code> to lift the block.
        </p>
    </body>
</html>
