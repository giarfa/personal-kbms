<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CalendarEventSeeder extends Seeder
{
    /**
     * Seed the long-running weekly series (recurrence identity) plus a fixture week
     * anchored on today, so the agenda's link-resolution and coverage shapes are
     * always demonstrable without editing the database.
     */
    public function run(): void
    {
        $timezone = config('kbms.timezone');

        $seriesUid = (string) Str::uuid();
        $seriesStart = now($timezone)->subWeeks(4)->startOfWeek()->setTime(9, 0);

        foreach (range(0, 11) as $week) {
            $occurrenceStart = (clone $seriesStart)->addWeeks($week);
            $recurrenceId = $occurrenceStart->clone()->utc()->format('Y-m-d\TH:i:s\Z');

            CalendarEvent::factory()->occurrenceOf($seriesUid, $recurrenceId)->create([
                'summary' => $week === 3 ? 'Weekly sync (moved)' : 'Weekly sync',
                'starts_at' => $occurrenceStart,
                'ends_at' => $occurrenceStart->clone()->addMinutes(30),
            ]);
        }

        $today = now($timezone)->startOfDay();

        // Every 7-day window contains exactly one Monday, where the series above already
        // lands. Pick the empty day among the other weekdays so it never collides with one.
        $emptyOffset = collect(range(1, 6))
            ->first(fn (int $offset): bool => $today->clone()->addDays($offset)->dayOfWeekIso !== 1);

        [$teamsOffset, $eventUrlOffset, $neitherOffset, $fillerOffsetA, $fillerOffsetB] = collect(range(1, 6))
            ->reject(fn (int $offset): bool => $offset === $emptyOffset)
            ->values()
            ->all();

        // Today: a multi-day all-day offsite, two overlapping timed meetings, and a cancelled one.
        CalendarEvent::factory()->spanning(1)->create([
            'summary' => 'Offsite: product strategy',
            'starts_at' => $today->clone()->format('Y-m-d').' 00:00:00',
            'ends_at' => $today->clone()->addDay()->format('Y-m-d').' 00:00:00',
        ]);

        CalendarEvent::factory()->at($today->clone()->setTime(9, 30), 60)->create([
            'summary' => 'Client kickoff',
        ]);

        CalendarEvent::factory()->at($today->clone()->setTime(10, 0), 60)->create([
            'summary' => 'Design review',
        ]);

        CalendarEvent::factory()->cancelled()->at($today->clone()->setTime(14, 0), 30)->create([
            'summary' => 'Budget review',
        ]);

        // Day +{$emptyOffset} is deliberately left empty so the per-day empty state
        // renders without editing the database.

        CalendarEvent::factory()->withTeamsLink()->at($today->clone()->addDays($teamsOffset)->setTime(11, 0), 45)->create([
            'summary' => 'Sprint planning',
        ]);

        CalendarEvent::factory()->withEventUrl()->at($today->clone()->addDays($eventUrlOffset)->setTime(15, 0), 30)->create([
            'summary' => 'Architecture discussion',
        ]);

        CalendarEvent::factory()->at($today->clone()->addDays($neitherOffset)->setTime(13, 0), 30)->create([
            'summary' => '1:1 check-in',
        ]);

        CalendarEvent::factory()->allDay()->create([
            'starts_at' => $today->clone()->addDays($fillerOffsetA)->format('Y-m-d').' 00:00:00',
            'ends_at' => $today->clone()->addDays($fillerOffsetA)->format('Y-m-d').' 00:00:00',
        ]);

        CalendarEvent::factory()->at($today->clone()->addDays($fillerOffsetB)->setTime(16, 0), 30)->create([
            'summary' => 'Retro',
        ]);

        // Background noise safely outside the default agenda window, for the calendar
        // month view (US-008).
        CalendarEvent::factory()->count(10)->state(function () {
            $start = fake()->dateTimeBetween('-30 days', '-8 days');

            return [
                'starts_at' => $start,
                'ends_at' => (clone $start)->modify('+30 minutes'),
            ];
        })->create();

        CalendarEvent::factory()->count(10)->state(function () {
            $start = fake()->dateTimeBetween('+8 days', '+45 days');

            return [
                'starts_at' => $start,
                'ends_at' => (clone $start)->modify('+30 minutes'),
            ];
        })->create();
    }
}
