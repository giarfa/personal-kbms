<?php

namespace Database\Seeders;

use App\Models\CalendarSyncRun;
use Illuminate\Database\Seeder;

class CalendarSyncRunSeeder extends Seeder
{
    /**
     * Seed a run history covering every status US-004 needs to render.
     */
    public function run(): void
    {
        CalendarSyncRun::factory()->failed()->create([
            'started_at' => now()->subHours(6),
            'finished_at' => now()->subHours(6)->addSeconds(5),
        ]);

        CalendarSyncRun::factory()->notModified()->create([
            'started_at' => now()->subHours(4),
            'finished_at' => now()->subHours(4)->addSecond(),
        ]);

        // Ten successes at ~15-minute intervals, ending with a very recent run
        // so `migrate:fresh --seed` always demos a healthy mirror (Ok state),
        // not whatever random "-7 days" timestamp happened to land last.
        for ($i = 9; $i >= 0; $i--) {
            $startedAt = now()->subMinutes(2 + $i * 15);

            CalendarSyncRun::factory()->successful()->create([
                'started_at' => $startedAt,
                'finished_at' => $startedAt->clone()->addSeconds(2),
            ]);
        }
    }
}
