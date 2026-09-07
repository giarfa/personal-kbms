<?php

namespace App\Console\Commands;

use App\Doctor\CheckStatus;
use App\Doctor\CheckSuite;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kbms:doctor')]
#[Description('Check every environment assumption this product makes about the machine')]
class DoctorCommand extends Command
{
    public function __construct(private CheckSuite $suite)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $results = $this->suite->run();

        $passed = 0;
        $failed = 0;

        foreach ($results as $label => $result) {
            $badge = match ($result->status) {
                CheckStatus::Pass => 'PASS',
                CheckStatus::NotConfigured => 'NOT CONFIGURED',
                CheckStatus::Failed => 'FAIL',
            };

            $this->components->twoColumnDetail($label, $badge);

            if ($result->status->isPassing()) {
                $passed++;

                continue;
            }

            $failed++;

            $this->components->bulletList([$result->detail]);

            if ($result->remediation !== null) {
                $this->components->bulletList([$result->remediation]);
            }
        }

        $this->newLine();
        $this->line("  {$passed} passed, {$failed} failed");

        return $this->suite->hasFailures($results) ? self::FAILURE : self::SUCCESS;
    }
}
