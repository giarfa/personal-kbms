<?php

namespace App\Doctor\Worker;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Answers `QueueWorkerProbe::observe()` by shelling out to `ps` (candidate
 * `queue:work` processes and their elapsed run time) and `lsof` (each
 * candidate's working directory, to keep only workers serving this
 * project). Both commands run as argument arrays through the Process
 * facade — never a shell string, and nothing interpolated — the same
 * discipline as `ClaudeSessionLauncher`. Herd/macOS is load-bearing for
 * this product, so inspecting the running worker process this way is
 * acceptable (see US-016).
 */
final class PsQueueWorkerProbe implements QueueWorkerProbe
{
    public function observe(): QueueWorkerObservation
    {
        $processes = $this->listQueueWorkerProcesses();

        if ($processes === null) {
            return QueueWorkerObservation::undetermined('`ps` did not return a usable process list');
        }

        if ($processes === []) {
            return QueueWorkerObservation::notRunning();
        }

        $projectRoot = base_path();
        $startedAts = [];

        foreach ($processes as $process) {
            $cwd = $this->workingDirectoryFor($process['pid']);

            if ($cwd === null) {
                return QueueWorkerObservation::undetermined('`lsof` could not resolve the working directory of a candidate queue worker');
            }

            if ($cwd !== $projectRoot) {
                continue;
            }

            $elapsedSeconds = $this->parseElapsedSeconds($process['etime']);

            if ($elapsedSeconds === null) {
                return QueueWorkerObservation::undetermined("could not parse \`ps\` elapsed time \"{$process['etime']}\"");
            }

            $startedAts[] = CarbonImmutable::now()->subSeconds($elapsedSeconds);
        }

        if ($startedAts === []) {
            return QueueWorkerObservation::notRunning();
        }

        // Several workers matched this project: the oldest start time is
        // the most conservative answer for a freshness check built on top.
        usort($startedAts, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);

        return QueueWorkerObservation::running($startedAts[0]);
    }

    /**
     * @return list<array{pid: int, etime: string, command: string}>|null null when `ps` itself could not be trusted
     */
    private function listQueueWorkerProcesses(): ?array
    {
        try {
            $result = Process::run(['ps', '-axo', 'pid=,etime=,command=']);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful() || trim($result->output()) === '') {
            return null;
        }

        $processes = [];

        foreach (explode("\n", $result->output()) as $line) {
            $parsed = $this->parseProcessLine($line);

            if ($parsed !== null && str_contains($parsed['command'], 'artisan queue:work')) {
                $processes[] = $parsed;
            }
        }

        return $processes;
    }

    /**
     * @return array{pid: int, etime: string, command: string}|null
     */
    private function parseProcessLine(string $line): ?array
    {
        $line = trim($line);

        if ($line === '' || ! preg_match('/^(\d+)\s+(\S+)\s+(.*)$/', $line, $matches)) {
            return null;
        }

        return ['pid' => (int) $matches[1], 'etime' => $matches[2], 'command' => $matches[3]];
    }

    /**
     * @return string|null the resolved cwd, or null when `lsof` could not answer (missing binary, non-zero exit, or no `cwd` line)
     */
    private function workingDirectoryFor(int $pid): ?string
    {
        try {
            $result = Process::run(['lsof', '-a', '-p', (string) $pid, '-d', 'cwd', '-Fn']);
        } catch (Throwable) {
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        foreach (explode("\n", $result->output()) as $line) {
            if (str_starts_with($line, 'n')) {
                return substr($line, 1);
            }
        }

        return null;
    }

    /**
     * Parses `ps`'s `[[dd-]hh:]mm:ss` elapsed-time format — deliberately
     * not `lstart`, whose format is locale-dependent.
     */
    private function parseElapsedSeconds(string $etime): ?int
    {
        $days = 0;

        if (str_contains($etime, '-')) {
            [$dayPart, $etime] = explode('-', $etime, 2);

            if (! ctype_digit($dayPart)) {
                return null;
            }

            $days = (int) $dayPart;
        }

        $parts = explode(':', $etime);

        if (count($parts) === 2) {
            [$hours, $minutes, $seconds] = ['0', ...$parts];
        } elseif (count($parts) === 3) {
            [$hours, $minutes, $seconds] = $parts;
        } else {
            return null;
        }

        foreach ([$hours, $minutes, $seconds] as $part) {
            if (! ctype_digit($part)) {
                return null;
            }
        }

        return ($days * 86400) + ((int) $hours * 3600) + ((int) $minutes * 60) + (int) $seconds;
    }
}
