<?php

namespace App\Doctor;

interface EnvironmentCheck
{
    public function label(): string;

    public function run(): CheckResult;
}
