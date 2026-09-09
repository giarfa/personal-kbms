<?php

namespace Database\Seeders;

use App\Models\CalendarEvent;
use App\Models\MeetingNote;
use Illuminate\Database\Seeder;

class MeetingNoteSeeder extends Seeder
{
    /**
     * Notes on a mix of annotated / blank / un-annotated meetings, so the agenda's
     * coverage badges and the recurrence-isolation shape are demonstrable in the
     * running app without editing the database. Targets are resolved by querying
     * `calendar_events` so this seeder cannot drift from CalendarEventSeeder.
     */
    public function run(): void
    {
        $currentWeekSync = CalendarEvent::query()
            ->where('summary', 'like', 'Weekly sync%')
            ->get()
            ->sortBy(fn (CalendarEvent $event): int => (int) round(abs($event->starts_at->diffInSeconds(now(), true))))
            ->first();

        if ($currentWeekSync !== null) {
            MeetingNote::factory()->forOccurrence($currentWeekSync)->create();
        }

        $clientKickoff = CalendarEvent::query()->where('summary', 'Client kickoff')->first();

        if ($clientKickoff !== null) {
            MeetingNote::factory()->forOccurrence($clientKickoff)->create();
        }

        $budgetReview = CalendarEvent::query()->where('summary', 'Budget review')->first();

        if ($budgetReview !== null) {
            MeetingNote::factory()->forOccurrence($budgetReview)->create();
        }

        $designReview = CalendarEvent::query()->where('summary', 'Design review')->first();

        if ($designReview !== null) {
            MeetingNote::factory()->forOccurrence($designReview)->blank()->create();
        }
    }
}
