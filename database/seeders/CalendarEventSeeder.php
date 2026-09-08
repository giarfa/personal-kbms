<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CalendarEventSeeder extends Seeder
{
    /**
     * Seed a fixture calendar the reading surfaces (US-004/US-005) can be built against.
     */
    public function run(): void
    {
        $seriesUid = (string) Str::uuid();

        $seriesStart = now()->subWeeks(4)->startOfWeek()->setTime(9, 0);

        foreach (range(0, 11) as $week) {
            $occurrenceStart = (clone $seriesStart)->addWeeks($week);
            $recurrenceId = $occurrenceStart->clone()->utc()->format('Y-m-d\TH:i:s\Z');

            CalendarEvent::factory()->occurrenceOf($seriesUid, $recurrenceId)->create([
                'summary' => $week === 3 ? 'Weekly sync (moved)' : 'Weekly sync',
                'starts_at' => $occurrenceStart,
                'ends_at' => $occurrenceStart->clone()->addMinutes(30),
            ]);
        }

        CalendarEvent::factory()->allDay()->count(2)->create();

        CalendarEvent::factory()->cancelled()->count(3)->create();

        CalendarEvent::factory()->withTeamsLink()->count(4)->create();

        CalendarEvent::factory()->count(21)->create();
    }
}
