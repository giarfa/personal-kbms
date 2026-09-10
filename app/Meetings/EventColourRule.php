<?php

namespace App\Meetings;

use App\Models\CalendarEvent;

/**
 * One colour rule from `config('kbms.event_colour_rules')` (US-012).
 *
 * Every field is drawn from a closed enum, so a rule that survives
 * construction cannot put anything unexpected into markup. Anything the
 * factory cannot resolve is not a rule at all — see `fromArray()`.
 */
final readonly class EventColourRule
{
    public function __construct(
        public EventColourField $field,
        public EventColourCondition $condition,
        public ?string $value,
        public EventColour $colour,
        public string $label,
    ) {}

    /**
     * Build a rule from a raw configuration entry, or `null` when the entry
     * names anything outside the closed vocabulary.
     *
     * Returning `null` rather than throwing is the acceptance criterion: a
     * malformed rule is ignored, the occurrence renders uncoloured, and the
     * page still renders. A misconfigured colour must never break the agenda.
     */
    public static function fromArray(mixed $rule): ?self
    {
        if (! is_array($rule)) {
            return null;
        }

        $field = EventColourField::tryFrom(self::stringOrNull($rule['field'] ?? null) ?? '');
        $condition = EventColourCondition::tryFrom(self::stringOrNull($rule['condition'] ?? null) ?? '');
        $colour = EventColour::tryFrom(self::stringOrNull($rule['colour'] ?? null) ?? '');
        $label = trim(self::stringOrNull($rule['label'] ?? null) ?? '');
        $value = self::stringOrNull($rule['value'] ?? null);

        if ($field === null || $condition === null || $colour === null || $label === '') {
            return null;
        }

        // A `contains` rule with no needle would paint every single occurrence,
        // because str_contains($x, '') is always true. That is never what the
        // operator meant, so the rule is dropped rather than honoured.
        if ($condition === EventColourCondition::Contains && ($value === null || $value === '')) {
            return null;
        }

        return new self($field, $condition, $value, $colour, $label);
    }

    public function matches(CalendarEvent $event): bool
    {
        return $this->condition->matches($this->field->valueFor($event), $this->value);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
