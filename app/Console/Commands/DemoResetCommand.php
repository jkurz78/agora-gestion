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

    protected $description = 'Réinitialise la base démo depuis un snapshot YAML (réhydrate les dates, restaure les fichiers)';

    public function handle(): int
    {
        // Guard: refuse outside demo environment
        if (! Demo::isActive()) {
            $this->error(
                'demo:reset refuse de tourner hors environnement démo (env actuel: '.app()->environment().')'
            );

            return self::FAILURE;
        }

        // Guard: refuse if APP_URL does not start with https://demo.
        // This prevents accidental execution in a mis-configured env where
        // APP_ENV=demo has been set but the URL points to a non-demo instance.
        if (! str_starts_with((string) config('app.url'), 'https://demo.')) {
            $this->error(
                'demo:reset refuse de tourner — env='.app()->environment().', url='.config('app.url')
            );

            return self::FAILURE;
        }

        $snapshotPath = (string) $this->option('snapshot');

        // Guard: --snapshot must resolve inside database/demo/
        $snapshotAbs = realpath($snapshotPath);
        $snapshotRoot = realpath(base_path('database/demo'));

        if ($snapshotAbs === false || $snapshotRoot === false || ! str_starts_with($snapshotAbs, $snapshotRoot.DIRECTORY_SEPARATOR)) {
            $this->error("--snapshot doit pointer dans database/demo/ (reçu: {$snapshotPath})");

            return self::FAILURE;
        }

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
            $snapshot = Yaml::parseFile($snapshotPath, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
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

        $sourceRoot = realpath(base_path('database/demo/files'));
        $targetRoot = realpath(base_path('storage/app'));

        foreach ($entries as $entry) {
            if (! isset($entry['source'], $entry['target'])) {
                $this->warn('Entrée fichier invalide (source/target manquant), ignorée.');

                continue;
            }

            // Validate source: must resolve inside database/demo/files/
            $sourceAbs = realpath(base_path($entry['source']));
            if ($sourceRoot === false || $sourceAbs === false || ! str_starts_with($sourceAbs, $sourceRoot.DIRECTORY_SEPARATOR)) {
                $this->warn('Skipping snapshot file (source hors database/demo/files): '.($entry['source'] ?? '?'));

                continue;
            }

            // Validate target: must resolve inside storage/app/ (target may not exist yet — use base_path only)
            $targetAbs = base_path($entry['target']);
            if ($targetRoot === false || ! str_starts_with($targetAbs, $targetRoot.DIRECTORY_SEPARATOR)) {
                $this->warn('Skipping snapshot file (target hors storage/app): '.($entry['target'] ?? '?'));

                continue;
            }

            if (! file_exists($sourceAbs)) {
                $this->warn("Fichier source introuvable : {$sourceAbs}");

                continue;
            }

            $targetDir = dirname($targetAbs);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            if (copy($sourceAbs, $targetAbs)) {
                $copied++;
            } else {
                $this->warn("Échec de la copie : {$sourceAbs} → {$targetAbs}");
            }
        }

        return $copied;
    }
}
