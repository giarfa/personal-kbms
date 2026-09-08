<x-layouts::app :title="__('Meeting')">
    <flux:heading level="1">{{ $event->summary }}</flux:heading>
    <flux:text class="mt-2">
        {{ __('The mirrored feed panel and Outlook/Teams links arrive with US-005.') }}
    </flux:text>
</x-layouts::app>
