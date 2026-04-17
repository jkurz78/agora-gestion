<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('type_operations')->get()->each(function (stdClass $row): void {
            $updates = [];

            foreach (['logo_path', 'attestation_medicale_path'] as $col) {
                if ($row->{$col} === null) {
                    continue;
                }

                $old = $row->{$col};
                $shortName = basename($old);
                $fullNew = storage_path('app/private/associations/'.$row->association_id.'/type-operations/'.$row->id.'/'.$shortName);

                if (str_contains($old, '/') || str_contains($old, 'type-operation')) {
                    $fullOld = storage_path('app/public/'.$old);
                    if (is_file($fullOld) && ! is_file($fullNew)) {
                        if (! is_dir(dirname($fullNew))) {
                            @mkdir(dirname($fullNew), 0775, true);
                        }
                        @rename($fullOld, $fullNew);
                    }
                }

                if (is_file($fullNew)) {
                    $updates[$col] = $shortName;
                } else {
                    Log::warning('S2 backfill: expected file missing, DB not updated', [
                        'table' => 'type_operations', 'id' => $row->id, 'column' => $col,
                        'old_value' => $old, 'expected_new_path' => $fullNew,
                    ]);
                }
            }

            if ($updates !== []) {
                DB::table('type_operations')->where('id', $row->id)->update($updates);
            }
        });
    }

    public function down(): void
    {
        // Non réversible sans backup — restaurer depuis sauvegarde si nécessaire.
    }
};
