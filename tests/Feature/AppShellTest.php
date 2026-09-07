<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_agenda_route_renders_the_shell(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), '<h1'), 'expected exactly one <h1> on the Agenda page');
        $response->assertSeeText('Agenda');
        $response->assertSee('Workspace');
        $response->assertSee('Later');
        $response->assertSee('kb-skip', false);
        $response->assertSee('noindex, nofollow', false);
        $response->assertSee('/favicon.svg', false);
    }

    public function test_calendar_route_renders_the_shell(): void
    {
        $response = $this->get('/calendar');

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), '<h1'), 'expected exactly one <h1> on the Calendar page');
        $response->assertSeeText('Calendar');
    }

    public function test_agenda_and_calendar_routes_resolve_by_name(): void
    {
        $this->assertSame('/', route('agenda', absolute: false));
        $this->assertSame('/calendar', route('calendar', absolute: false));
    }
}
