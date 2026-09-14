<?php

namespace Tests\Unit\Doctor;

use App\Doctor\Checks\QueueWorkerFreshnessCheck;
use App\Doctor\CheckStatus;
use App\Doctor\Worker\QueueWorkerObservation;
use App\Doctor\Worker\QueueWorkerProbe;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class QueueWorkerFreshnessCheckTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->envPath = sys_get_temp_dir().'/kbms-doctor-env-'.uniqid();
        file_put_contents($this->envPath, 'APP_ENV=testing');
    }

    protected function tearDown(): void
    {
        if (is_file($this->envPath)) {
            unlink($this->envPath);
        }

        parent::tearDown();
    }

    private function fakeProbe(QueueWorkerObservation $observation): QueueWorkerProbe
    {
        return new class($observation) implements QueueWorkerProbe
        {
            public function __construct(private readonly QueueWorkerObservation $observation) {}

            public function observe(): QueueWorkerObservation
            {
                return $this->observation;
            }
        };
    }

    private function touchEnvAt(CarbonImmutable $at): void
    {
        touch($this->envPath, $at->getTimestamp());
    }

    public function test_it_passes_when_the_worker_started_after_the_env_write(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
        $this->touchEnvAt(CarbonImmutable::now()->subMinutes(10));
        $probe = $this->fakeProbe(QueueWorkerObservation::running(CarbonImmutable::now()->subMinutes(5)));

        $result = (new QueueWorkerFreshnessCheck($probe, $this->envPath))->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    public function test_it_fails_when_the_worker_started_before_the_env_write(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
        $envWrittenAt = CarbonImmutable::now()->subMinutes(5);
        $startedAt = CarbonImmutable::now()->subMinutes(10);
        $this->touchEnvAt($envWrittenAt);
        $probe = $this->fakeProbe(QueueWorkerObservation::running($startedAt));

        $result = (new QueueWorkerFreshnessCheck($probe, $this->envPath))->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('queue:restart', $result->remediation);
        // Both halves of the detail must render in the same (app) timezone,
        // or a worker genuinely older than `.env` can read as the reverse.
        $this->assertStringContainsString("worker started {$startedAt->toDateTimeString()}", $result->detail);
        $this->assertStringContainsString("\".env\" last written {$envWrittenAt->toDateTimeString()}", $result->detail);
    }

    public function test_a_same_second_tie_is_reported_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
        $tiedAt = CarbonImmutable::now()->subMinutes(5);
        $this->touchEnvAt($tiedAt);
        $probe = $this->fakeProbe(QueueWorkerObservation::running($tiedAt));

        $result = (new QueueWorkerFreshnessCheck($probe, $this->envPath))->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
    }

    public function test_a_restart_broadcast_after_the_env_write_clears_the_stale_condition(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-01-01 12:00:00'));
        $this->touchEnvAt(CarbonImmutable::now()->subMinutes(10));
        // The worker process itself is older than the .env write, but a
        // queue:restart broadcast after the write means it is answering
        // with current configuration regardless.
        Cache::put('illuminate:queue:restart', CarbonImmutable::now()->subMinutes(2)->getTimestamp());
        $probe = $this->fakeProbe(QueueWorkerObservation::running(CarbonImmutable::now()->subMinutes(20)));

        $result = (new QueueWorkerFreshnessCheck($probe, $this->envPath))->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    public function test_an_undetermined_worker_state_fails_and_names_it_could_not_determine(): void
    {
        $this->touchEnvAt(CarbonImmutable::now());
        $probe = $this->fakeProbe(QueueWorkerObservation::undetermined('`ps` did not return a usable process list'));

        $result = (new QueueWorkerFreshnessCheck($probe, $this->envPath))->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('could not determine', $result->detail);
    }

    public function test_no_worker_running_passes_without_repeating_the_queue_connection_checks_wording(): void
    {
        $this->touchEnvAt(CarbonImmutable::now());
        $probe = $this->fakeProbe(QueueWorkerObservation::notRunning());

        $result = (new QueueWorkerFreshnessCheck($probe, $this->envPath))->run();

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertStringNotContainsString('reachable', $result->detail);
    }

    public function test_a_missing_env_file_fails_as_could_not_determine(): void
    {
        $probe = $this->fakeProbe(QueueWorkerObservation::notRunning());
        $missingPath = sys_get_temp_dir().'/kbms-doctor-env-missing-'.uniqid();

        $result = (new QueueWorkerFreshnessCheck($probe, $missingPath))->run();

        $this->assertSame(CheckStatus::Failed, $result->status);
        $this->assertStringContainsString('could not determine', $result->detail);
    }
}
