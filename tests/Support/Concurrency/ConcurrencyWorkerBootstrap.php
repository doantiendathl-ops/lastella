<?php

namespace Tests\Support\Concurrency;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Room Demand/Room Board Unification M5 — boots a real, standalone Laravel
 * application instance for a single concurrency worker process (run_worker.php).
 *
 * This is deliberately NOT the PHPUnit TestCase bootstrap (that runs inside
 * one shared process against SQLite :memory:, per phpunit.xml — unusable for
 * real concurrency). Each worker is its own OS process with its own real
 * MySQL connection, pointed at the disposable database named by the
 * CONCURRENCY_DB_DATABASE environment variable — passed only to this
 * process's environment, never written to .env or .env.testing.
 */
final class ConcurrencyWorkerBootstrap
{
    public static function boot(): Application
    {
        $basePath = dirname(__DIR__, 3);

        require $basePath . '/vendor/autoload.php';

        /** @var Application $app */
        $app = require $basePath . '/bootstrap/app.php';

        $dbDatabase = getenv('CONCURRENCY_DB_DATABASE');

        if ($dbDatabase === false || $dbDatabase === '') {
            throw new \RuntimeException('CONCURRENCY_DB_DATABASE must be set for a concurrency worker process — refusing to run against the default connection.');
        }

        // Force the mysql connection's database name for THIS PROCESS ONLY,
        // via config(), before the kernel boots any service that resolves a
        // DB connection. No .env file is read or written.
        $app->make(Kernel::class)->bootstrap();

        // Defensive: even though WorkerProcessRunner strips PHPUnit's
        // DB_CONNECTION=sqlite override before spawning this process, force
        // both the default connection and the mysql database name explicitly
        // here too, so this worker can never silently fall back to sqlite.
        config(['database.default' => 'mysql']);
        config(['database.connections.mysql.database' => $dbDatabase]);
        \Illuminate\Support\Facades\DB::purge('mysql');
        \Illuminate\Support\Facades\DB::setDefaultConnection('mysql');

        return $app;
    }
}
