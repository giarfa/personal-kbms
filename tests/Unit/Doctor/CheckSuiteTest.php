<?php

namespace Tests\Unit\Doctor;

use App\Doctor\CheckResult;
use App\Doctor\CheckStatus;
use App\Doctor\CheckSuite;
use App\Doctor\EnvironmentCheck;
use PHPUnit\Framework\TestCase;

class CheckSuiteTest extends TestCase
{
    public function test_a_throwing_check_yields_a_failed_result_and_the_remaining_checks_still_run(): void
    {
        $throwing = new class implements EnvironmentCheck
        {
            public function label(): string
            {
                return 'Throwing check';
            }

            public function run(): CheckResult
            {
                throw new \RuntimeException('boom');
            }
        };

        $passing = new class implements EnvironmentCheck
        {
            public function label(): string
            {
                return 'Passing check';
            }

            public function run(): CheckResult
            {
                return CheckResult::pass('all good');
            }
        };

        $suite = new CheckSuite([$throwing, $passing]);

        $results = $suite->run();

        $this->assertSame(CheckStatus::Failed, $results['Throwing check']->status);
        $this->assertSame('boom', $results['Throwing check']->detail);
        $this->assertSame(CheckStatus::Pass, $results['Passing check']->status);
    }

    public function test_has_failures_is_true_when_any_result_is_not_passing(): void
    {
        $suite = new CheckSuite([]);

        $this->assertTrue($suite->hasFailures([
            'Check A' => CheckResult::pass('ok'),
            'Check B' => CheckResult::notConfigured('unset', 'set KBMS_EXAMPLE'),
        ]));

        $this->assertFalse($suite->hasFailures([
            'Check A' => CheckResult::pass('ok'),
        ]));
    }
}
