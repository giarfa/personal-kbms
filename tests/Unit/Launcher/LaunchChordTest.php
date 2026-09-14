<?php

namespace Tests\Unit\Launcher;

use App\Launcher\LaunchChord;
use PHPUnit\Framework\TestCase;

class LaunchChordTest extends TestCase
{
    public function test_command_label_is_the_mac_glyph_chord(): void
    {
        $this->assertSame('⌘ Enter', LaunchChord::Command->label());
    }

    public function test_control_label_is_the_word_form_chord(): void
    {
        $this->assertSame('Ctrl Enter', LaunchChord::Control->label());
    }

    public function test_spoken_labels_are_hyphenated_word_forms(): void
    {
        $this->assertSame('Command-Enter', LaunchChord::Command->spokenLabel());
        $this->assertSame('Control-Enter', LaunchChord::Control->spokenLabel());
    }

    public function test_spoken_labels_never_contain_the_glyph(): void
    {
        foreach (LaunchChord::cases() as $case) {
            $this->assertStringNotContainsString('⌘', $case->spokenLabel());
        }
    }

    public function test_labels_map_has_exactly_one_entry_per_case(): void
    {
        $labels = LaunchChord::labels();
        $expectedKeys = array_map(fn (LaunchChord $case): string => $case->value, LaunchChord::cases());

        $this->assertSame($expectedKeys, array_keys($labels));

        foreach (LaunchChord::cases() as $case) {
            $this->assertSame($case->label(), $labels[$case->value]);
        }
    }

    public function test_spoken_labels_map_has_exactly_one_entry_per_case(): void
    {
        $spokenLabels = LaunchChord::spokenLabels();
        $expectedKeys = array_map(fn (LaunchChord $case): string => $case->value, LaunchChord::cases());

        $this->assertSame($expectedKeys, array_keys($spokenLabels));

        foreach (LaunchChord::cases() as $case) {
            $this->assertSame($case->spokenLabel(), $spokenLabels[$case->value]);
        }
    }
}
