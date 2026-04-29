<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Auto-detects Eloquent model columns that use the 'encrypted' cast
 * (or 'encrypted:*' variants) by scanning app/Models/*.php via reflection.
 *
 * Builds a map [table_name => [col1, col2, ...]] used by TableCapture
 * (decrypt at capture) and SnapshotLoader (re-encrypt at reset).
 *
 * The result is cached for the lifetime of the process (static property).
 */
final class EncryptedColumnsRegistry
{
    /** @var array<string, list<string>>|null */
    private static ?array $cache = null;

    /**
     * Return the full map of encrypted columns per table.
     *
     * @return array<string, list<string>>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = self::build();

        return self::$cache;
    }

    /**
     * Return the list of encrypted columns for a given table.
     * Returns an empty array if the table is not in the registry.
     *
     * @return list<string>
     */
    public static function forTable(string $tableName): array
    {
        return self::all()[$tableName] ?? [];
    }

    /**
     * Clear the static cache (useful in tests when APP_KEY changes).
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }

    /**
     * Scan app/Models/*.php (root level only), instantiate each model,
     * call getCasts(), and collect columns with 'encrypted' or 'encrypted:*'.
     *
     * @return array<string, list<string>>
     */
    private static function build(): array
    {
        $modelsPath = app_path('Models');
        $map = [];

        $finder = Finder::create()
            ->files()
            ->in($modelsPath)
            ->depth(0)           // root only — no subdirectories
            ->name('*.php');

        foreach ($finder as $file) {
            $className = 'App\\Models\\'.basename($file->getFilename(), '.php');

            try {
                $reflection = new ReflectionClass($className);

                // Skip abstract classes, interfaces, traits
                if (! $reflection->isInstantiable()) {
                    continue;
                }

                // Must extend Eloquent Model
                if (! $reflection->isSubclassOf(Model::class)) {
                    continue;
                }

                /** @var Model $model */
                $model = $reflection->newInstanceWithoutConstructor();

                $tableName = $model->getTable();

                // We call the protected casts() method directly via reflection rather
                // than getCasts(), because newInstanceWithoutConstructor() skips the
                // Eloquent constructor which initialises internal state needed by
                // getCasts() to merge the static $casts property. The protected
                // casts() method itself requires no state and returns the array directly.
                // Note: setAccessible() is no-op since PHP 8.1, omitted intentionally.
                $castsMethod = $reflection->getMethod('casts');
                $casts = $castsMethod->invoke($model);

                // Also merge any static $casts property (old Laravel style)
                if ($reflection->hasProperty('casts')) {
                    $castsProp = $reflection->getProperty('casts');
                    $staticCasts = $castsProp->getValue($model) ?? [];
                    if (is_array($staticCasts)) {
                        $casts = array_merge($staticCasts, $casts);
                    }
                }

                $encryptedCols = array_values(
                    array_keys(
                        array_filter(
                            $casts,
                            static fn (mixed $castValue): bool => is_string($castValue)
                                && (
                                    $castValue === 'encrypted'
                                    || str_starts_with($castValue, 'encrypted:')
                                ),
                        )
                    )
                );

                if ($encryptedCols !== []) {
                    $map[$tableName] = $encryptedCols;
                }
            } catch (\Throwable $e) {
                Log::warning('EncryptedColumnsRegistry: skipped model', [
                    'class' => $className,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        ksort($map);

        return $map;
    }
}
