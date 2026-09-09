<?php

namespace App\Livewire;

use App\Meetings\MarkdownRenderer;
use App\Meetings\OccurrenceKey;
use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class MeetingNotes extends Component
{
    #[Locked]
    public string $eventUid = '';

    #[Locked]
    public string $eventRecurrenceId = '';

    public string $body = '';

    /**
     * @var 'idle'|'saving'|'saved'|'error'
     */
    public string $saveState = 'idle';

    public ?string $savedAt = null;

    /**
     * @var 'write'|'preview'
     */
    public string $mode = 'write';

    /**
     * Store only the occurrence's natural key, never the model — a
     * Livewire-hydrated model would reintroduce the surrogate id as the
     * identity. There is deliberately no starts_at/isPast() branch anywhere
     * in this component: a note is writable before and after the meeting,
     * and on a cancelled occurrence.
     */
    public function mount(CalendarEvent $occurrence): void
    {
        $this->eventUid = $occurrence->source_uid;
        $this->eventRecurrenceId = $occurrence->recurrence_id;

        $note = MeetingNote::query()->forOccurrence($this->occurrenceKey())->first();

        if ($note !== null) {
            $this->body = $note->body;
            $this->saveState = 'saved';
            $this->savedAt = $note->updated_at->format('H:i');
        }
    }

    public function updatedBody(): void
    {
        $this->save();
    }

    /**
     * Re-attempt the write after a failure. The typed body was never
     * discarded, so this persists exactly what is still in the editor.
     */
    public function retry(): void
    {
        $this->save();
    }

    public function switchMode(string $mode): void
    {
        $this->mode = $mode === 'preview' ? 'preview' : 'write';
    }

    /**
     * A failure sets `error` and leaves `$body` untouched — it is never
     * reassigned from the database on this path, or typed content would be
     * silently discarded.
     */
    private function save(): void
    {
        $this->saveState = 'saving';

        try {
            MeetingNote::query()->updateOrCreate(
                ['event_uid' => $this->eventUid, 'event_recurrence_id' => $this->eventRecurrenceId],
                ['body' => $this->body],
            );

            $this->saveState = 'saved';
            $this->savedAt = now()->format('H:i');
        } catch (Throwable $e) {
            report($e);

            $this->saveState = 'error';
        }
    }

    private function occurrenceKey(): OccurrenceKey
    {
        return new OccurrenceKey($this->eventUid, $this->eventRecurrenceId);
    }

    public function render(): View
    {
        return view('livewire.meeting-notes', [
            'preview' => app(MarkdownRenderer::class)->render($this->body),
        ]);
    }
}
