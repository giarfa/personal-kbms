<x-layouts::print :title="$row->displayTitle">
    <x-meeting.print-header :row="$row" />

    <div class="kb-print__body kb-md">
        {!! $body !!}
    </div>

    <script>
        window.addEventListener('load', () => window.print());
    </script>
</x-layouts::print>
