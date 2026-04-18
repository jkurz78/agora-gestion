<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('association')->get()->each(function (stdClass $row): void {
            $updates = [];

            foreach (['logo_path', 'cachet_signature_path'] as $col) {
                if ($row->{$col} === null) {
                    continue;
                }

                $old = $row->{$col};
                $shortName = basename($old);
                $fullNew = storage_path('app/private/associations/'.$row->id.'/branding/'.$shortName);

                // If the old value still carries a path prefix, try to move the physical file.
                if (str_contains($old, '/') || str_contains($old, 'association')) {
                    $fullOld = storage_path('app/public/'.$old);
                    if (is_file($fullOld) && ! is_file($fullNew)) {
                        if (! is_dir(dirname($fullNew))) {
                            @mkdir(dirname($fullNew), 0775, true);
                        }
                        @rename($fullOld, $fullNew);
                    }
                }

                // Only update DB if the file is actually present at the target location —
                // otherwise we'd end up with a DB pointer to a non-existing file.
                if (is_file($fullNew)) {
                    $updates[$col] = $shortName;
                } else {
                    Log::warning('S2 backfill: expected file missing, DB not updated', [
                        'table' => 'association', 'id' => $row->id, 'column' => $col,
                        'old_value' => $old, 'expected_new_path' => $fullNew,
                    ]);
                }
            }

            if ($updates !== []) {
                DB::table('association')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // Non réversible sans backup — restaurer depuis sauvegarde si nécessaire.
    }
};
