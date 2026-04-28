<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Demo;
use App\Support\Demo\SnapshotLoader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset
        {--snapshot=database/demo/snapshot.yaml : Path to the YAML snapshot file}
        {--skip-migrate : Skip migrate:fresh (for testing only)}';

    protected $description = 'Reset the demo database from a YAML snapshot (rehydrates dates, restores files)';

    public function handle(): int
    {
        // Guard: refuse outside demo environment
        if (! Demo::isActive()) {
            $this->error(
                'demo:reset refuse de tourner hors environnement démo (env actuel: '.app()->environment().')'
            );

            return self::FAILURE;
        }

        $snapshotPath = (string) $this->option('snapshot');

        // Validate file existence before going into maintenance mode
        if (! file_exists($snapshotPath)) {
            $this->error("Snapshot introuvable : {$snapshotPath}");

            try {
                Artisan::call('down', ['--retry' => 60]);
            } finally {
                Artisan::call('up');
            }

            return self::FAILURE;
        }

        // Parse YAML early so we can report a clear error before wiping the DB.
        try {
            $snapshot = Yaml::parseFile($snapshotPath);
        } catch (ParseException $e) {
            $this->error('Snapshot YAML invalide : '.$e->getMessage());

            try {
                Artisan::call('down', ['--retry' => 60]);
            } finally {
                Artisan::call('up');
            }

            return self::FAILURE;
        }

        if (! is_array($snapshot) || ! isset($snapshot['tables']) || ! is_array($snapshot['tables'])) {
            $this->error('Snapshot YAML invalide : clé "tables" absente ou malformée.');

            try {
                Artisan::call('down', ['--retry' => 60]);
            } finally {
                Artisan::call('up');
            }

            return self::FAILURE;
        }

        $skipMigrate = (bool) $this->option('skip-migrate');
        $startTime = microtime(true);
        $tablesInserted = 0;
        $rowsPerTable = [];
        $filesCopied = 0;

        try {
            Artisan::call('down', ['--retry' => 60]);

            if (! $skipMigrate) {
                Artisan::call('migrate:fresh', ['--force' => true]);
            }

            $loader = new SnapshotLoader;
            [$tablesInserted, $rowsPerTable] = $loader->load($snapshot['tables']);

            // Restore files (section may be absent or empty)
            $filesEntries = $snapshot['files'] ?? [];
            if (is_array($filesEntries) && $filesEntries !== []) {
                $filesCopied = $this->syncStorage($filesEntries);
            }
        } finally {
            Artisan::call('up');
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        $totalRows = array_sum($rowsPerTable);

        $this->info("Snapshot rechargé depuis : {$snapshotPath}");
        $this->info("Tables rejouées : {$tablesInserted}, lignes totales : {$totalRows}, fichiers copiés : {$filesCopied}, durée : {$elapsed}s");

        foreach ($rowsPerTable as $table => $count) {
            $this->line("  - {$table}: {$count} ligne(s)");
        }

        return self::SUCCESS;
    }

    /**
     * Copy snapshot files to their target locations.
     *
     * Each entry: ['source' => 'database/demo/files/...', 'target' => 'storage/app/...']
     *
     * @param  list<array{source: string, target: string}>  $entries
     */
    private function syncStorage(array $entries): int
    {
        $copied = 0;

        foreach ($entries as $entry) {
            if (! isset($entry['source'], $entry['target'])) {
                $this->warn('Entrée fichier invalide (source/target manquant), ignorée.');

                continue;
            }

            $source = base_path($entry['source']);
            $target = base_path($entry['target']);

            if (! file_exists($source)) {
                $this->warn("Fichier source introuvable : {$source}");

                continue;
            }

            $targetDir = dirname($target);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (copy($source, $target)) {
                $copied++;
            } else {
                $this->warn("Échec de la copie : {$source} → {$target}");
            }
        }

        return $copied;
    }
}
