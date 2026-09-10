<?php

namespace App\Models;

use App\Launcher\PromptLaunchStatus;
use App\Meetings\OccurrenceKey;
use Database\Factories\PromptLaunchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $event_uid
 * @property string $event_recurrence_id
 * @property string $context_path
 * @property string $question
 * @property list<string> $command
 * @property PromptLaunchStatus $status
 * @property int|null $exit_code
 * @property string|null $error
 * @property Carbon|null $launched_at
 *
 * No Eloquent relation to CalendarEvent is defined here, deliberately — see
 * the migration's docblock. A composite-key hasOne()->where() workaround
 * would cross-contaminate siblings under with(). Reads go through
 * scopeForOccurrence(), exactly like MeetingNote and MeetingTranscript.
 */
class PromptLaunch extends Model
{
    /** @use HasFactory<PromptLaunchFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'command' => 'array',
        'status' => PromptLaunchStatus::class,
        'launched_at' => 'datetime',
    ];

    /**
     * Scope to the launches addressed by the given occurrence's natural key.
     */
    public function scopeForOccurrence(Builder $query, OccurrenceKey $key): Builder
    {
        return $query->where('event_uid', $key->sourceUid)->where('event_recurrence_id', $key->recurrenceId);
    }

    /**
     * Newest first — the "latest launch for this occurrence" lookup.
     */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at');
    }

    public function occurrenceKey(): OccurrenceKey
    {
        return new OccurrenceKey($this->event_uid, $this->event_recurrence_id);
    }
}
