<?php

namespace App\Meetings;

use App\Models\CalendarEvent;

/**
 * The ordered rule set from `config('kbms.event_colour_rules')` (US-012).
 *
 * Array order is the priority and the only reprioritising mechanism: `match()`
 * walks top-down and stops at the first hit. There is no priority key, and no
 * multi-match rendering — an occupancy that satisfies two rules is painted by
 * the earlier one, deliberately.
 *
 * Not container-bound on purpose. Construction is a handful of array reads, and
 * binding it as a singleton would go stale the moment a test (or a future
 * runtime config override) changed the rule list.
 */
final readonly class EventColourRules
{
    /**
     * @param  list<EventColourRule>  $rules
     */
    public function __construct(private array $rules = []) {}

    public static function fromConfig(): self
    {
        $configured = config('kbms.event_colour_rules');

        if (! is_array($configured)) {
            return new self;
        }

        $rules = [];

        foreach ($configured as $rule) {
            $parsed = EventColourRule::fromArray($rule);

            if ($parsed !== null) {
                $rules[] = $parsed;
            }
        }

        return new self($rules);
    }

    /**
     * The first rule this occurrence satisfies, or `null` when none do.
     */
    public function match(CalendarEvent $event): ?EventColourRule
    {
        foreach ($this->rules as $rule) {
            if ($rule->matches($event)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Every valid configured rule, in order — the source for both legends.
     *
     * @return list<EventColourRule>
     */
    public function all(): array
    {
        return $this->rules;
    }
}
