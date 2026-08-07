<?php

namespace Tests\Support\Concurrency;

/**
 * Room Demand/Room Board Unification M5 — deterministic two-worker
 * synchronization barrier for real-process concurrency tests.
 *
 * Two independent OS processes (spawned by WorkerProcessRunner) each call
 * wait() with their own worker name. Neither proceeds past wait() until BOTH
 * have arrived — implemented via a marker file per worker plus a tight poll
 * loop, never a fixed sleep(). This is test-support code only: it is never
 * required by, imported into, or reachable from any `app/` file, route, or
 * controller.
 */
final class Barrier
{
    public function __construct(private readonly string $dir)
    {
        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    /**
     * Blocks until $totalWorkers distinct worker names have called wait()
     * on this same barrier directory, then returns almost simultaneously
     * for all of them. Throws if the timeout is reached (a worker crashed
     * or the barrier directory is wrong) rather than hanging forever.
     */
    public function wait(string $workerName, int $totalWorkers, int $timeoutSeconds = 10): void
    {
        file_put_contents($this->dir . '/' . $workerName . '.ready', '1');

        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $readyCount = count(glob($this->dir . '/*.ready') ?: []);

            if ($readyCount >= $totalWorkers) {
                return;
            }

            usleep(2_000); // 2ms poll — tight enough for a deterministic near-simultaneous release, no long sleeps.
        }

        throw new \RuntimeException("Barrier timeout waiting for {$totalWorkers} workers in {$this->dir} (worker '{$workerName}' saw only partial arrival).");
    }

    public static function freshDir(string $baseDir, string $caseName): string
    {
        $dir = rtrim($baseDir, '/\\') . '/' . $caseName . '_' . bin2hex(random_bytes(4));

        if (is_dir($dir)) {
            self::cleanup($dir);
        }

        mkdir($dir, 0777, true);

        return $dir;
    }

    public static function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
