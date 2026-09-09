<?php

namespace App\Models;

use App\Meetings\OccurrenceKey;
use App\Transcripts\TranscriptLinkSource;
use Database\Factories\MeetingTranscriptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $event_uid
 * @property string $event_recurrence_id
 * @property string|null $path
 * @property TranscriptLinkSource $link_source
 * @property int|null $file_size
 * @property Carbon|null $file_mtime
 * @property Carbon|null $linked_at
 *
 * No Eloquent relation to CalendarEvent is defined here, deliberately — see
 * the migration's docblock. Reads go through scopeForOccurrence() and the
 * batched lookup in MeetingCoverageLookup, never a hasOne().
 */
class MeetingTranscript extends Model
{
    /** @use HasFactory<MeetingTranscriptFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'link_source' => TranscriptLinkSource::class,
        'file_mtime' => 'datetime',
        'linked_at' => 'datetime',
    ];

    /**
     * Scope to the single transcript row addressed by the given occurrence's natural key.
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
     * The manual-unlink tombstone: the operator cleared a link and the
     * convention must not re-link this occurrence (decision journal:
     * "transcript clear link semantics").
     */
    public function isSuppressed(): bool
    {
        return $this->link_source === TranscriptLinkSource::Manual && $this->path === null;
    }

    public function isManual(): bool
    {
        return $this->link_source === TranscriptLinkSource::Manual;
    }
}
