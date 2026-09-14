<?php

namespace Tests\Unit\Doctor;

use App\Doctor\Worker\PsQueueWorkerProbe;
use App\Doctor\Worker\QueueWorkerState;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PsQueueWorkerProbeTest extends TestCase
{
    /**
     * Fakes `ps` with the given raw output and `lsof` with per-pid canned
     * output (keyed by pid, as it appears in the `-p` argument).
     *
     * @param  array<int|string, string>  $lsofOutputByPid
     */
    private function fakeProcesses(string $psOutput, array $lsofOutputByPid = [], int $psExitCode = 0, ?string $lsofExitCodeForPid = null): void
    {
        Process::fake(function (PendingProcess $process) use ($psOutput, $lsofOutputByPid, $psExitCode, $lsofExitCodeForPid) {
            $command = $process->command;
            $bin = is_array($command) ? ($command[0] ?? null) : $command;

            if ($bin === 'ps') {
                return Process::result(output: $psOutput, exitCode: $psExitCode);
            }

            if ($bin === 'lsof') {
                $pid = is_array($command) ? ($command[3] ?? null) : null;

                if ($pid === $lsofExitCodeForPid) {
                    return Process::result(exitCode: 1);
                }

                return Process::result(output: is_string($pid) ? ($lsofOutputByPid[$pid] ?? '') : '');
            }

            return Process::result();
        });
    }

    private function cwdLine(string $path): string
    {
        return "p1\nfcwd\nn{$path}\n";
    }

    public static function elapsedTimeShapes(): array
    {
        return [
            'mm:ss' => ['06:20', 380],
            'hh:mm:ss' => ['01:06:20', 3980],
            'dd-hh:mm:ss' => ['2-01:06:20', 176780],
        ];
    }

    #[DataProvider('elapsedTimeShapes')]
    public function test_each_etime_shape_maps_to_the_right_elapsed_seconds(string $etime, int $expectedElapsedSeconds): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
        $this->fakeProcesses(
            "  111 {$etime} php artisan queue:work --sleep=3\n",
            ['111' => $this->cwdLine(base_path())]
        );

        $observation = (new PsQueueWorkerProbe)->observe();

        $this->assertSame(QueueWorkerState::Running, $observation->state);
        $this->assertTrue($observation->startedAt->equalTo(now()->subSeconds($expectedElapsedSeconds)));
    }

    public function test_a_candidate_whose_cwd_is_another_project_is_excluded(): void
    {
        $this->fakeProcesses(
            "  111 06:20 php artisan queue:work\n",
            ['111' => $this->cwdLine('/var/www/some-other-project')]
        );

        $observation = (new PsQueueWorkerProbe)->observe();

        $this->assertSame(QueueWorkerState::NotRunning, $observation->state);
    }

    public function test_two_workers_for_this_project_yield_the_oldest_start_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
        $this->fakeProcesses(
            "  111 06:20 php artisan queue:work\n  222 01:06:20 php artisan queue:work\n",
            [
                '111' => $this->cwdLine(base_path()),
                '222' => $this->cwdLine(base_path()),
            ]
        );

        $observation = (new PsQueueWorkerProbe)->observe();

        $this->assertSame(QueueWorkerState::Running, $observation->state);
        $this->assertTrue($observation->startedAt->equalTo(now()->subSeconds(3980)));
    }

    public function test_a_failed_ps_is_undetermined_and_names_ps(): void
    {
        $this->fakeProcesses('', psExitCode: 1);

        $observation = (new PsQueueWorkerProbe)->observe();

        $this->assertSame(QueueWorkerState::Undetermined, $observation->state);
        $this->assertStringContainsString('ps', $observation->reason);
    }

    public function test_a_failed_lsof_is_undetermined_and_names_lsof(): void
    {
        $this->fakeProcesses(
            "  111 06:20 php artisan queue:work\n",
            lsofExitCodeForPid: '111'
        );

        $observation = (new PsQueueWorkerProbe)->observe();

        $this->assertSame(QueueWorkerState::Undetermined, $observation->state);
        $this->assertStringContainsString('lsof', $observation->reason);
    }

    public function test_output_with_no_queue_work_line_is_not_running(): void
    {
        $this->fakeProcesses("  111 06:20 php artisan schedule:run\n");

        $observation = (new PsQueueWorkerProbe)->observe();

        $this->assertSame(QueueWorkerState::NotRunning, $observation->state);
    }
}
