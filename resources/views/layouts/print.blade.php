@props(['title'])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta name="robots" content="noindex, nofollow" />

        <title>{{ $title }}</title>

        @vite(['resources/css/app.css'])
    </head>
    <body class="kb-print">
        {{ $slot }}
    </body>
</html>
