<?php

namespace App\Doctor;

final class CheckSuite
{
    /**
     * @param  list<EnvironmentCheck>  $checks
     */
    public function __construct(private array $checks) {}

    /**
     * @return array<string, CheckResult>
     */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $results[$check->label()] = $check->run();
            } catch (\Throwable $e) {
                $results[$check->label()] = CheckResult::failed($e->getMessage(), 'the check raised an unexpected error — see the detail above');
            }
        }

        return $results;
    }

    /**
     * @param  array<string, CheckResult>  $results
     */
    public function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if (! $result->status->isPassing()) {
                return true;
            }
        }

        return false;
    }
}
