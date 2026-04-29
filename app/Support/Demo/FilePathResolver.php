<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Illuminate\Support\Facades\Log;

/**
 * Resolves a file-path column in a captured DB row to the absolute filesystem
 * path where the source file lives on disk.
 *
 * Used by DemoCaptureCommand to locate files to copy into database/demo/files/.
 *
 * Two resolution modes are selected automatically based on the template:
 *
 *  - {value} mode (basename)  : template contains {value} — uses basename($column_value).
 *                               Suitable for columns that store only the filename (e.g. 'logo.png').
 *
 *  - {path} mode (full path)  : template contains {path} — uses the full column value as-is,
 *                               with strict anti-traversal validation:
 *                                 • empty string → rejected
 *                                 • contains '..' → rejected (path traversal)
 *                                 • starts with '/' → rejected (absolute path)
 *                               Suitable for columns that store a full relative path
 *                               (e.g. 'associations/1/notes-de-frais/1/ligne-1.pdf').
 *
 * The guard "association_id missing" only applies when the template references
 * {association_id}. Full-path templates that use only {path} do not require the
 * row to carry association_id.
 */
final class FilePathResolver
{
    /**
     * @param  string  $tableName  DB table name (e.g. 'association')
     * @param  string  $column  Column name (e.g. 'logo_path')
     * @param  string  $template  Path template with placeholders.
     *                            Path is relative to the project root (e.g. 'storage/app/private/...').
     *
     * Supported placeholders:
     *   {value}          → basename of the column value (basename mode)
     *   {path}           → full column value after sanitization (full-path mode)
     *   {association_id} → row.association_id, or row.id for the 'association' table
     *   {id}             → row.id of the current row
     */
    public function __construct(
        private readonly string $tableName,
        private readonly string $column,
        private readonly string $template,
    ) {}

    /**
     * Returns true when this resolver operates in full-path mode ({path} placeholder).
     */
    private function isFullPathMode(): bool
    {
        return str_contains($this->template, '{path}');
    }

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

        if ($this->isFullPathMode()) {
            return $this->resolveFullPath($value);
        }

        return $this->resolveBasename($row, $value);
    }

    /**
     * Full-path resolution: use the stored value directly as the relative storage path.
     *
     * Anti-traversal guards are applied before building the absolute path.
     */
    private function resolveFullPath(string $value): ?string
    {
        // Anti-traversal sanitization
        if ($value === '' || str_contains($value, '..') || str_starts_with($value, '/')) {
            Log::warning('demo:capture — chemin full-path refusé (traversal ou absolu)', [
                'table' => $this->tableName,
                'column' => $this->column,
                'value' => $value,
            ]);

            return null;
        }

        $relative = str_replace('{path}', $value, $this->template);
        $absolute = base_path($relative);

        if (! file_exists($absolute)) {
            Log::warning('demo:capture — fichier full-path introuvable (skipped)', [
                'table' => $this->tableName,
                'column' => $this->column,
                'path' => $absolute,
            ]);

            return null;
        }

        return $absolute;
    }

    /**
     * Basename resolution: extract the basename of the column value and substitute
     * it along with {association_id} and {id} into the template.
     *
     * @param  array<string, mixed>  $row
     */
    private function resolveBasename(array $row, string $value): ?string
    {
        // Only the basename is meaningful — guard against path traversal in stored values.
        $basename = basename($value);
        if ($basename === '' || $basename === '.') {
            return null;
        }

        // Resolve {association_id}: for the 'association' table itself, use 'id'.
        // For tenant-scoped tables, use 'association_id'.
        // Guard only applies when the template actually needs association_id.
        if (str_contains($this->template, '{association_id}')) {
            $associationId = $this->tableName === 'association'
                ? ($row['id'] ?? null)
                : ($row['association_id'] ?? null);

            if ($associationId === null) {
                Log::warning('demo:capture — association_id manquant', [
                    'table' => $this->tableName,
                    'column' => $this->column,
                ]);

                return null;
            }
        } else {
            $associationId = $this->tableName === 'association'
                ? ($row['id'] ?? '0')
                : ($row['association_id'] ?? '0');
        }

        $rowId = $row['id'] ?? null;

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
     * For full-path mode: strips the 'associations/{id}/' prefix from the stored
     * value to produce a shorter, readable sub-path (e.g.
     * 'notes-de-frais/1/ligne-1.pdf' from 'associations/1/notes-de-frais/1/ligne-1.pdf').
     *
     * For basename mode, behaviour mirrors the existing logic.
     *
     * @param  array<string, mixed>  $row
     */
    public function destSubPath(array $row): string
    {
        $value = $row[$this->column] ?? '';

        if ($this->isFullPathMode()) {
            return $this->destSubPathForFullPath((string) $value);
        }

        return $this->destSubPathForBasename($row, (string) $value);
    }

    /**
     * Full-path dest sub-path: strip the leading 'associations/{id}/' segment.
     *
     * Input : 'associations/1/notes-de-frais/1/ligne-1.pdf'
     * Output: 'notes_de_frais_lignes/notes-de-frais/1/ligne-1.pdf'
     *
     * The table name is prepended so that files from different tables don't collide.
     */
    private function destSubPathForFullPath(string $value): string
    {
        // Strip 'associations/{numeric_id}/' prefix if present
        $stripped = preg_replace('#^associations/\d+/#', '', $value);

        return $this->tableName.'/'.($stripped ?? $value);
    }

    /**
     * Basename dest sub-path (original behaviour).
     *
     * @param  array<string, mixed>  $row
     */
    private function destSubPathForBasename(array $row, string $value): string
    {
        $basename = basename($value);

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

        if ($this->isFullPathMode()) {
            // For full-path mode, just substitute {path} in the template
            return str_replace('{path}', (string) $value, $this->template);
        }

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
