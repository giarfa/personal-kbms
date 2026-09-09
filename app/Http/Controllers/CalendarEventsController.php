<?php

namespace App\Http\Controllers;

use App\Meetings\CalendarFeedQuery;
use App\Meetings\CalendarRange;
use App\Meetings\CalendarView;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The JSON feed FullCalendar's `events` function calls for the visible
 * window only. `from`/`to` are hand-editable query-string values — parsed
 * defensively and clamped, never trusted to bound the query on their own.
 */
class CalendarEventsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        [$from, $to] = $this->window($request->query('from'), $request->query('to'));

        $payloads = CalendarFeedQuery::for($from, $to)
            ->map(fn ($payload): array => $payload->toArray())
            ->values();

        return response()->json($payloads);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(?string $from, ?string $to): array
    {
        try {
            $start = CarbonImmutable::parse($from)->startOfDay();
            $end = CarbonImmutable::parse($to)->startOfDay();
        } catch (Exception) {
            $range = CalendarRange::today(CalendarView::Month);

            return [$range->visibleStart(), $range->visibleEnd()];
        }

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->addDay();
        }

        $maxEnd = $start->addDays(CalendarFeedQuery::MAX_RANGE_DAYS);

        return [$start, $end->greaterThan($maxEnd) ? $maxEnd : $end];
    }
}
