<x-layouts::print :title="$row->displayTitle">
    <x-meeting.print-header :row="$row" :source-filename="$export->filename()" />

    <div class="kb-print__body @if ($export->content->isMarkdown) kb-md @else kb-print--mono @endif">
        {!! $export->content->rendered !!}
    </div>

    <script>
        window.addEventListener('load', () => window.print());
    </script>
</x-layouts::print>
