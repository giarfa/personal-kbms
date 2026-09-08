<?php

namespace App\Meetings;

use App\Models\CalendarEvent;

/**
 * Resolves the "Open in Outlook" call-to-action in one place, with an explicit
 * three-way outcome, so the view never has to choose between a real URL and a broken one.
 */
class OutlookLinkBuilder
{
    public function for(CalendarEvent $event): OutlookLink
    {
        if ($event->event_url !== null && $this->isSafeUrl($event->event_url)) {
            return new OutlookLink($event->event_url, OutlookLinkSource::Feed);
        }

        $template = config('kbms.outlook_url_template');

        if ($template !== null) {
            return new OutlookLink($this->substitute($template, $event), OutlookLinkSource::Template);
        }

        return new OutlookLink(null, OutlookLinkSource::Unavailable, 'No feed link and KBMS_OUTLOOK_URL_TEMPLATE is unset.');
    }

    /**
     * The Teams join URL is resolved independently — it is a distinct action,
     * never merged into the Outlook calendar CTA.
     */
    public function teamsUrl(CalendarEvent $event): ?string
    {
        return $event->join_url !== null && $this->isSafeUrl($event->join_url) ? $event->join_url : null;
    }

    /**
     * The feed is a semi-trusted third party: reject anything but http(s) so a
     * malicious/compromised ICS source can never smuggle a `javascript:` (or
     * other dangerous-scheme) URL into a rendered `href`.
     */
    private function isSafeUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array(strtolower((string) $scheme), ['http', 'https'], true);
    }

    private function substitute(string $template, CalendarEvent $event): string
    {
        if ($event->is_all_day) {
            $date = $event->starts_at->format('Y-m-d');
            $time = '00:00';
            $datetime = $date.'T00:00:00';
        } else {
            $local = $event->starts_at->clone()->setTimezone(config('kbms.timezone'));
            $date = $local->format('Y-m-d');
            $time = $local->format('H:i');
            $datetime = $local->toIso8601String();
        }

        return strtr($template, [
            '{date}' => $date,
            '{time}' => $time,
            '{datetime}' => $datetime,
        ]);
    }
}
