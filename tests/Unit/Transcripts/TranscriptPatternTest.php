<?php

namespace Tests\Unit\Transcripts;

use App\Transcripts\TranscriptPattern;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TranscriptPatternTest extends TestCase
{
    public function test_the_default_pattern_parses_a_conventional_filename(): void
    {
        config(['kbms.transcript_pattern' => TranscriptPattern::DEFAULT_PATTERN]);

        $parsed = TranscriptPattern::compile()->parse('20260907_0930_q4_roadmap_review');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-09-07 09:30', $parsed->datetime->format('Y-m-d H:i'));
        $this->assertSame('q4_roadmap_review', $parsed->slug);
    }

    public function test_time_parses_as_four_digits_and_rejects_a_colon(): void
    {
        config(['kbms.transcript_pattern' => TranscriptPattern::DEFAULT_PATTERN]);
        $pattern = TranscriptPattern::compile();

        $this->assertNotNull($pattern->parse('20260907_0930_standup'));
        $this->assertNull($pattern->parse('20260907_09:30_standup'));
    }

    public function test_a_custom_pattern_with_different_literals_compiles_and_parses(): void
    {
        config(['kbms.transcript_pattern' => 'rec_{date}_{time}__{slug}']);

        $parsed = TranscriptPattern::compile()->parse('rec_20260907_1430__standup');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-09-07 14:30', $parsed->datetime->format('Y-m-d H:i'));
        $this->assertSame('standup', $parsed->slug);
    }

    public function test_a_non_matching_filename_yields_null_and_no_error(): void
    {
        config(['kbms.transcript_pattern' => TranscriptPattern::DEFAULT_PATTERN]);

        $this->assertNull(TranscriptPattern::compile()->parse('random-recording-name'));
    }

    public function test_regex_metacharacters_in_literal_portions_are_escaped(): void
    {
        config(['kbms.transcript_pattern' => '{date}.{time}.{slug}']);
        $pattern = TranscriptPattern::compile();

        // A literal "." must not act as "any character" — "20260907X0930Xstandup"
        // must not match even though it has the right shape.
        $this->assertNull($pattern->parse('20260907X0930Xstandup'));
        $this->assertNotNull($pattern->parse('20260907.0930.standup'));
    }

    public function test_a_pattern_missing_slug_falls_back_to_the_default_and_logs_a_warning(): void
    {
        Log::shouldReceive('warning')->once();

        config(['kbms.transcript_pattern' => '{date}-{time}']);

        $parsed = TranscriptPattern::compile()->parse('20260907_0930_standup');

        $this->assertNotNull($parsed);
        $this->assertSame('standup', $parsed->slug);
    }

    public function test_a_pattern_ordering_slug_before_date_falls_back_to_the_default_and_logs_a_warning(): void
    {
        Log::shouldReceive('warning')->once();

        config(['kbms.transcript_pattern' => '{slug}-{date}-{time}']);

        $parsed = TranscriptPattern::compile()->parse('20260907_0930_standup');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-09-07 09:30', $parsed->datetime->format('Y-m-d H:i'));
    }

    public function test_render_shares_the_compiler_with_parse(): void
    {
        config(['kbms.transcript_pattern' => TranscriptPattern::DEFAULT_PATTERN]);
        $pattern = TranscriptPattern::compile();

        $rendered = $pattern->render(CarbonImmutable::parse('2026-09-07 14:30'), 'Q4 Roadmap Review');

        $this->assertSame('20260907_1430_q4_roadmap_review', $rendered);
        $this->assertNotNull($pattern->parse($rendered));
    }
}
