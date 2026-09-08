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

        CalendarSyncRun::factory()->successful()->count(10)->create();
    }
}
