<?php

declare(strict_types=1);

namespace App\Support\Demo;

use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Captures all rows from a single DB table, converting datetime columns
 * to relative delta strings and optionally overriding passwords.
 */
final class TableCapture
{
    private Carbon $capturedAt;

    public function __construct(Carbon $capturedAt)
    {
        $this->capturedAt = $capturedAt;
    }

    /**
     * Capture all rows from the given table and return them as an array,
     * with datetime values converted to delta strings.
     *
     * @return list<array<string, mixed>>
     */
    public function capture(string $tableName): array
    {
        $rows = DB::table($tableName)->get();
        $result = [];

        $sensitiveColumns = SnapshotConfig::SENSITIVE_COLUMNS[$tableName] ?? [];
        $encryptedColumns = EncryptedColumnsRegistry::forTable($tableName);

        foreach ($rows as $row) {
            $rowArray = (array) $row;

            // For users table: replace password with demo hash and force role to 'user'
            if ($tableName === 'users') {
                $rowArray['password'] = SnapshotConfig::DEMO_USER_PASSWORD_HASH;
                $rowArray['role_systeme'] = 'user';  // anti-fuite super-admin
            }

            // Decrypt encrypted columns so the plaintext is stored in YAML.
            // The snapshot will be re-encrypted at reset time with the target APP_KEY.
            foreach ($encryptedColumns as $col) {
                if (array_key_exists($col, $rowArray) && $rowArray[$col] !== null) {
                    try {
                        $rowArray[$col] = Crypt::decryptString((string) $rowArray[$col]);
                    } catch (\Throwable $e) {
                        Log::warning('TableCapture: could not decrypt column, storing null', [
                            'table' => $tableName,
                            'column' => $col,
                            'error' => $e->getMessage(),
                        ]);
                        $rowArray[$col] = null;
                    }
                }
            }

            // Scrub sensitive columns (secrets, tokens, API keys).
            // These override any decrypted value above for columns we never want in YAML.
            foreach ($sensitiveColumns as $col) {
                if (array_key_exists($col, $rowArray)) {
                    $rowArray[$col] = null;
                }
            }

            // Sort keys alphabetically for stable YAML
            ksort($rowArray);

            // Convert datetime values to delta strings
            $result[] = $this->convertDatetimeValues($rowArray);
        }

        return $result;
    }

    /**
     * Walk through all values and convert date/datetime/timestamp strings
     * to relative delta strings.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function convertDatetimeValues(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_string($value) && $this->looksLikeDatetime($value)) {
                try {
                    $date = Carbon::parse($value);
                    $row[$key] = DateDelta::toDelta($date, $this->capturedAt);
                } catch (\Throwable) {
                    // If parsing fails, leave the original value
                }
            }
        }

        return $row;
    }

    /**
     * Returns true if the string matches a date or datetime pattern.
     * We match: YYYY-MM-DD or YYYY-MM-DD HH:MM:SS (with optional fractional seconds).
     */
    private function looksLikeDatetime(string $value): bool
    {
        return (bool) preg_match(
            '/^\d{4}-\d{2}-\d{2}(\s\d{2}:\d{2}:\d{2}(\.\d+)?)?$/',
            $value
        );
    }
}
