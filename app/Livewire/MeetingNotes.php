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
     * Whether a `meeting_notes` row currently exists — display-only, drives
     * whether the delete control renders. Kept separate from `hasContent()`:
     * a blank-bodied row still has something to delete.
     */
    public bool $hasNote = false;

    #[Locked]
    public string $eventSummary = '';

    #[Locked]
    public string $eventWhen = '';

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
        $this->eventSummary = (string) $occurrence->summary;
        $this->eventWhen = $occurrence->is_all_day
            ? $occurrence->starts_at->format('D j M')
            : $occurrence->starts_at->format('D j M H:i');

        $note = MeetingNote::query()->forOccurrence($this->occurrenceKey())->first();

        if ($note !== null) {
            $this->body = $note->body;
            $this->saveState = 'saved';
            $this->savedAt = $note->updated_at->format('H:i');
            $this->hasNote = true;
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
     * The only path that removes the row — autosave never deletes (clearing
     * the editor persists a blank body and keeps the row). Resets the
     * properties directly rather than going through `save()`/`updatedBody()`,
     * so deleting cannot turn around and recreate the row.
     */
    public function deleteNote(): void
    {
        MeetingNote::query()->forOccurrence($this->occurrenceKey())->delete();

        $this->body = '';
        $this->saveState = 'idle';
        $this->savedAt = null;
        $this->hasNote = false;
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
            $this->hasNote = true;
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
            // Only rendered in Preview mode — every debounced keystroke would
            // otherwise re-run the Markdown conversion for a view the operator
            // is not looking at.
            'preview' => $this->mode === 'preview' ? app(MarkdownRenderer::class)->render($this->body) : null,
            'printUrl' => route('meetings.note.print', $this->occurrenceKey()->toRouteKey()),
        ]);
    }
}
