<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Illuminate\Support\Facades\Log;

/**
 * Resolves a file-path column in a captured DB row to the absolute filesystem
 * path where the source file lives on disk.
 *
 * Used by DemoCaptureCommand to locate files to copy into database/demo/files/.
 */
final class FilePathResolver
{
    /**
     * @param  string  $tableName  DB table name (e.g. 'association')
     * @param  string  $column  Column name (e.g. 'logo_path')
     * @param  string  $template  Path template with {value}, {association_id}, {id} placeholders.
     *                            Path is relative to the project root (e.g. 'storage/app/associations/...').
     */
    public function __construct(
        private readonly string $tableName,
        private readonly string $column,
        private readonly string $template,
    ) {}

    /**
     * Resolve the physical source path for a given captured row.
     *
     * Returns the absolute path if the file exists, null otherwise.
     * Logs a warning when the column is set but the file cannot be found.
     *
     * @param  array<string, mixed>  $row  Row as captured by TableCapture (values may be delta strings for datetimes).
     */
    public function resolve(array $row): ?string
    {
        $value = $row[$this->column] ?? null;

        if (empty($value) || ! is_string($value)) {
            return null;
        }

        // Only the basename is meaningful — guard against path traversal in stored values.
        $basename = basename($value);
        if ($basename === '' || $basename === '.') {
            return null;
        }

        // Resolve {association_id}: for the 'association' table itself, use 'id'.
        // For tenant-scoped tables, use 'association_id'.
        $associationId = $this->tableName === 'association'
            ? ($row['id'] ?? null)
            : ($row['association_id'] ?? null);

        $rowId = $row['id'] ?? null;

        // All placeholders must be available to build a valid path.
        if ($associationId === null) {
            Log::warning('demo:capture — association_id manquant', [
                'table' => $this->tableName,
                'column' => $this->column,
            ]);

            return null;
        }

        $relative = str_replace(
            ['{value}', '{association_id}', '{id}'],
            [(string) $basename, (string) $associationId, (string) $rowId],
            $this->template,
        );

        $absolute = base_path($relative);

        if (! file_exists($absolute)) {
            Log::warning('demo:capture — fichier path introuvable (skipped)', [
                'table' => $this->tableName,
                'column' => $this->column,
                'path' => $absolute,
            ]);

            return null;
        }

        return $absolute;
    }

    /**
     * Return the sub-path inside database/demo/files/ where this file should
     * be copied, preserving a human-readable directory structure.
     *
     * Example for association.logo_path with assocId=1:
     *   → "branding/1/logo.png"
     *
     * @param  array<string, mixed>  $row
     */
    public function destSubPath(array $row): string
    {
        $value = $row[$this->column] ?? '';
        $basename = basename((string) $value);

        $associationId = $this->tableName === 'association'
            ? ($row['id'] ?? '0')
            : ($row['association_id'] ?? '0');

        $rowId = $row['id'] ?? '0';

        // Build a logical sub-path that mirrors the storage structure but
        // strips the 'storage/app/associations/{id}/' prefix, replacing it
        // with a short human-readable prefix.
        //
        // For 'association' branding: branding/{assocId}/{basename}
        // For 'type_operations': type-operations/{assocId}/{rowId}/{basename}
        return match ($this->tableName) {
            'association' => "branding/{$associationId}/{$basename}",
            'type_operations' => "type-operations/{$associationId}/{$rowId}/{$basename}",
            default => "{$this->tableName}/{$associationId}/{$rowId}/{$basename}",
        };
    }

    /**
     * Return the storage target path (relative to project root) where
     * demo:reset should restore the file.
     *
     * This mirrors the physical source path pattern.
     *
     * @param  array<string, mixed>  $row
     */
    public function targetStoragePath(array $row): string
    {
        $value = $row[$this->column] ?? '';
        $basename = basename((string) $value);

        $associationId = $this->tableName === 'association'
            ? ($row['id'] ?? '0')
            : ($row['association_id'] ?? '0');

        $rowId = $row['id'] ?? '0';

        return str_replace(
            ['{value}', '{association_id}', '{id}'],
            [(string) $basename, (string) $associationId, (string) $rowId],
            $this->template,
        );
    }
}
