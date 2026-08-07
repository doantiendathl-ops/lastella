<?php

namespace Tests\Support\Concurrency;

/**
 * Room Demand/Room Board Unification M5 — spawns and coordinates the exact
 * number of independent OS worker processes a concurrency test needs (2 for
 * every race case in this milestone — never more), each running
 * run_worker.php against the disposable MySQL database.
 *
 * Uses raw proc_open() — no new Composer dependency, no Artisan command, no
 * production code path involved. Test-support code only.
 */
final class WorkerProcessRunner
{
    private readonly string $barrierDir;

    private readonly string $tmpDir;

    public function __construct(
        private readonly string $caseName,
        private readonly string $database,
    ) {
        $this->tmpDir = sys_get_temp_dir() . '/lastella_concurrency';

        if (! is_dir($this->tmpDir)) {
            mkdir($this->tmpDir, 0777, true);
        }

        $this->barrierDir = Barrier::freshDir($this->tmpDir, $caseName);
    }

    /**
     * @param  array<int, array{name: string, action: string, params: array}>  $workers  exactly the workers for this race (2, except double-submit cases which reuse the same params twice).
     * @return array<int, array>  one decoded result array per worker, in the same order as $workers.
     */
    public function run(array $workers, int $timeoutSeconds = 15): array
    {
        $totalWorkers = count($workers);
        $processes = [];
        $paramsFiles = [];
        $resultFiles = [];

        foreach ($workers as $worker) {
            $paramsFile = $this->barrierDir . '/' . $worker['name'] . '.params.json';
            $resultFile = $this->barrierDir . '/' . $worker['name'] . '.result.json';
            file_put_contents($paramsFile, json_encode($worker['params']));

            $paramsFiles[] = $paramsFile;
            $resultFiles[] = $resultFile;

            $cmd = [
                PHP_BINARY,
                __DIR__ . '/run_worker.php',
                $worker['name'],
                $this->barrierDir,
                (string) $totalWorkers,
                $worker['action'],
                $paramsFile,
                $resultFile,
            ];

            // proc_open()'s $env only accepts scalar values — $_SERVER/$_ENV can
            // contain array entries (e.g. some SAPI argv/argc shapes) that must
            // be filtered out first, or proc_open throws "Array to string conversion".
            //
            // PHPUnit's own phpunit.xml <env> block (DB_CONNECTION=sqlite,
            // DB_DATABASE=:memory:, CACHE_STORE=array, QUEUE_CONNECTION=sync) is
            // already present in THIS (parent) process's $_ENV/$_SERVER — those
            // must NOT be forwarded to the worker child, or the child's own
            // env('DB_CONNECTION') resolves to sqlite instead of the real .env's
            // mysql value, before ConcurrencyWorkerBootstrap even gets a chance
            // to repoint the database name.
            $phpunitEnvKeys = ['DB_CONNECTION', 'DB_DATABASE', 'CACHE_STORE', 'QUEUE_CONNECTION'];

            $scalarEnv = array_filter(
                array_merge($_ENV, $_SERVER),
                static fn ($value, $key): bool => is_scalar($value) && ! in_array($key, $phpunitEnvKeys, true),
                ARRAY_FILTER_USE_BOTH,
            );

            $env = array_merge($scalarEnv, [
                'CONCURRENCY_DB_DATABASE' => $this->database,
            ]);

            $processes[] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $env);
            $processes[count($processes) - 1] = ['proc' => end($processes), 'pipes' => $pipes];
        }

        // Wait for every process to exit (bounded by $timeoutSeconds total).
        $deadline = microtime(true) + $timeoutSeconds;
        $exitCodes = [];
        $stdouts = [];
        $stderrs = [];

        foreach ($processes as $i => $entry) {
            $proc = $entry['proc'];
            $stdouts[$i] = stream_get_contents($entry['pipes'][1]);
            $stderrs[$i] = stream_get_contents($entry['pipes'][2]);
            fclose($entry['pipes'][1]);
            fclose($entry['pipes'][2]);
            $exitCodes[$i] = proc_close($proc);

            if (! file_exists($resultFiles[$i])) {
                throw new \RuntimeException(
                    "Worker {$workers[$i]['name']} did not write a result file (exit {$exitCodes[$i]}).\n"
                    . "stdout: {$stdouts[$i]}\nstderr: {$stderrs[$i]}",
                );
            }

            if (microtime(true) > $deadline) {
                throw new \RuntimeException("Concurrency case '{$this->caseName}' exceeded {$timeoutSeconds}s.");
            }
        }

        $results = [];

        foreach ($resultFiles as $resultFile) {
            $results[] = json_decode(file_get_contents($resultFile), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    public function cleanup(): void
    {
        Barrier::cleanup($this->barrierDir);
    }
}
