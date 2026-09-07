<?php

namespace Tests\Feature;

use Tests\TestCase;

class DoctorCommandTest extends TestCase
{
    public function test_it_runs_and_prints_every_registered_check(): void
    {
        $this->artisan('kbms:doctor')
            ->expectsOutputToContain('ICS calendar feed')
            ->expectsOutputToContain('Transcripts directory')
            ->expectsOutputToContain('Claude launcher script')
            ->expectsOutputToContain('Queue connection')
            ->expectsOutputToContain('Calendar sync schedule')
            ->expectsOutputToContain('Timezone')
            ->run();
    }
}
