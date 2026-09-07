@props([])

<div {{ $attributes->class('px-3 py-2 text-xs leading-relaxed text-zinc-500 dark:text-white/60') }}>
    <strong class="text-zinc-800 dark:text-white">{{ __('Local only') }}</strong><br>
    <code class="font-mono">127.0.0.1</code> &middot; {{ config('app.timezone') }}<br>
    {{ __('SQLite') }} &middot; {{ __('no accounts') }}
</div>
