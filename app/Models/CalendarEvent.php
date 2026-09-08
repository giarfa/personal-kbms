<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\CalendarEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $source_uid
 * @property string $recurrence_id
 * @property array<int, array{name: ?string, email: ?string}>|null $attendees
 */
class CalendarEvent extends Model
{
    /** @use HasFactory<CalendarEventFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attendees' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_all_day' => 'boolean',
        'last_seen_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Scope to occurrences whose start falls within the given window.
     */
    public function scopeInWindow(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('starts_at', [$from, $to]);
    }
}
