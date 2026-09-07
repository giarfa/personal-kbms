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
        $response->assertSee('<h1', false);
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
        $response->assertSeeText('Calendar');
    }

    public function test_agenda_and_calendar_routes_resolve_by_name(): void
    {
        $this->assertSame('/', route('agenda', absolute: false));
        $this->assertSame('/calendar', route('calendar', absolute: false));
    }
}
