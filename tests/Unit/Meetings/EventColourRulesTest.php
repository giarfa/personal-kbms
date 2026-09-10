<?php

namespace Tests\Unit\Meetings;

use App\Meetings\EventColour;
use App\Meetings\EventColourRule;
use App\Meetings\EventColourRules;
use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * US-012. These tests pin what a *match* means before anything renders it, so
 * a later rendering change cannot quietly redefine the vocabulary.
 */
class EventColourRulesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Always set `location` explicitly: the factory gives it a 50/50 chance of
     * being null, which would let a coin flip decide an `empty`-rule outcome.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function event(array $attributes = []): CalendarEvent
    {
        return CalendarEvent::factory()->make(array_merge([
            'summary' => 'Roadmap review',
            'location' => 'Meeting room 2',
            'description' => 'Quarterly planning',
            'organizer' => 'Chiara <chiara@example.test>',
        ], $attributes));
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function rules(array $rules): EventColourRules
    {
        config(['kbms.event_colour_rules' => $rules]);

        return EventColourRules::fromConfig();
    }

    /**
     * @return array<string, mixed>
     */
    private function pingRule(string $colour = 'yellow'): array
    {
        return ['field' => 'summary', 'condition' => 'contains', 'value' => 'PING', 'colour' => $colour, 'label' => 'Ping'];
    }

    /**
     * @return array<string, mixed>
     */
    private function noLocationRule(string $colour = 'purple'): array
    {
        return ['field' => 'location', 'condition' => 'empty', 'colour' => $colour, 'label' => 'No location'];
    }

    public function test_contains_matches_case_insensitively(): void
    {
        $rules = $this->rules([$this->pingRule()]);

        foreach (['PING weekly', 'Ping weekly', 'ping weekly', 'Weekly ping sync'] as $summary) {
            $this->assertNotNull(
                $rules->match($this->event(['summary' => $summary])),
                "Expected [{$summary}] to match the ping rule.",
            );
        }
    }

    public function test_contains_does_not_match_when_the_needle_is_absent(): void
    {
        $rules = $this->rules([$this->pingRule()]);

        $this->assertNull($rules->match($this->event(['summary' => 'Roadmap review'])));
    }

    public function test_a_null_summary_matches_no_contains_rule_and_raises_nothing(): void
    {
        $rules = $this->rules([$this->pingRule()]);

        $this->assertNull($rules->match($this->event(['summary' => null])));
    }

    public function test_empty_covers_null_blank_and_whitespace_only(): void
    {
        $rules = $this->rules([$this->noLocationRule()]);

        // The single-space case is the one the spec names: an ICS feed emitting
        // `LOCATION:` with a space must read as empty, not as a location.
        foreach ([null, '', ' ', "\t", "  \n "] as $location) {
            $this->assertNotNull(
                $rules->match($this->event(['location' => $location])),
                'Expected ['.var_export($location, true).'] to read as empty.',
            );
        }
    }

    public function test_empty_is_false_for_a_real_value(): void
    {
        $rules = $this->rules([$this->noLocationRule()]);

        $this->assertNull($rules->match($this->event(['location' => 'Meeting room 2'])));
    }

    public function test_the_first_matching_rule_wins_and_later_rules_are_skipped(): void
    {
        $both = $this->event(['summary' => 'PING weekly', 'location' => null]);

        $pingFirst = $this->rules([$this->pingRule(), $this->noLocationRule()]);
        $matched = $pingFirst->match($both);

        $this->assertInstanceOf(EventColourRule::class, $matched);
        $this->assertSame(EventColour::Yellow, $matched->colour);
        $this->assertSame('Ping', $matched->label);
    }

    public function test_reordering_the_array_reverses_the_outcome(): void
    {
        $both = $this->event(['summary' => 'PING weekly', 'location' => null]);

        // Array order is the only priority mechanism, so this is the whole
        // reprioritising API — there is deliberately no priority key.
        $noLocationFirst = $this->rules([$this->noLocationRule(), $this->pingRule()]);
        $matched = $noLocationFirst->match($both);

        $this->assertInstanceOf(EventColourRule::class, $matched);
        $this->assertSame(EventColour::Purple, $matched->colour);
        $this->assertSame('No location', $matched->label);
    }

    public function test_every_malformed_rule_shape_is_dropped(): void
    {
        $malformed = [
            'unknown field' => ['field' => 'attendees', 'condition' => 'empty', 'colour' => 'green', 'label' => 'X'],
            'unknown condition' => ['field' => 'summary', 'condition' => 'regex', 'value' => 'x', 'colour' => 'green', 'label' => 'X'],
            'unknown colour' => ['field' => 'summary', 'condition' => 'contains', 'value' => 'x', 'colour' => 'chartreuse', 'label' => 'X'],
            'raw css colour' => ['field' => 'summary', 'condition' => 'contains', 'value' => 'x', 'colour' => '#ff0000', 'label' => 'X'],
            'missing label' => ['field' => 'location', 'condition' => 'empty', 'colour' => 'green'],
            'blank label' => ['field' => 'location', 'condition' => 'empty', 'colour' => 'green', 'label' => '   '],
            'contains without a needle' => ['field' => 'summary', 'condition' => 'contains', 'colour' => 'green', 'label' => 'X'],
            'contains with an empty needle' => ['field' => 'summary', 'condition' => 'contains', 'value' => '', 'colour' => 'green', 'label' => 'X'],
            'missing field' => ['condition' => 'empty', 'colour' => 'green', 'label' => 'X'],
            'not an array' => 'summary contains PING yellow',
        ];

        foreach ($malformed as $reason => $rule) {
            $this->assertSame([], $this->rules([$rule])->all(), "Expected [{$reason}] to be dropped.");
        }
    }

    public function test_a_malformed_rule_does_not_disturb_a_valid_one_beside_it(): void
    {
        $rules = $this->rules([
            ['field' => 'summary', 'condition' => 'regex', 'value' => 'x', 'colour' => 'green', 'label' => 'Bad'],
            $this->pingRule(),
        ]);

        $this->assertCount(1, $rules->all());

        $matched = $rules->match($this->event(['summary' => 'PING weekly']));
        $this->assertInstanceOf(EventColourRule::class, $matched);
        $this->assertSame('Ping', $matched->label);
    }

    public function test_an_empty_or_non_array_config_yields_an_empty_rule_set(): void
    {
        foreach ([[], null, 'nonsense', 42] as $configured) {
            config(['kbms.event_colour_rules' => $configured]);
            $rules = EventColourRules::fromConfig();

            $this->assertSame([], $rules->all());
            $this->assertNull($rules->match($this->event(['summary' => 'PING weekly', 'location' => null])));
        }
    }

    public function test_the_shipped_defaults_are_ping_then_no_location(): void
    {
        // The order shipped in config/kbms.php is itself a product decision:
        // a meeting that is both a PING and locationless must read as PING.
        $rules = EventColourRules::fromConfig();
        $all = $rules->all();

        $this->assertCount(2, $all);
        $this->assertSame('Ping', $all[0]->label);
        $this->assertSame(EventColour::Yellow, $all[0]->colour);
        $this->assertSame('No location', $all[1]->label);
        $this->assertSame(EventColour::Purple, $all[1]->colour);

        $matched = $rules->match($this->event(['summary' => 'PING weekly', 'location' => null]));
        $this->assertInstanceOf(EventColourRule::class, $matched);
        $this->assertSame(EventColour::Yellow, $matched->colour);
    }
}
