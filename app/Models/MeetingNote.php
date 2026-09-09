<?php

namespace App\Models;

use App\Meetings\OccurrenceKey;
use Database\Factories\MeetingNoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $event_uid
 * @property string $event_recurrence_id
 * @property string $body
 */
class MeetingNote extends Model
{
    /** @use HasFactory<MeetingNoteFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * Scope to the single note addressed by the given occurrence's natural key.
     */
    public function scopeForOccurrence(Builder $query, OccurrenceKey $key): Builder
    {
        return $query->where('event_uid', $key->sourceUid)->where('event_recurrence_id', $key->recurrenceId);
    }

    public function occurrenceKey(): OccurrenceKey
    {
        return new OccurrenceKey($this->event_uid, $this->event_recurrence_id);
    }

    /**
     * Whether the note carries operator content — the single definition of
     * "annotated", consumed by the agenda/detail coverage lookup.
     */
    public function hasContent(): bool
    {
        return trim($this->body) !== '';
    }
}
