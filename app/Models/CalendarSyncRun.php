<?php

namespace App\Models;

use App\Calendar\SyncRunStatus;
use Database\Factories\CalendarSyncRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CalendarSyncRun extends Model
{
    /** @use HasFactory<CalendarSyncRunFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => SyncRunStatus::class,
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Create a new Running run, started now.
     */
    public static function start(): self
    {
        return self::query()->create([
            'started_at' => now(),
            'status' => SyncRunStatus::Running,
        ]);
    }

    /**
     * Record a successful run with its validators and counts.
     */
    public function succeed(int $httpStatus, ?string $etag, ?string $lastModified, int $upserted, int $cancelled): void
    {
        $this->update([
            'finished_at' => now(),
            'status' => SyncRunStatus::Success,
            'http_status' => $httpStatus,
            'etag' => $etag,
            'last_modified' => $lastModified,
            'events_upserted' => $upserted,
            'events_cancelled' => $cancelled,
        ]);
    }

    /**
     * Record a 304 Not Modified response, carrying the validators forward.
     */
    public function markNotModified(?string $etag, ?string $lastModified): void
    {
        $this->update([
            'finished_at' => now(),
            'status' => SyncRunStatus::NotModified,
            'http_status' => 304,
            'etag' => $etag,
            'last_modified' => $lastModified,
        ]);
    }

    /**
     * Record a failed run.
     */
    public function fail(string $error, ?int $httpStatus = null): void
    {
        $this->update([
            'finished_at' => now(),
            'status' => SyncRunStatus::Failed,
            'http_status' => $httpStatus,
            'error' => $error,
        ]);
    }

    /**
     * Mark a stuck `Running` row as failed, naming the fix in the error.
     */
    public function abandon(string $reason): void
    {
        $this->update([
            'finished_at' => now(),
            'status' => SyncRunStatus::Failed,
            'error' => $reason,
        ]);
    }

    /**
     * The most recent run whose validators are usable for a conditional GET.
     */
    public static function latestValidators(): ?self
    {
        return self::query()
            ->whereIn('status', [SyncRunStatus::Success, SyncRunStatus::NotModified])
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * The most recent run that counts as a successful sync (Success or NotModified).
     */
    public static function latestSuccess(): ?self
    {
        return self::latestValidators();
    }

    /**
     * The currently running row, if any.
     */
    public static function activeRun(): ?self
    {
        return self::query()
            ->where('status', SyncRunStatus::Running)
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * The most recently started run that has finished (successfully or not).
     */
    public static function latestFinished(): ?self
    {
        return self::query()
            ->whereNotNull('finished_at')
            ->orderByDesc('started_at')
            ->first();
    }
}
