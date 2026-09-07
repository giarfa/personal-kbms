<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <a href="#main" class="kb-skip">{{ __('Skip to content') }}</a>

        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('agenda') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Workspace')" class="grid">
                    <flux:sidebar.item icon="calendar-days" :href="route('agenda')" :current="request()->routeIs('agenda')" wire:navigate>
                        {{ __('Agenda') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="calendar" :href="route('calendar')" :current="request()->routeIs('calendar')" wire:navigate>
                        {{ __('Calendar') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Later')" class="grid">
                    <flux:sidebar.item icon="magnifying-glass" badge="FR-013" disabled tabindex="-1" aria-disabled="true">
                        {{ __('Search') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="inbox" badge="FR-014" disabled tabindex="-1" aria-disabled="true">
                        {{ __('Transcript inbox') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:sidebar.nav>
                <x-environment-block />
            </flux:sidebar.nav>
        </flux:sidebar>

        <!-- Mobile toggle -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
