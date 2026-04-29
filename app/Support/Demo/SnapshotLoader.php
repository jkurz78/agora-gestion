<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Loads a parsed YAML snapshot into the database.
 *
 * Responsibilities:
 * - Rehydrate delta strings (e.g. '-13d') back to absolute datetimes using DateDelta.
 * - Bulk-insert rows per table in dependency order (parents before pivot tables).
 * - Temporarily disable FK enforcement where possible.
 * - Adjust MySQL AUTO_INCREMENT after each table insertion.
 */
final class SnapshotLoader
{
    /** Regex matching a delta token produced by DateDelta::toDelta(). */
    public const DELTA_PATTERN = '/^[+-]?\d+[dMy]$/';

    /** Number of rows per DB bulk-insert chunk. */
    private const CHUNK_SIZE = 200;

    /**
     * Tables that must be inserted before all others (in order), to satisfy FK constraints
     * even when FK enforcement cannot be disabled (e.g. SQLite inside a transaction).
     */
    private const FK_PRIORITY_ORDER = ['association', 'users', 'association_user'];

    /**
     * Bulk-insert all tables from the snapshot data.
     *
     * Returns [tablesInserted, rowsPerTable].
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return array{int, array<string, int>}
     */
    public function load(array $tables): array
    {
        $driver = DB::connection()->getDriverName();
        $rowsPerTable = [];

        $tables = $this->sortByDependencyOrder($tables);

        $this->disableForeignKeys($driver);

        try {
            // First pass: clear all snapshot tables (idempotency).
            // Backfill migrations may have pre-seeded rows (e.g. multi-tenant default
            // association at id=1) which would collide with the snapshot's PKs.
            // FKs are disabled above, so deletion order does not matter.
            foreach ($tables as $tableName => $rows) {
                if (is_array($rows) && $rows !== []) {
                    DB::table($tableName)->delete();
                }
            }

            foreach ($tables as $tableName => $rows) {
                if (! is_array($rows) || $rows === []) {
                    $rowsPerTable[$tableName] = 0;

                    continue;
                }

                $rehydrated = array_map(
                    fn (array $row) => $this->rehydrateRow($row, $tableName),
                    $rows
                );

                foreach (array_chunk($rehydrated, self::CHUNK_SIZE) as $chunk) {
                    DB::table($tableName)->insert($chunk);
                }

                $rowsPerTable[$tableName] = count($rehydrated);

                if ($driver === 'mysql' || $driver === 'mariadb') {
                    $this->adjustAutoIncrement($tableName, $rehydrated);
                }
            }
        } finally {
            $this->enableForeignKeys($driver);
        }

        return [count($rowsPerTable), $rowsPerTable];
    }

    /**
     * Walk all values of a row: rehydrate delta strings to absolute datetimes,
     * then re-encrypt any columns that were stored as plaintext in the snapshot.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function rehydrateRow(array $row, string $tableName = ''): array
    {
        $now = Carbon::now();

        foreach ($row as $key => $value) {
            if (is_string($value) && preg_match(self::DELTA_PATTERN, $value)) {
                $carbon = DateDelta::fromDelta($value, $now);
                // Use datetime format — MySQL/SQLite accept Y-m-d H:i:s
                $row[$key] = $carbon->format('Y-m-d H:i:s');
            }
        }

        // Re-encrypt plaintext values that were decrypted at capture time.
        // This uses the current APP_KEY (the demo server's key), producing
        // valid ciphertext for the target environment.
        if ($tableName !== '') {
            $encryptedColumns = EncryptedColumnsRegistry::forTable($tableName);

            foreach ($encryptedColumns as $col) {
                if (array_key_exists($col, $row) && $row[$col] !== null) {
                    $row[$col] = Crypt::encryptString((string) $row[$col]);
                }
            }
        }

        return $row;
    }

    /**
     * Sort table names so that known FK parents come before pivot/child tables.
     *
     * Guarantees correct insertion order even when FK enforcement is active
     * (e.g. SQLite inside a RefreshDatabase transaction where PRAGMA
     * foreign_keys cannot be toggled off at runtime).
     *
     * @param  array<string, list<array<string, mixed>>>  $tables
     * @return array<string, list<array<string, mixed>>>
     */
    private function sortByDependencyOrder(array $tables): array
    {
        $ordered = [];

        foreach (self::FK_PRIORITY_ORDER as $name) {
            if (isset($tables[$name])) {
                $ordered[$name] = $tables[$name];
            }
        }

        foreach ($tables as $name => $rows) {
            if (! isset($ordered[$name])) {
                $ordered[$name] = $rows;
            }
        }

        return $ordered;
    }

    private function disableForeignKeys(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
    }

    private function enableForeignKeys(string $driver): void
    {
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * After bulk-inserting into a MySQL table, set AUTO_INCREMENT to max(id)+1.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function adjustAutoIncrement(string $tableName, array $rows): void
    {
        $maxId = 0;

        foreach ($rows as $row) {
            if (isset($row['id']) && is_numeric($row['id'])) {
                $id = (int) $row['id'];
                if ($id > $maxId) {
                    $maxId = $id;
                }
            }
        }

        if ($maxId > 0) {
            $next = $maxId + 1;
            DB::statement("ALTER TABLE `{$tableName}` AUTO_INCREMENT = {$next}");
        }
    }
}
