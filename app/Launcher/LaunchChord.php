<?php

namespace App\Launcher;

/**
 * The launch chord's label vocabulary — symbolic for sighted users, spoken
 * for screen readers. *Detection* of which case applies stays client-side
 * (recorded decision `ask claude keyboard shortcut affordance`): the browser
 * knows the platform, this enum only knows what each platform's chord is
 * called. Keeping the words here, not in JS, is what makes the mapping
 * unit-testable without a browser suite.
 */
enum LaunchChord: string
{
    case Command = 'mac';
    case Control = 'other';

    /**
     * The symbolic chip text — rendered `aria-hidden`, paired with
     * `spokenLabel()` reaching assistive tech through an accessible
     * description instead.
     */
    public function label(): string
    {
        return match ($this) {
            self::Command => '⌘ Enter',
            self::Control => 'Ctrl Enter',
        };
    }

    /**
     * The phrase a screen reader should read. Hyphenated so the two keys
     * are announced as one chord rather than two separate words, and never
     * contains the `⌘` glyph — a screen reader must not be handed the symbol.
     */
    public function spokenLabel(): string
    {
        return match ($this) {
            self::Command => 'Command-Enter',
            self::Control => 'Control-Enter',
        };
    }

    /**
     * Symbolic labels keyed by case value. Built from `self::cases()` so a
     * future case cannot be added without also gaining a label here.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->label();
        }

        return $labels;
    }

    /**
     * Spoken labels keyed by case value. Same completeness guarantee as
     * `labels()`.
     *
     * @return array<string, string>
     */
    public static function spokenLabels(): array
    {
        $labels = [];

        foreach (self::cases() as $case) {
            $labels[$case->value] = $case->spokenLabel();
        }

        return $labels;
    }
}
