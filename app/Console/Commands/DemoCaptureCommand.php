<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Demo\SnapshotConfig;
use App\Support\Demo\TableCapture;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

final class DemoCaptureCommand extends Command
{
    protected $signature = 'demo:capture {--out=database/demo/snapshot.yaml : Output file path}';

    protected $description = 'Capture la base de données courante dans un snapshot YAML pour relecture par demo:reset';

    public function handle(): int
    {
        // Guard 1: refuse to run in production
        if (app()->environment('production')) {
            $this->error('demo:capture refuse de tourner en production');

            return self::FAILURE;
        }

        // Guard 2: exactly one association required
        $associationCount = DB::table('association')->count();
        if ($associationCount !== 1) {
            $this->error("demo:capture exige une seule association ({$associationCount} trouvées)");

            return self::FAILURE;
        }

        $capturedAt = Carbon::now();
        $outPath = (string) $this->option('out');

        // Ensure output directory exists
        $outDir = dirname($outPath);
        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        // Collect all non-excluded tables
        $tables = $this->listTables();
        $excludedTables = SnapshotConfig::EXCLUDED_TABLES;
        $tables = array_filter($tables, fn (string $t) => ! in_array($t, $excludedTables, true));
        sort($tables); // alphabetical order

        $tableCapture = new TableCapture($capturedAt);
        $tablesData = [];

        foreach ($tables as $tableName) {
            try {
                $rows = $tableCapture->capture($tableName);
                $tablesData[$tableName] = $rows;
            } catch (\Throwable $e) {
                $this->warn("Skipping table {$tableName}: {$e->getMessage()}");
            }
        }

        // Build final snapshot structure
        $snapshot = [
            'captured_at' => $capturedAt->toIso8601String(),
            'files' => [],
            'schema_version' => SnapshotConfig::SCHEMA_VERSION,
            'tables' => $tablesData,
        ];

        // Sort top-level keys
        ksort($snapshot);

        // Dump to YAML
        $yaml = Yaml::dump(
            $snapshot,
            8,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
        );

        $written = file_put_contents($outPath, $yaml);
        if ($written === false) {
            $this->error("Failed to write snapshot to: {$outPath}");

            return self::FAILURE;
        }

        // Summary log
        $totalRows = array_sum(array_map('count', $tablesData));
        $this->info("Snapshot written to: {$outPath}");
        $this->info("captured_at: {$capturedAt->toIso8601String()}");
        $this->info('Tables captured: '.count($tablesData).", total rows: {$totalRows}");

        foreach ($tablesData as $name => $rows) {
            $this->line("  - {$name}: ".count($rows).' row(s)');
        }

        return self::SUCCESS;
    }

    /**
     * Return all table names in the current database.
     *
     * Supports SQLite (for tests) and MySQL (for production/demo).
     *
     * @return list<string>
     */
    private function listTables(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

            return array_map(fn (object $r) => $r->name, $rows);
        }

        // MySQL / MariaDB
        $rows = DB::select('SHOW TABLES');

        return array_map(fn (object $r) => array_values((array) $r)[0], $rows);
    }
}
