<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main id="main" role="main">
        <flux:header container class="mb-6 border-b border-zinc-200 pb-4 dark:border-zinc-700">
            <flux:sidebar.toggle class="lg:hidden mr-2" icon="bars-2" inset="left" />

            <flux:breadcrumbs>
                {{ $breadcrumbs ?? '' }}
            </flux:breadcrumbs>

            <flux:spacer />

            <livewire:sync-status />

            <button
                type="button"
                x-data
                x-on:click="$flux.appearance = $flux.appearance === 'dark' ? 'light' : 'dark'"
                aria-label="{{ __('Toggle color theme') }}"
                class="inline-flex size-8 items-center justify-center rounded-md text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
            >
                <flux:icon name="sun" variant="mini" class="dark:hidden" />
                <flux:icon name="moon" variant="mini" class="hidden dark:inline" />
            </button>
        </flux:header>

        <livewire:sync-alert />

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
