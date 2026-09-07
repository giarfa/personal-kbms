<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseUnavailableTest extends TestCase
{
    public function test_a_missing_sqlite_file_renders_an_actionable_503_with_no_trace(): void
    {
        // phpunit.xml forces SESSION_DRIVER=array so plain requests never touch the
        // database; switch it back to force the real session-read path through sqlite.
        config(['session.driver' => 'database']);
        config(['database.connections.sqlite.database' => '/tmp/kbms-test-missing-'.uniqid().'.sqlite']);
        config(['app.debug' => true]);
        DB::purge('sqlite');

        $response = $this->get('/');

        $response->assertStatus(503);
        $response->assertSee('is missing');
        $response->assertSee('touch');
        $response->assertSee('php artisan migrate', false);
        $response->assertDontSee('Stack trace', false);
        $response->assertDontSee('#0 ', false);
    }

    public function test_a_present_but_unreadable_sqlite_file_renders_a_distinct_actionable_503(): void
    {
        $path = sys_get_temp_dir().'/kbms-test-unreadable-'.uniqid().'.sqlite';
        touch($path);
        chmod($path, 0000);

        config(['session.driver' => 'database']);
        config(['database.connections.sqlite.database' => $path]);
        config(['app.debug' => true]);
        DB::purge('sqlite');

        try {
            $response = $this->get('/');

            $response->assertStatus(503);
            $response->assertSee('unreadable');
            $response->assertSee('chmod', false);
        } finally {
            chmod($path, 0644);
            unlink($path);
        }
    }

    public function test_a_genuine_sql_error_still_throws_normally(): void
    {
        $this->expectException(QueryException::class);

        DB::table('nonexistent_table_xyz')->get();
    }
}
